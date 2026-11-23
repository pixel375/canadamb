<?php

namespace Tests\Unit;

use App\Support\BusinessImports\BusinessCsv;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BusinessCsvTest extends TestCase
{
    public function test_csv_stream_normalizes_data_and_supports_legacy_and_grouped_rows(): void
    {
        foreach (['', ',business_key'] as $extension) {
            $input = "\xef\xbb\xbfexternal_key,name,category,location,address,website$extension\r\n"
                ." Branch-1 ,\" Cafe\u{0301} \",services,BRANDON,\" 2 Main Street, Unit 1 \",https://example.test".($extension ? ', Group-1' : '')."\r\n";
            [$hash, $rows] = $this->read($input);
            $this->assertSame(hash('sha256', $input), $hash);
            $this->assertSame('Café', $rows[2]['data']['name']);
            $this->assertSame('branch-1', $rows[2]['data']['external_key']);
            $this->assertSame('brandon', $rows[2]['data']['location']);
            $this->assertSame('2 Main Street, Unit 1', $rows[2]['data']['address']);
            $this->assertSame($extension ? 'group-1' : null, $rows[2]['data']['business_key']);
        }
    }

    public function test_csv_reports_invalid_records_without_coercing_them(): void
    {
        [, $rows] = $this->read("external_key,name,category,location,address,website\n"
            ."x,\"unterminated,services,brandon,Address,\n"
            ."x,\"Incomplete\"adjacent,services,brandon,Address,\n"
            ."x,Name\xff,services,brandon,Address,\n"
            ."../x,Name,unknown,brandon,Address,javascript:example\n"
            ."x,Name,services,brandon,Address,https://user:secret@example.test\n"
            ."x,Name,services,brandon,Address,https://example.test\\bad\n"
            ."x,\"An \"\"excellent\"\" name\",services,brandon,Address,\n");
        foreach (range(2, 7) as $line) {
            $this->assertNotEmpty($rows[$line]['error']);
            $this->assertArrayNotHasKey('data', $rows[$line]);
        }
        $this->assertSame('An "excellent" name', $rows[8]['data']['name']);
    }

    public function test_csv_rejects_binary_headers_and_overlong_lines(): void
    {
        foreach (["PK\x03\x04fake spreadsheet", "external_key,name,category,location,address,website\n".str_repeat('x', 4097)] as $invalid) {
            try {
                $this->read($invalid);
                $this->fail('Unsafe file was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertNotEmpty($exception->getMessage());
            }
        }
    }

    private function read(string $body): array
    {
        $file = fopen('php://temp', 'w+b');
        try {
            fwrite($file, $body);
            rewind($file);
            $rows = [];
            $hash = (new BusinessCsv)->read($file, function (int $line, array $record) use (&$rows): void {
                $rows[$line] = $record;
            });

            return [$hash, $rows];
        } finally {
            fclose($file);
        }
    }
}
