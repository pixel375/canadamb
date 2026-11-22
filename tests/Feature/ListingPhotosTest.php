<?php

namespace Tests\Feature;

use App\Support\ListingPhotos;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ListingPhotosTest extends TestCase
{
    private const DISK = 'listing_photo_processing_test';

    private const COLORS = [
        'red' => [224, 20, 24],
        'green' => [25, 224, 20],
        'blue' => [24, 20, 224],
        'yellow' => [224, 224, 24],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);
    }

    #[DataProvider('orientations')]
    public function test_jpeg_orientation_is_applied_to_both_webp_sizes(int $orientation, bool $littleEndian, array $corners): void
    {
        $jpeg = $this->jpegWithOrientation($this->encode($this->quadrantImage(), 'jpg'), $orientation, $littleEndian);
        $photo = app(ListingPhotos::class)->store([UploadedFile::fake()->createWithContent('private-camera-name.jpg', $jpeg)], disk: self::DISK)[0];
        $turn = $orientation >= 5;

        $this->assertNormalizedPhoto($photo['image_path'], $turn ? [320, 640] : [640, 320], $corners);
        $this->assertNormalizedPhoto($photo['thumbnail_path'], $turn ? [240, 480] : [480, 240], $corners);
    }

    public static function orientations(): array
    {
        return [
            'upright' => [1, false, ['red', 'green', 'blue', 'yellow']],
            'mirrored horizontally' => [2, true, ['green', 'red', 'yellow', 'blue']],
            'upside down' => [3, false, ['yellow', 'blue', 'green', 'red']],
            'mirrored vertically' => [4, true, ['blue', 'yellow', 'red', 'green']],
            'transposed' => [5, false, ['red', 'blue', 'green', 'yellow']],
            'rotated clockwise' => [6, true, ['blue', 'red', 'yellow', 'green']],
            'transverse' => [7, false, ['yellow', 'green', 'blue', 'red']],
            'rotated counterclockwise' => [8, true, ['green', 'yellow', 'red', 'blue']],
        ];
    }

    public function test_a_jpeg_with_an_invalid_exif_offset_still_decodes(): void
    {
        $jpeg = $this->jpegWithOrientation($this->encode($this->quadrantImage(), 'jpg'), 6, true, 0xFFFFFFFF);
        $photo = app(ListingPhotos::class)->store([UploadedFile::fake()->createWithContent('broken-exif.jpg', $jpeg)], disk: self::DISK)[0];

        $this->assertNormalizedPhoto($photo['image_path'], [640, 320], ['red', 'green', 'blue', 'yellow']);
    }

    #[DataProvider('transparencyFormats')]
    public function test_transparency_and_color_survive_resizing(string $extension): void
    {
        $image = imagecreatetruecolor(1800, 900);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, 599, 899, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 600, 0, 1199, 899, imagecolorallocatealpha($image, 24, 100, 200, 63));
        imagefilledrectangle($image, 1200, 0, 1799, 899, imagecolorallocatealpha($image, 224, 20, 24, 0));
        $file = UploadedFile::fake()->createWithContent("transparent.$extension", $this->encode($image, $extension));
        $photo = app(ListingPhotos::class)->store([$file], disk: self::DISK)[0];

        foreach (['image_path' => [1600, 800], 'thumbnail_path' => [480, 240]] as $key => [$width, $height]) {
            $stored = imagecreatefromstring(Storage::disk(self::DISK)->get($photo[$key]));
            $this->assertSame([$width, $height], [imagesx($stored), imagesy($stored)]);
            $this->assertSame(127, imagecolorsforindex($stored, imagecolorat($stored, 10, 10))['alpha']);
            $center = imagecolorsforindex($stored, imagecolorat($stored, intdiv($width, 2), 10));
            $this->assertEqualsWithDelta(63, $center['alpha'], 1);
            $this->assertEqualsWithDelta(24, $center['red'], 20);
            $this->assertEqualsWithDelta(100, $center['green'], 20);
            $this->assertEqualsWithDelta(200, $center['blue'], 20);
            $this->assertSame(0, imagecolorsforindex($stored, imagecolorat($stored, $width - 10, 10))['alpha']);
        }
    }

    public static function transparencyFormats(): array
    {
        return [['png'], ['webp']];
    }

    public function test_png_transparent_color_key_becomes_webp_alpha(): void
    {
        $image = imagecreatetruecolor(1800, 900);
        $transparent = imagecolorallocate($image, 56, 120, 179);
        imagefill($image, 0, 0, $transparent);
        imagecolortransparent($image, $transparent);
        imagefilledrectangle($image, 900, 0, 1799, 899, imagecolorallocate($image, 224, 20, 24));
        $file = UploadedFile::fake()->createWithContent('color-key.png', $this->encode($image, 'png'));
        $photo = app(ListingPhotos::class)->store([$file], disk: self::DISK)[0];

        foreach (['image_path', 'thumbnail_path'] as $key) {
            $stored = imagecreatefromstring(Storage::disk(self::DISK)->get($photo[$key]));
            $this->assertSame(127, imagecolorsforindex($stored, imagecolorat($stored, 10, 10))['alpha']);
            $this->assertSame(0, imagecolorsforindex($stored, imagecolorat($stored, imagesx($stored) - 10, 10))['alpha']);
        }
    }

    public function test_a_corrupt_photo_cleans_up_earlier_files_in_the_batch(): void
    {
        $disk = Storage::disk(self::DISK);
        $disk->put('listings/keep.webp', 'existing data');

        try {
            app(ListingPhotos::class)->store([
                UploadedFile::fake()->image('valid.jpg'),
                UploadedFile::fake()->createWithContent('broken.jpg', "\xff\xd8\xff\xd9"),
            ], disk: self::DISK);
            $this->fail('A corrupt JPEG was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('readable', $exception->errors()['photos'][0]);
        }

        $this->assertSame(['listings/keep.webp'], $disk->allFiles());
    }

    public function test_the_megapixel_guard_checks_dimensions_before_decoding(): void
    {
        $header = 'IHDR'.pack('NNCCCCC', 5000, 4000, 8, 6, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).$header.pack('N', crc32($header));

        try {
            app(ListingPhotos::class)->store([UploadedFile::fake()->createWithContent('huge.png', $png)], disk: self::DISK);
            $this->fail('A photo exceeding the pixel limit was accepted.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('16 megapixels', $exception->errors()['photos'][0]);
        }

        $this->assertSame([], Storage::disk(self::DISK)->allFiles());
    }

    public function test_post_commit_cleanup_reports_an_unavailable_disk_without_throwing(): void
    {
        Exceptions::fake();
        app(ListingPhotos::class)->delete([
            ['image_path' => 'listings/full.webp', 'thumbnail_path' => 'listings/preview.webp'],
        ], 'missing_listing_photo_disk');

        Exceptions::assertReported(InvalidArgumentException::class);
    }

    private function quadrantImage(): GdImage
    {
        $image = imagecreatetruecolor(640, 320);
        foreach (array_values(self::COLORS) as $index => [$red, $green, $blue]) {
            $x = ($index % 2) * 320;
            $y = intdiv($index, 2) * 160;
            imagefilledrectangle($image, $x, $y, $x + 319, $y + 159, imagecolorallocate($image, $red, $green, $blue));
        }

        return $image;
    }

    private function encode(GdImage $image, string $extension): string
    {
        ob_start();
        match ($extension) {
            'jpg' => imagejpeg($image, null, 95),
            'png' => imagepng($image),
            'webp' => imagewebp($image, null, 95),
        };

        return ob_get_clean();
    }

    private function jpegWithOrientation(string $jpeg, int $orientation, bool $littleEndian, int $ifdOffset = 8): string
    {
        $tiff = $littleEndian
            ? 'II'.pack('vVvvvVvvV', 42, $ifdOffset, 1, 0x0112, 3, 1, $orientation, 0, 0)
            : 'MM'.pack('nNnnnNnnN', 42, $ifdOffset, 1, 0x0112, 3, 1, $orientation, 0, 0);
        $exif = "Exif\0\0".$tiff;

        return substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);
    }

    private function assertNormalizedPhoto(string $path, array $dimensions, array $corners): void
    {
        $bytes = Storage::disk(self::DISK)->get($path);
        $this->assertSame([...$dimensions, IMAGETYPE_WEBP], array_slice(getimagesizefromstring($bytes), 0, 3));
        $this->assertStringNotContainsString('Exif', $bytes);
        $image = imagecreatefromstring($bytes);
        $pixelCoordinates = [[10, 10], [$dimensions[0] - 10, 10], [10, $dimensions[1] - 10], [$dimensions[0] - 10, $dimensions[1] - 10]];
        foreach ($pixelCoordinates as $index => [$x, $y]) {
            $pixel = imagecolorsforindex($image, imagecolorat($image, $x, $y));
            $channels = ['red', 'green', 'blue'];
            foreach (self::COLORS[$corners[$index]] as $channel => $expected) {
                $this->assertEqualsWithDelta($expected, $pixel[$channels[$channel]], 20);
            }
        }
    }
}
