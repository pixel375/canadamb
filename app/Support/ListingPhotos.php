<?php

namespace App\Support;

use App\Models\ListingMedia;
use GdImage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ListingPhotos
{
    public const DIRECTORY = 'listings';

    public function store(array $files, string $directory = self::DIRECTORY, ?string $disk = null): array
    {
        if ($files === []) {
            return [];
        }

        $storage = Storage::disk($disk ?? config('network.media_disk'));
        $paths = [];
        try {
            foreach ($files as $file) {
                $image = $this->normalize($file);
                $id = Str::uuid()->toString();
                $photo = [
                    'image_path' => "$directory/$id.webp",
                    'thumbnail_path' => "$directory/$id-preview.webp",
                    'position' => count($paths),
                ];
                $paths[] = $photo;
                $this->write($image, $photo['image_path'], $storage);
                $this->write($this->resize($image, 480), $photo['thumbnail_path'], $storage);
            }
        } catch (Throwable $exception) {
            $this->delete($paths, $disk);
            throw $exception;
        }

        return $paths;
    }

    public function delete(array $paths, ?string $disk = null, bool $unreferenced = false): void
    {
        if ($paths === []) {
            return;
        }

        try {
            $storage = Storage::disk($disk ?? config('network.media_disk'));
        } catch (Throwable $exception) {
            rescue(fn () => report($exception), report: false);

            return;
        }

        foreach ($paths as $photo) {
            foreach ([$photo['image_path'], $photo['thumbnail_path']] as $path) {
                try {
                    if ((! $unreferenced || ! ListingMedia::referencesPath($path)) && ! $storage->delete($path)) {
                        throw new RuntimeException('Could not remove listing photo file '.$path.'.');
                    }
                } catch (Throwable $exception) {
                    rescue(fn () => report($exception), report: false);
                }
            }
        }
    }

    private function normalize(UploadedFile $file): GdImage
    {
        $path = $file->getRealPath();
        $dimensions = $path ? @getimagesize($path) : false;
        if (! $dimensions || $dimensions[0] < 1 || $dimensions[1] < 1) {
            throw ValidationException::withMessages(['photos' => 'Upload a readable JPEG, PNG or WebP photo.']);
        }
        if ($dimensions[0] > intdiv(16_000_000, $dimensions[1])) {
            throw ValidationException::withMessages(['photos' => 'Each photo must be 16 megapixels or less.']);
        }
        $image = match ($dimensions[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };
        if (! $image) {
            throw ValidationException::withMessages(['photos' => 'Upload a readable JPEG, PNG or WebP photo.']);
        }

        // Shrink the decoded image first to avoid a second camera-sized buffer during rotation.
        $image = $this->resize($image, 1600);

        return $dimensions[2] === IMAGETYPE_JPEG ? JpegOrientation::apply($image, $path) : $image;
    }

    private function resize(GdImage $image, int $limit): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $truecolor = imageistruecolor($image);
        $hasColorKey = $truecolor && imagecolortransparent($image) !== -1;
        if (max($width, $height) <= $limit && $truecolor && ! $hasColorKey) {
            return $image;
        }

        $scale = min(1, $limit / max($width, $height));
        $canvas = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        if ($hasColorKey) {
            // Some truecolor PNGs mark one RGB value as transparent without an alpha channel.
            // GD's resampled copy ignores that key, but its resized copy skips keyed pixels.
            imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
            imagecopyresized($canvas, $image, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $width, $height);
        } else {
            imagecopyresampled($canvas, $image, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $width, $height);
        }

        return $canvas;
    }

    private function write(GdImage $image, string $path, Filesystem $storage): void
    {
        $stream = fopen('php://temp', 'w+b');
        try {
            if (! imagewebp($image, $stream, 84) || ftell($stream) === 0) {
                throw new RuntimeException('Could not encode the listing photo.');
            }
            rewind($stream);
            if (! $storage->put($path, $stream)) {
                throw new RuntimeException('Could not store the listing photo.');
            }
        } finally {
            fclose($stream);
        }
    }
}
