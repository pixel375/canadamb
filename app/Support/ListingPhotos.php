<?php

namespace App\Support;

use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ListingPhotos
{
    public function store(array $files, string $directory = 'listings', ?string $disk = null): array
    {
        $paths = [];
        try {
            foreach ($files as $index => $file) {
                $id = Str::uuid()->toString();
                $paths[] = [
                    'image_path' => "$directory/$id.webp",
                    'thumbnail_path' => "$directory/$id-preview.webp",
                    'position' => $index,
                ];
                $image = $this->decode($file);
                $this->write($image, $paths[$index]['image_path'], 1600, $disk);
                $this->write($image, $paths[$index]['thumbnail_path'], 480, $disk);
            }
        } catch (Throwable $exception) {
            $this->delete($paths, $disk);
            throw $exception;
        }

        return $paths;
    }

    public function delete(array $paths, ?string $disk = null): void
    {
        foreach ($paths as $photo) {
            Storage::disk($disk ?? config('network.media_disk'))->delete([$photo['image_path'], $photo['thumbnail_path']]);
        }
    }

    private function decode(UploadedFile $file): GdImage
    {
        $path = $file->getRealPath();
        $dimensions = $path ? @getimagesize($path) : false;
        if (! $dimensions || $dimensions[0] * $dimensions[1] > 16_000_000) {
            throw ValidationException::withMessages(['photos' => 'Each photo must be below 16 megapixels.']);
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

        return $image;
    }

    private function write(GdImage $image, string $path, int $limit, ?string $disk): void
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $limit / max($width, $height));
        $canvas = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $width, $height);
        $stream = fopen('php://temp', 'w+b');
        try {
            if (! imagewebp($canvas, $stream, 84) || ftell($stream) === 0) {
                throw new RuntimeException('Could not encode the listing photo.');
            }
            rewind($stream);
            if (! Storage::disk($disk ?? config('network.media_disk'))->put($path, $stream)) {
                throw new RuntimeException('Could not store the listing photo.');
            }
        } finally {
            fclose($stream);
        }
    }
}
