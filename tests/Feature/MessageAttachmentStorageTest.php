<?php

namespace Tests\Feature;

use App\Models\Listing;
use App\Models\Location;
use App\Models\User;
use App\Support\MessageAttachments;
use App\Support\StaticMessagePdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MessageAttachmentStorageTest extends TestCase
{
    use RefreshDatabase;

    private const DISK = 'test_message_attachments';

    protected $seeder = DatabaseSeeder::class;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(self::DISK);
        config(['network.media_disk' => self::DISK]);
    }

    public function test_images_are_reencoded_as_private_bounded_webp_with_exif_orientation(): void
    {
        $image = imagecreatetruecolor(1800, 900);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        $exif = "Exif\0\0II".pack('vVvvvVvvV', 42, 8, 1, 0x0112, 3, 1, 6, 0, 0);
        $oriented = substr($jpeg, 0, 2)."\xff\xe1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);

        $documents = app(MessageAttachments::class)->store([
            UploadedFile::fake()->createWithContent("private\\resume\r\n%<card>.JPG", $oriented),
            UploadedFile::fake()->image('card.png'),
            UploadedFile::fake()->image('card.webp'),
        ]);

        $disk = Storage::disk(self::DISK);
        $this->assertSame('resume-card.webp', $documents[0]['original_name']);
        $this->assertSame('image/webp', $documents[0]['mime_type']);
        $this->assertCount(3, array_unique(array_column($documents, 'path')));
        $this->assertCount(3, $disk->allFiles());
        foreach ($documents as $index => $document) {
            $bytes = $disk->get($document['path']);
            $this->assertMatchesRegularExpression('#^message-attachments/[a-f0-9-]{36}\.webp$#D', $document['path']);
            if (DIRECTORY_SEPARATOR === '/') {
                $this->assertSame('private', $disk->getVisibility($document['path']));
            }
            $this->assertSame(strlen($bytes), $document['size_bytes']);
            $this->assertSame($index === 0 ? [800, 1600, IMAGETYPE_WEBP] : [10, 10, IMAGETYPE_WEBP], array_slice(getimagesizefromstring($bytes), 0, 3));
            $this->assertStringNotContainsString('Exif', $bytes);
        }
    }

    public function test_static_documents_preserve_pdf_bytes_and_allow_plain_text_mentioning_pdf_keywords(): void
    {
        $pdf = self::pdf(stream: gzcompress('BT (/JavaScript and /Encrypt in printed text) Tj ET'), streamDictionary: '/Filter [/FlateDecode]', beforeEndStream: '');
        [$attachment] = app(MessageAttachments::class)->store([
            UploadedFile::fake()->createWithContent('project notes.PDF', $pdf),
        ]);

        $this->assertSame('project-notes.pdf', $attachment['original_name']);
        $this->assertSame('application/pdf', $attachment['mime_type']);
        $this->assertSame(strlen($pdf), $attachment['size_bytes']);
        $this->assertSame($pdf, Storage::disk(self::DISK)->get($attachment['path']));
    }

    public function test_static_pdf_parser_rejects_every_incomplete_prefix_of_a_valid_document(): void
    {
        $pdf = rtrim(self::pdf(), "\n");
        $this->assertTrue(StaticMessagePdf::accepts($pdf));

        for ($length = 0; $length < strlen($pdf); $length++) {
            $this->assertFalse(StaticMessagePdf::accepts(substr($pdf, 0, $length)), "Accepted a truncated PDF at byte $length.");
        }
    }

    public function test_static_documents_share_a_decoded_byte_budget_across_streams(): void
    {
        $compressed = gzcompress(str_repeat(' ', 17 * 1024 * 1024));
        $additionalStream = '<< /Length '.strlen($compressed)." /Filter /FlateDecode >>\nstream\n".$compressed."\nendstream";
        $overBudget = self::pdf(stream: $compressed, streamDictionary: '/Filter /FlateDecode', extraObject: $additionalStream);

        $this->assertTrue(StaticMessagePdf::accepts(self::pdf(stream: $compressed, streamDictionary: '/Filter /FlateDecode')));
        $this->assertTrue(StaticMessagePdf::accepts(self::pdf(extraObject: $additionalStream)));
        $this->assertRejectedWithoutFiles([UploadedFile::fake()->createWithContent('document.pdf', $overBudget)]);
    }

    #[DataProvider('unsupportedPdfs')]
    public function test_documents_with_unsafe_or_unverifiable_structures_never_reach_storage(string $pdf): void
    {
        $this->assertRejectedWithoutFiles([UploadedFile::fake()->createWithContent('document.pdf', $pdf)]);
    }

    public static function unsupportedPdfs(): array
    {
        $pdf = self::pdf();

        return [
            'PDF-looking text' => ["%PDF-1.4\nnot a document\n%%EOF"],
            'inline bitmap outside an image stream dictionary' => [self::pdf(stream: 'q BI /W 1 /H 1 /BPC 1 /CS /G ID 0 EI Q')],
            'trailing payload' => [$pdf."\n<html>extra</html>"],
            'bad cross-reference offset' => [preg_replace('/startxref\n\d+/', "startxref\n10", $pdf)],
            'wrong object offset in cross-reference table' => [preg_replace('/^\d{10}( 00000 n )$/m', '0000000001$1', $pdf, 1)],
            'corrupt page tree' => [self::pdf(pageTree: '<< /Type /Pages /Kids [3 0 R] /Count 9 >>')],
            'object nesting limit' => [self::pdf(extraObject: str_repeat('[', 25).'null'.str_repeat(']', 25))],
            'value count limit' => [self::pdf(extraObject: '['.str_repeat('null ', 100_000).']')],
            'image dimension limit' => [self::pdf(extraObject: "<< /Type /XObject /Subtype /Image /Width 8193 /Height 1 /ColorSpace /DeviceGray /BitsPerComponent 8 /Length 8193 >>\nstream\n".str_repeat(' ', 8193)."\nendstream")],
            'escaped action name' => [self::pdf(catalog: '/Open#41ction 6 0 R')],
            'JavaScript dictionary' => [self::pdf(extraObject: '<< /S /Java#53cript /JS () >>')],
            'launch action' => [self::pdf(extraObject: '<< /S /Launch >>')],
            'embedded file specification' => [self::pdf(extraObject: '<< /Type /Filespec /EF << >> >>')],
            'annotations' => [self::pdf(catalog: '/Annots []')],
            'presentation actions' => [self::pdf(catalog: '/PresSteps 6 0 R', extraObject: '<< /NA << /S /Named >> >>')],
            'active form' => [self::pdf(catalog: '/AcroForm << >>')],
            'encrypted' => [self::pdf(trailer: '/Encrypt 6 0 R')],
            'incremental revision' => [self::pdf(trailer: '/Prev 24')],
            'object stream' => [self::pdf(extraObject: '<< /Type /ObjStm >>')],
            'hybrid xref stream' => [self::pdf(trailer: '/XRefStm 45')],
            'unverifiable stream length' => [self::pdf(streamDictionary: '/Length 2')],
            'external stream data' => [self::pdf(streamDictionary: '/F (elsewhere)')],
            'unsupported stream filter' => [self::pdf(streamDictionary: '/Filter /LZWDecode')],
            'invalid stream filter value' => [self::pdf(streamDictionary: '/Filter (FlateDecode)')],
            'truncated compressed stream' => [self::pdf(stream: substr(gzcompress('q Q'), 0, -1), streamDictionary: '/Filter /FlateDecode')],
            'oversized inflated stream' => [self::pdf(stream: gzcompress(str_repeat(' ', 32 * 1024 * 1024 + 1)), streamDictionary: '/Filter /FlateDecode')],
        ];
    }

    public function test_disguised_corrupted_and_oversized_images_are_rejected_before_writing_the_batch(): void
    {
        $header = 'IHDR'.pack('NNCCCCC', 5000, 4000, 8, 6, 0, 0, 0);
        $png = "\x89PNG\r\n\x1a\n".pack('N', 13).$header.pack('N', crc32($header));
        $smallPng = UploadedFile::fake()->image('actual.png');

        foreach ([
            UploadedFile::fake()->createWithContent('wrong.png', self::pdf())->mimeType('image/png'),
            UploadedFile::fake()->createWithContent('actual.pdf', file_get_contents($smallPng->getRealPath()))->mimeType('application/pdf'),
            UploadedFile::fake()->createWithContent('broken.jpg', "\xff\xd8\xff\xd9"),
            UploadedFile::fake()->createWithContent('huge.png', $png),
        ] as $invalid) {
            $this->assertRejectedWithoutFiles([UploadedFile::fake()->image('valid.jpg'), $invalid]);
        }
    }

    public function test_batch_size_and_count_limits_are_checked_before_writing(): void
    {
        $file = UploadedFile::fake()->image('small.jpg');
        $this->assertRejectedWithoutFiles([$file, $file, $file, $file]);
        $this->assertRejectedWithoutFiles([
            UploadedFile::fake()->createWithContent('large.pdf', str_repeat(' ', 5 * 1024 * 1024 + 1)),
        ]);
        $fourMiB = UploadedFile::fake()->createWithContent('batch.pdf', str_repeat(' ', 4 * 1024 * 1024 + 1));
        $this->assertRejectedWithoutFiles([$fourMiB, $fourMiB, $fourMiB]);
    }

    public function test_attachment_paths_cannot_resolve_to_other_media_or_mismatched_types(): void
    {
        $uuid = Str::uuid();
        $this->assertTrue(MessageAttachments::acceptsPath("message-attachments/$uuid.pdf", 'application/pdf'));
        $this->assertFalse(MessageAttachments::acceptsPath("message-attachments/$uuid.pdf", 'image/webp'));
        $this->assertFalse(MessageAttachments::acceptsPath("message-attachments/../listings/$uuid.webp"));
        $this->assertFalse(MessageAttachments::acceptsPath("listings/$uuid.webp"));
    }

    public function test_missing_committed_metadata_removes_staged_bytes_after_rollback_and_retry_replay(): void
    {
        $service = app(MessageAttachments::class);
        $disk = Storage::disk(self::DISK);
        $files = [UploadedFile::fake()->image('card.jpg')];
        $message = $this->newMessage();
        try {
            $service->withStored($files, function (array $staged) use ($disk, $message): void {
                $disk->assertExists($staged[0]['path']);
                DB::transaction(function () use ($staged, $message): void {
                    DB::table('message_attachments')->insert([...$staged[0], 'message_id' => $message]);
                    throw new RuntimeException('Simulated send failure');
                });
            });
            $this->fail('The send should have failed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated send failure', $exception->getMessage());
        }

        $this->assertDatabaseCount('message_attachments', 0);
        $this->assertSame([], $disk->allFiles());

        $result = $service->withStored($files, fn () => 'previous result');
        $this->assertSame('previous result', $result);
        $this->assertSame([], $disk->allFiles());
    }

    public function test_orphan_reporting_is_dry_run_rate_limited_and_skips_referenced_recent_and_unrelated_bytes(): void
    {
        $service = app(MessageAttachments::class);
        $disk = Storage::disk(self::DISK);
        $message = $this->newMessage();
        $referenced = $service->withStored([UploadedFile::fake()->image('card.jpg')], function (array $staged) use ($message) {
            DB::transaction(fn () => DB::table('message_attachments')->insert([...$staged[0], 'message_id' => $message]));

            return $staged[0]['path'];
        });
        $orphan = 'message-attachments/'.Str::uuid().'.pdf';
        $fresh = 'message-attachments/'.Str::uuid().'.webp';
        $unrelated = 'listings/'.Str::uuid().'.webp';
        foreach ([$orphan, $fresh, $unrelated] as $path) {
            $disk->put($path, 'fixture');
        }
        foreach ([$referenced, $orphan, $unrelated] as $old) {
            $this->assertTrue(touch($disk->path($old), now()->subDays(2)->timestamp));
        }

        $this->artisan('messages:attachment-orphans')
            ->expectsOutput($orphan)
            ->doesntExpectOutput($referenced)
            ->doesntExpectOutput($fresh)
            ->doesntExpectOutput($unrelated)
            ->assertSuccessful();
        $this->artisan('messages:attachment-orphans')->assertFailed();
        $disk->assertExists([$referenced, $orphan, $fresh, $unrelated]);
    }

    private function assertRejectedWithoutFiles(array $files): void
    {
        try {
            app(MessageAttachments::class)->store($files);
            $this->fail('An unsupported upload was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('attachments', $exception->errors());
        }

        $this->assertSame([], Storage::disk(self::DISK)->allFiles());
    }

    private function newMessage(): int
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();
        $listing = Listing::create([
            'user_id' => $seller->id, 'location_id' => Location::where('slug', 'brandon')->sole()->id,
            'category' => 'other', 'title' => 'Fixture listing', 'description' => 'Fixture description.',
            'condition' => 'good', 'price_cents' => 0, 'status' => 'active',
        ]);
        $conversation = DB::table('conversations')->insertGetId([
            'listing_id' => $listing->id, 'buyer_id' => $buyer->id, 'seller_id' => $seller->id,
        ]);

        return DB::table('messages')->insertGetId([
            'conversation_id' => $conversation, 'sender_id' => $buyer->id, 'body' => 'Fixture message.',
        ]);
    }

    private static function pdf(string $catalog = '', string $stream = 'q Q', string $streamDictionary = '', string $trailer = '', string $extraObject = 'null', string $beforeEndStream = "\n", string $pageTree = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>'): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R '.$catalog.' >>',
            $pageTree,
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Contents 4 0 R >>',
            '<< /Length 5 0 R '.$streamDictionary." >>\nstream\n".$stream.$beforeEndStream.'endstream',
            (string) strlen($stream),
            $extraObject,
        ];
        $pdf = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        $xref = "xref\n0 7\n0000000000 65535 f \n";
        foreach ($objects as $index => $object) {
            $xref .= sprintf("%010d 00000 n \n", strlen($pdf));
            $pdf .= ($index + 1)." 0 obj\n$object\nendobj\n";
        }

        return $pdf.$xref."trailer\n<< /Root 1 0 R /Size 7 $trailer >>\nstartxref\n".strlen($pdf)."\n%%EOF\n";
    }
}
