<?php

namespace App\Support\NewsFeeds;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Str;
use InvalidArgumentException;

class FeedParser
{
    public function candidates(string $xml, string $feedUrl): array
    {
        if ($xml === '' || strlen($xml) > FeedTransport::MAX_BYTES || ! mb_check_encoding($xml, 'UTF-8')
            || str_contains($xml, "\0") || stripos($xml, '<!DOCTYPE') !== false
            || preg_match('/^\s*(?:\xEF\xBB\xBF)?\s*<\?xml[^?]*encoding\s*=\s*["\'](?!utf-8["\'])/i', $xml)) {
            throw new FeedFailure('Only UTF-8 feeds without a doctype are supported.');
        }
        $document = new DOMDocument;
        if (! @$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new FeedFailure('The feed could not be parsed as XML.');
        }
        $document->documentURI = $feedUrl;
        $host = parse_url($feedUrl, PHP_URL_HOST);

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
        $atom = $xpath->query('/atom:feed')->length === 1;
        if (! $atom && $xpath->query('/rss/channel')->length !== 1) {
            throw new FeedFailure('The response is not an RSS 2.0 or Atom feed.');
        }

        $entries = $xpath->query($atom ? '/atom:feed/atom:entry' : '/rss/channel/item');
        $results = [];
        foreach ($entries as $index => $entry) {
            if ($index >= 100) {
                break;
            }
            $title = $this->title($xpath, $entry, $atom);
            $link = $xpath->query($atom ? 'atom:link[not(@rel) or @rel="alternate"][1]' : 'link[1]', $entry)->item(0);
            $url = $link ? $this->link($link, $atom, $feedUrl, $host) : null;
            if ($title === '' || $url === null) {
                continue;
            }
            $key = hash('sha256', $url);
            $date = $atom
                ? $xpath->evaluate('string((atom:published | atom:updated[not(../atom:published)])[1])', $entry)
                : $xpath->evaluate('string(pubDate[1])', $entry);
            $results[$key] ??= ['source_hash' => $key, 'source_url' => $url, 'title' => $title, 'source_date' => $this->date($date)];
        }

        return array_values($results);
    }

    private function link(DOMElement $link, bool $atom, string $feedUrl, string $host): ?string
    {
        $value = trim($atom ? $link->getAttribute('href') : $link->textContent);
        if ($value === '') {
            return null;
        }
        try {
            $base = UriResolver::resolve(new Uri($feedUrl), new Uri($link->baseURI ?? $feedUrl));
            $url = UriResolver::resolve($base, new Uri($value));

            return FeedUrl::normalize((string) $url, $host);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function title(DOMXPath $xpath, DOMElement $entry, bool $atom): string
    {
        $value = $xpath->evaluate($atom ? 'string(atom:title[1])' : 'string(title[1])', $entry);

        return Str::limit(Str::squish(strip_tags($value)), 220, '');
    }

    private function date(string $value): ?string
    {
        $parts = date_parse($value);
        if (! $parts['year'] || $parts['year'] < 1900 || $parts['year'] > 9999
            || ! $parts['month'] || ! $parts['day'] || $parts['error_count'] || $parts['warning_count']) {
            return null;
        }

        return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d');
    }
}
