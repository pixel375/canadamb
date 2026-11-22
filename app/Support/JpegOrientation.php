<?php

namespace App\Support;

use GdImage;
use RuntimeException;

final class JpegOrientation
{
    public static function apply(GdImage $image, string $path): GdImage
    {
        $orientation = self::read($path);
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        $rotation = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => 0,
        };
        if ($rotation === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $rotation, 0);
        if ($rotated === false) {
            throw new RuntimeException('Could not orient the listing photo.');
        }

        return $rotated;
    }

    private static function read(string $path): int
    {
        if (function_exists('exif_read_data')) {
            $data = @exif_read_data($path, 'IFD0', true);
            $orientation = $data['IFD0']['Orientation'] ?? null;
            if (is_int($orientation) && $orientation >= 1 && $orientation <= 8) {
                return $orientation;
            }
        }

        // Read only the JPEG header and only the orientation tag from EXIF's first TIFF directory.
        $header = @file_get_contents($path, false, null, 0, 1024 * 1024);
        if ($header === false || ! str_starts_with($header, "\xff\xd8")) {
            return 1;
        }

        $length = strlen($header);
        $offset = 2;
        while ($offset + 3 < $length && $header[$offset] === "\xff") {
            $offset += strspn($header, "\xff", $offset);
            if ($offset >= $length) {
                break;
            }
            $marker = ord($header[$offset++]);
            if (in_array($marker, [0x00, 0xD9, 0xDA], true)) {
                break;
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                continue;
            }
            if ($offset + 2 > $length) {
                break;
            }
            $size = unpack('n', $header, $offset)[1];
            if ($size < 2 || $offset + $size > $length) {
                break;
            }
            if ($marker === 0xE1 && $size >= 8 && substr($header, $offset + 2, 6) === "Exif\0\0") {
                $orientation = self::readTiff(substr($header, $offset + 8, $size - 8));
                if ($orientation !== null) {
                    return $orientation;
                }
            }
            $offset += $size;
        }

        return 1;
    }

    private static function readTiff(string $tiff): ?int
    {
        $length = strlen($tiff);
        $byteOrder = substr($tiff, 0, 2);
        if ($length < 8 || ! in_array($byteOrder, ['II', 'MM'], true)) {
            return null;
        }

        [$short, $long] = $byteOrder === 'II' ? ['v', 'V'] : ['n', 'N'];
        $directory = unpack($long, $tiff, 4)[1];
        if (unpack($short, $tiff, 2)[1] !== 42 || $directory < 8 || $directory > $length - 2) {
            return null;
        }

        $count = unpack($short, $tiff, $directory)[1];
        $entry = $directory + 2;
        if ($count > intdiv($length - $entry, 12)) {
            return null;
        }

        for ($index = 0; $index < $count; $index++, $entry += 12) {
            if (unpack($short, $tiff, $entry)[1] === 0x0112
                && unpack($short, $tiff, $entry + 2)[1] === 3
                && unpack($long, $tiff, $entry + 4)[1] === 1) {
                $orientation = unpack($short, $tiff, $entry + 8)[1];

                return $orientation >= 1 && $orientation <= 8 ? $orientation : null;
            }
        }

        return null;
    }
}
