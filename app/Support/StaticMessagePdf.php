<?php

namespace App\Support;

use UnexpectedValueException;

/** Checks a bounded PDF 1.x subset with one classic cross-reference table. */
final class StaticMessagePdf
{
    private const DELIMITER = '(?=[\x09\x0a\x0c\x0d\x20()<>\[\]/%]|\z)';

    private const MAX_DECODED_BYTES = 32 * 1024 * 1024;

    private const FORBIDDEN_NAMES = [
        // Actions, annotations, forms, scripts, multimedia and external resources.
        '/a', '/aa', '/openaction', '/action', '/javascript', '/js', '/launch', '/uri', '/xfa', '/acroform',
        '/submitform', '/importdata', '/gotor', '/gotoe', '/rendition', '/richmedia', '/movie', '/sound',
        '/3d', '/annots', '/annot', '/filespec', '/embeddedfile', '/embeddedfiles', '/ef', '/af',
        '/fileattachment', '/rf', '/ref', '/opi', '/postscript', '/ps', '/collection', '/extensions',
        '/pressteps', '/navnode', '/na', '/pa', '/named', '/goto', '/goto3dview', '/hide', '/resetform',
        '/setocgstate', '/thread',
        // Structures in which a reader can find objects outside the classic cross-reference table.
        '/encrypt', '/crypt', '/objstm', '/xref', '/xrefstm', '/prev', '/linearized',
    ];

    private int $offset = 0;

    private int $tokens = 0;

    private int $decodedBytes = 0;

    private int $imagePixels = 0;

    private array $xref = [];

    private function __construct(private string $pdf) {}

    public static function accepts(string $pdf): bool
    {
        try {
            (new self($pdf))->validate();

            return true;
        } catch (UnexpectedValueException) {
            return false;
        }
    }

    private function validate(): void
    {
        if (! $this->take('%PDF-1\.[0-7](?:\r\n|[\r\n])')
            || preg_match('~[\r\n]startxref[ \t]*[\r\n]+([1-9][0-9]{0,6})[ \t]*[\r\n]+%%EOF[ \t\r\n]*\z~', substr($this->pdf, -128), $end) !== 1) {
            $this->reject();
        }

        $bodyStart = $this->offset;
        $xrefOffset = (int) $end[1];
        if ($xrefOffset <= $bodyStart || $xrefOffset >= strlen($this->pdf)) {
            $this->reject();
        }

        $this->offset = $xrefOffset;
        $trailer = $this->readTrailer();
        $root = $trailer['/Root']['id'] ?? 0;
        $this->offset = $bodyStart;
        $pageTree = 0;
        $pages = [];

        // Every non-comment byte before xref must belong to an object with an exact xref offset.
        foreach ($this->xref as $id => [$position, $generation]) {
            $this->skipTrivia();
            if ($position !== $this->offset || $position >= $xrefOffset) {
                $this->reject();
            }
            $this->objectHeader($id, $generation);
            $object = $this->readValue();
            $this->skipTrivia();
            $isStream = $this->take('stream(?:\r\n|\n)') !== null;
            if ($isStream) {
                $this->readStream($object, $xrefOffset);
                $this->skipTrivia();
            }
            if (! $this->take('endobj'.self::DELIMITER)) {
                $this->reject();
            }

            if ($id === $root && ! $isStream && ($object['/Type'] ?? null) === '/Catalog') {
                $pageTree = $object['/Pages']['id'] ?? 0;
            }
            if (! $isStream && in_array($object['/Type'] ?? null, ['/Pages', '/Page'], true)) {
                $pages[$id] = $object;
            }
        }

        $this->skipTrivia();
        if ($this->offset !== $xrefOffset || ($pages[$pageTree]['/Type'] ?? null) !== '/Pages') {
            $this->reject();
        }
        $this->countPages($pages, $pageTree, 0, 0);
    }

    private function countPages(array $pages, int $id, int $parent, int $depth): int
    {
        $node = $pages[$id] ?? null;
        if ($node === null || $depth > 24 || ($node['/Parent']['id'] ?? 0) !== $parent) {
            $this->reject();
        }
        if ($node['/Type'] === '/Page') {
            return 1;
        }
        $kids = $node['/Kids'] ?? null;
        if (! is_array($kids) || $kids === [] || ! array_is_list($kids)) {
            $this->reject();
        }
        $children = array_column($kids, 'id');
        if (count(array_unique($children)) !== count($kids)) {
            $this->reject();
        }

        $total = 0;
        foreach ($children as $child) {
            $total += $this->countPages($pages, $child, $id, $depth + 1);
        }
        if (($node['/Count'] ?? 0) !== $total) {
            $this->reject();
        }

        return $total;
    }

