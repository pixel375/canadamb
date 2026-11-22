<?php

namespace App\Support;

use Closure;
use finfo;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Throwable;

class MessageAttachments
{
    public const DIRECTORY = 'message-attachments';

    private const MAX_FILE_BYTES = 5 * 1024 * 1024;

    private const MAX_TOTAL_BYTES = 12 * 1024 * 1024;

    private const CONTENT_TYPES = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
    ];

    public function __construct(private ListingPhotos $photos) {}

    /** The callback owns the database transaction and can retry without rewriting any file. */
    public function withStored(array $uploads, Closure $send): mixed
    {
        $staged = $this->store($uploads);
        try {
            return $send($staged);
        } finally {
            $this->discard($staged);
        }
    }

    public function store(array $uploads): array
    {
        if ($uploads === []) {
            return [];
        }

        $this->checkUploadSizes($uploads);
        $disk = self::disk();
        $fileInfo = new finfo(FILEINFO_MIME_TYPE);
        $streams = [];
        $rows = [];
        $written = [];
        $storedBytes = 0;

        try {
            foreach ($uploads as $upload) {
                $path = $upload->getRealPath();
                $extension = strtolower($upload->getClientOriginalExtension());
                $mime = $fileInfo->file($path);
                if (! isset(self::CONTENT_TYPES[$extension]) || self::CONTENT_TYPES[$extension] !== $mime) {
                    $this->invalid('Upload a JPEG, PNG, WebP or static PDF with a matching file extension.');
                }

                $isPdf = $mime === 'application/pdf';
                $stream = $isPdf ? $this->pdfStream($path) : $this->imageStream($path, $upload);
                $streams[] = $stream;
                $size = fstat($stream)['size'];
                $storedBytes += $size;
                if ($size < 1 || $size > self::MAX_FILE_BYTES || $storedBytes > self::MAX_TOTAL_BYTES) {
                    $this->invalid('The finished attachments must fit within 5 MiB per file and 12 MiB per message.');
                }

                $storedExtension = $isPdf ? 'pdf' : 'webp';
                $rows[] = [
                    'path' => self::DIRECTORY.'/'.Str::uuid().'.'.$storedExtension,
                    'original_name' => $this->filename($upload->getClientOriginalName(), $storedExtension),
                    'mime_type' => $isPdf ? 'application/pdf' : 'image/webp',
                    'size_bytes' => $size,
                ];
            }

            foreach ($rows as $index => $row) {
                $written[] = $row['path'];
                if (! $disk->put($row['path'], $streams[$index], 'private')) {
                    throw new RuntimeException('Could not store the private message attachment.');
                }
            }
        } catch (Throwable $exception) {
            $this->deletePaths($disk, $written);
            throw $exception;
        } finally {
            foreach ($streams as $stream) {
                fclose($stream);
            }
        }

        return $rows;
    }

    public function discard(array $staged): void
    {
        $paths = array_filter(array_column($staged, 'path'), self::acceptsPath(...));
        if ($paths === []) {
            return;
        }

        try {
            // Query the writer even after a commit so cleanup never relies on a lagging replica.
            $referenced = DB::table('message_attachments')->useWritePdo()->whereIn('path', $paths)->pluck('path')->all();
            $unreferenced = array_diff($paths, $referenced);
            if ($unreferenced !== []) {
                $this->deletePaths(self::disk(), $unreferenced);
            }
        } catch (Throwable $exception) {
            rescue(fn () => report($exception), report: false);
        }
    }

    public static function acceptsPath(string $path, ?string $mime = null): bool
    {
        if (! preg_match('#^'.self::DIRECTORY.'/[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\.(pdf|webp)$#D', $path, $match)) {
            return false;
        }

        return $mime === null || self::CONTENT_TYPES[$match[1]] === $mime;
    }

    public static function disk(): FilesystemAdapter
    {
        $name = config('network.media_disk');
        $disk = Storage::disk($name);
        if ($name === 'public' || ($disk->getConfig()['visibility'] ?? null) === 'public') {
            throw new RuntimeException('Message attachments require a private network media disk.');
        }

        if ($disk->getAdapter() instanceof LocalFilesystemAdapter) {
            $directory = $disk->path(self::DIRECTORY);
            $root = realpath(dirname($directory));
            $webroot = realpath(public_path());
            if (is_link($directory) || ($root !== false && $webroot !== false && ($root === $webroot || str_starts_with($root, $webroot.DIRECTORY_SEPARATOR)))) {
                throw new RuntimeException('Message attachments require storage outside the web root.');
            }
        }

        return $disk;
    }

    private function checkUploadSizes(array $uploads): void
    {
        if (count($uploads) > 3) {
            $this->invalid('Attach up to three files per message.');
        }

        $total = 0;
        foreach ($uploads as $upload) {
            $path = $upload instanceof UploadedFile && $upload->isValid() ? $upload->getRealPath() : false;
            $size = $path === false ? false : filesize($path);
            if ($size === false || $size < 1 || $size > self::MAX_FILE_BYTES) {
                $this->invalid('Each attachment must be a readable file no larger than 5 MiB.');
            }
            $total += $size;
        }

        if ($total > self::MAX_TOTAL_BYTES) {
            $this->invalid('Attachments may total up to 12 MiB per message.');
        }
    }

    /** @return resource */
    private function imageStream(string $path, UploadedFile $upload)
    {
        $info = @getimagesize($path);
        if ($info === false || min($info[0], $info[1]) < 1 || max($info[0], $info[1]) > 8192 || $info[0] * $info[1] > 16_000_000) {
            $this->invalid('Use a readable photo up to 16 megapixels and 8,192 pixels per side.');
        }

        $memoryLimit = ini_parse_quantity(ini_get('memory_limit'));
        $required = memory_get_usage(true) + $info[0] * $info[1] * 5 + 24 * 1024 * 1024;
        if ($memoryLimit > 0 && $required > $memoryLimit) {
            $this->invalid('This photo is too large to process. Resize it and try again.');
        }

        try {
            return $this->photos->normalizedWebpStream($upload);
        } catch (ValidationException $exception) {
            $this->invalid($exception->errors()['photos'][0]);
        }
    }

    /** @return resource */
    private function pdfStream(string $path)
    {
        $stream = @fopen($path, 'rb');
        if ($stream === false) {
            $this->invalid('Upload a readable static PDF.');
        }

        try {
            $bytes = stream_get_contents($stream, self::MAX_FILE_BYTES + 1);
            if ($bytes === false || strlen($bytes) > self::MAX_FILE_BYTES || ! StaticMessagePdf::accepts($bytes) || ! rewind($stream)) {
                $this->invalid('Upload a plain PDF without forms, active content or unsupported structures.');
            }

            return $stream;
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
    }

    private function filename(string $original, string $extension): string
    {
        $name = Str::ascii(pathinfo($original, PATHINFO_FILENAME));
        $name = trim(substr(preg_replace('/[^0-9A-Za-z_-]+/', '-', $name), 0, 80), '-_');

        return ($name === '' ? 'attachment' : $name).'.'.$extension;
    }

    private function deletePaths(FilesystemAdapter $disk, array $paths): void
    {
        foreach ($paths as $path) {
            try {
                if (! $disk->delete($path)) {
                    throw new RuntimeException('Could not remove a staged message attachment.');
                }
            } catch (Throwable $exception) {
                rescue(fn () => report($exception), report: false);
            }
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['attachments' => $message]);
    }
}
