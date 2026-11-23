<?php

namespace App\Support\BusinessImports;

use App\Support\BusinessDirectory;
use RuntimeException;

final class BusinessCsv
{
    private const HEADER = ['external_key', 'name', 'category', 'location', 'address', 'website'];

    private const MAX_LINE = 4096;

    private const MAX_BYTES = 16_000_000;

    private const MAX_ROWS = 20_000;

    /** Read and hash the very same bytes; staging persists only parsed rows. */
    public function read($stream, callable $stage): string
    {
        $digest = hash_init('sha256');
        $bytes = 0;
        $line = 0;
        $records = 0;
        $header = null;
        while (($raw = fgets($stream, self::MAX_LINE + 2)) !== false) {
            $bytes += strlen($raw);
            if (strlen($raw) > self::MAX_LINE || $bytes > self::MAX_BYTES) {
                throw new RuntimeException('CSV exceeds the 4,096-byte line or 16 MB file limit.');
            }
            hash_update($digest, $raw);
            $line++;
            $record = rtrim($raw, "\r\n");
            if ($line === 1) {
                $record = str_starts_with($record, "\xef\xbb\xbf") ? substr($record, 3) : $record;
                $header = $this->fields($record);
                if ($header !== self::HEADER && $header !== [...self::HEADER, 'business_key']) {
                    throw new RuntimeException('Use the six documented CSV headers with optional business_key last.');
                }

                continue;
            }
            if ($record === '') {
                continue;
            }
            if (++$records > self::MAX_ROWS) {
                throw new RuntimeException('CSV contains more than 20,000 records.');
            }

            $fields = $this->fields($record);
            $stage($line, $fields === null || count($fields) !== count($header)
                ? ['error' => 'Invalid UTF-8, quoting, control characters or column count. Use one record per line.']
                : $this->normalized(array_combine($header, $fields)));
        }
        if (! feof($stream) || $records === 0) {
            throw new RuntimeException('CSV could not be read or contains no records.');
        }

        return hash_final($digest);
    }

    public static function branchKey(string $externalKey): string
    {
        return hash('sha256', strtolower(BusinessDirectory::normalizedText($externalKey)));
    }

    public static function metadataText(string $value, int $limit): string
    {
        if (! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}]/u', $value) || mb_strlen($value) > $limit) {
            throw new RuntimeException('Registry text contains invalid characters or exceeds its length limit.');
        }

        return BusinessDirectory::normalizedText($value);
    }

    public static function webUrl(string $value, bool $secure = false): bool
    {
        if (str_contains($value, '\\') || filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $parts = parse_url($value);

        return ! empty($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass'])
            && in_array(strtolower($parts['scheme'] ?? ''), $secure ? ['https'] : ['http', 'https'], true);
    }

    private function fields(string $line): ?array
    {
        // Strict quoting before str_getcsv, whose parser also accepts incomplete quotes.
        if (! mb_check_encoding($line, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}]/u', $line)
            || ! preg_match('/\A(?:"(?:[^"]++|"")*+"|[^",]*)(?:,(?:"(?:[^"]++|"")*+"|[^",]*))*\z/u', $line)) {
            return null;
        }

        return str_getcsv($line, ',', '"', '');
    }

    private function normalized(array $fields): array
    {
        $fields = array_map(BusinessDirectory::normalizedText(...), $fields);
        $fields['business_key'] = $fields['business_key'] ?? '';
        foreach (['external_key', 'business_key', 'location'] as $key) {
            $fields[$key] = strtolower($fields[$key]);
            if (($key !== 'business_key' || $fields[$key] !== '')
                && ! preg_match('/\A[a-z0-9][a-z0-9._:-]{0,99}\z/', $fields[$key])) {
                return ['error' => "Use a valid $key of up to 100 ASCII key characters."];
            }
        }
        foreach (['name' => 140, 'address' => 255] as $key => $limit) {
            if ($fields[$key] === '' || mb_strlen($fields[$key]) > $limit) {
                return ['error' => "Enter $key using 1–$limit characters."];
            }
        }
        if (! array_key_exists($fields['category'], BusinessDirectory::CATEGORIES)) {
            return ['error' => 'Use a documented business category key.'];
        }
        if ($fields['website'] !== '' && (strlen($fields['website']) > 255 || ! self::webUrl($fields['website']))) {
            return ['error' => 'Enter an HTTP(S) website without embedded credentials.'];
        }
        $fields['business_key'] = $fields['business_key'] === '' ? null : $fields['business_key'];
        $fields['website'] = $fields['website'] ?: null;

        return ['data' => $fields];
    }
}