    private function readTrailer(): array
    {
        $header = $this->take('xref[ \t]*(?:\r\n|[\r\n])0[ \t]+([1-9][0-9]{0,3})[ \t]*(?:\r\n|[\r\n])');
        $count = (int) ($header[1] ?? 0);
        if ($count < 4 || $count > 4096 || ! $this->take('0000000000 65535 f(?:\r\n| [\r\n])')) {
            $this->reject();
        }

        for ($id = 1; $id < $count; $id++) {
            $record = $this->take('([0-9]{10}) ([0-9]{5}) ([fn])(?:\r\n| [\r\n])');
            if ($record === null) {
                $this->reject();
            }
            if ($record[3] === 'n') {
                $this->xref[$id] = [(int) $record[1], (int) $record[2]];
            }
        }
        asort($this->xref, SORT_REGULAR);

        $this->skipTrivia();
        if (! $this->take('trailer'.self::DELIMITER)) {
            $this->reject();
        }
        $trailer = $this->readValue();
        $this->skipTrivia();
        if (! is_array($trailer) || ($trailer['/Size'] ?? 0) !== $count || ! isset($trailer['/Root']['id'])
            || array_diff(array_keys($trailer), ['/Size', '/Root', '/Info', '/ID']) !== []
            || ! $this->take('startxref[ \t]*[\r\n]+[1-9][0-9]{0,6}[ \t]*[\r\n]+%%EOF[ \t\r\n]*\z')) {
            $this->reject();
        }

        return $trailer;
    }

    private function readValue(int $depth = 0): int|array|string|null
    {
        if ($depth > 24 || ++$this->tokens > 100_000) {
            $this->reject();
        }
        $this->skipTrivia();
        if ($this->take('<<')) {
            $dictionary = [];
            $this->skipTrivia();
            while (! $this->take('>>')) {
                $name = $this->name();
                if (array_key_exists($name, $dictionary)) {
                    $this->reject();
                }
                $dictionary[$name] = $this->readValue($depth + 1);
                $this->skipTrivia();
            }
            if (($dictionary['/FunctionType'] ?? null) === 4) {
                $this->reject();
            }

            return $dictionary;
        }
        if ($this->take('\[')) {
            $array = [];
            $this->skipTrivia();
            while (! $this->take('\]')) {
                $array[] = $this->readValue($depth + 1);
                $this->skipTrivia();
            }

            return $array;
        }
        if (($this->pdf[$this->offset] ?? '') === '/') {
            return $this->name();
        }
        if ($this->take('\(')) {
            $this->skipLiteralString();

            return null;
        }
        if ($this->take('<[\x09\x0a\x0c\x0d\x20a-fA-F0-9]*+>') || $this->take('(?:true|false|null)'.self::DELIMITER)) {
            return null;
        }
        if ($reference = $this->take('([1-9][0-9]{0,3})[ \t\r\n]+([0-9]{1,5})[ \t\r\n]+R'.self::DELIMITER)) {
            $id = (int) $reference[1];
            if (($this->xref[$id][1] ?? -1) !== (int) $reference[2]) {
                $this->reject();
            }

            return ['id' => $id];
        }
        if ($number = $this->take('([+-]?(?:[0-9]{1,18}(?:\.[0-9]{0,16})?|\.[0-9]{1,16}))'.self::DELIMITER)) {
            return str_contains($number[1], '.') ? null : (int) $number[1];
        }

        $this->reject();
    }

    private function name(): string
    {
        $token = $this->take('/([^\x00-\x20()<>\[\]/%]{1,512})'.self::DELIMITER);
        if ($token === null || preg_match('/#(?![0-9a-f]{2})/i', $token[1])) {
            $this->reject();
        }
        $name = '/'.rawurldecode(str_replace('#', '%', $token[1]));
        if (str_contains($name, "\0") || in_array(strtolower($name), self::FORBIDDEN_NAMES, true)) {
            $this->reject();
        }

        return $name;
    }

    private function skipLiteralString(): void
    {
        $start = $this->offset;
        $depth = 1;
        while ($depth > 0) {
            $this->offset += strcspn($this->pdf, '()\\', $this->offset);
            $character = $this->pdf[$this->offset] ?? null;
            if ($character === null || $this->offset - $start > 262_144) {
                $this->reject();
            }
            $this->offset++;
            if ($character === '\\') {
                $this->offset++;
                if ($this->offset >= strlen($this->pdf)) {
                    $this->reject();
                }
            } else {
                $depth += $character === '(' ? 1 : -1;
            }
        }
    }

    private function readStream(int|array|string|null $dictionary, int $xrefOffset): void
    {
        if (! is_array($dictionary) || array_intersect(array_keys($dictionary), ['/F', '/FFilter', '/FDecodeParms', '/DecodeParms']) !== []) {
            $this->reject();
        }
        $length = $this->streamLength($dictionary['/Length'] ?? null);
        if ($length < 0 || $length > $xrefOffset - $this->offset) {
            $this->reject();
        }
        $data = substr($this->pdf, $this->offset, $length);
        $this->offset += $length;
        if (! $this->take('(?:\r\n|[\r\n])?endstream'.self::DELIMITER)) {
            $this->reject();
        }

        $this->checkStreamData($data, $dictionary);
    }

    private function streamLength(int|array|string|null $length): int
    {
        if (is_int($length)) {
            return $length;
        }
        $id = $length['id'] ?? 0;
        if (! isset($this->xref[$id])) {
            $this->reject();
        }

        $afterHeader = $this->offset;
        try {
            [$this->offset, $generation] = $this->xref[$id];
            $this->objectHeader($id, $generation);
            $this->skipTrivia();
            $size = $this->take('([0-9]{1,7})'.self::DELIMITER);
            $this->skipTrivia();
            if ($size === null || ! $this->take('endobj'.self::DELIMITER)) {
                $this->reject();
            }

            return (int) $size[1];
        } finally {
            $this->offset = $afterHeader;
        }
    }

    private function checkStreamData(string $data, array $dictionary): void
    {
        $filter = $dictionary['/Filter'] ?? null;
        if (is_array($filter) && count($filter) === 1 && array_is_list($filter)) {
            $filter = $filter[0];
        }
        $isImage = ($dictionary['/Subtype'] ?? null) === '/Image';
        $width = $dictionary['/Width'] ?? 0;
        $height = $dictionary['/Height'] ?? 0;
        if ($isImage) {
            if (! is_int($width) || ! is_int($height) || min($width, $height) < 1 || max($width, $height) > 8192 || $width * $height > 16_000_000) {
                $this->reject();
            }
            $this->imagePixels += $width * $height;
            if ($this->imagePixels > 32_000_000) {
                $this->reject();
            }
        }

        if ($filter === '/FlateDecode') {
            $remaining = self::MAX_DECODED_BYTES - $this->decodedBytes;
            $memoryLimit = ini_parse_quantity(ini_get('memory_limit'));
            if ($memoryLimit > 0) {
                $remaining = min($remaining, $memoryLimit - memory_get_usage(true) - 16 * 1024 * 1024);
            }
            $decoded = $remaining > 0 ? @gzuncompress($data, $remaining) : false;
            if ($decoded === false) {
                $this->reject();
            }
            $data = $decoded;
        } elseif ($filter === '/DCTDecode' && $isImage) {
            $info = @getimagesizefromstring($data);
            if ($info === false || array_slice($info, 0, 3) !== [$width, $height, IMAGETYPE_JPEG]) {
                $this->reject();
            }
        } elseif (array_key_exists('/Filter', $dictionary)) {
            $this->reject();
        }
        // Inline images carry their own dimensions and filters, outside the checked stream dictionary.
        if (! $isImage && preg_match('~(?:^|[\x09\x0a\x0c\x0d\x20<>()[\]{}])BI'.self::DELIMITER.'~', $data) !== 0) {
            $this->reject();
        }
        $this->decodedBytes += strlen($data);
        if ($this->decodedBytes > self::MAX_DECODED_BYTES) {
            $this->reject();
        }
    }

    private function objectHeader(int $id, int $generation): void
    {
        if (! $this->take($id.'[ \t]+'.$generation.'[ \t]+obj'.self::DELIMITER)) {
            $this->reject();
        }
    }

    private function skipTrivia(): void
    {
        $this->take('(?:[\x09\x0a\x0c\x0d\x20]++|%[^\r\n]*+)*+');
    }

    private function take(string $pattern): ?array
    {
        if ($this->offset > strlen($this->pdf) || preg_match('~\G(?:'.$pattern.')~', $this->pdf, $match, 0, $this->offset) !== 1) {
            return null;
        }
        $this->offset += strlen($match[0]);

        return $match;
    }

    private function reject(): never
    {
        throw new UnexpectedValueException('Unsupported PDF structure.');
    }
}
