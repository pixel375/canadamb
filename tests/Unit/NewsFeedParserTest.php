<?php

namespace Tests\Unit;

use App\Support\NewsFeeds\FeedFailure;
use App\Support\NewsFeeds\FeedParser;
use App\Support\NewsFeeds\FeedTransport;
use PHPUnit\Framework\TestCase;

class NewsFeedParserTest extends TestCase
{
    public function test_an_empty_successful_response_counts_as_a_feed_failure(): void
    {
        $this->expectException(FeedFailure::class);
        (new FeedParser)->candidates('', 'https://example.test/feed');
    }

    public function test_a_feed_cannot_define_a_document_type(): void
    {
        $this->expectException(FeedFailure::class);
        $this->expectExceptionMessage('doctype');
        (new FeedParser)->candidates('<!DOCTYPE rss><rss><channel/></rss>', 'https://example.test/feed');
    }

    public function test_known_non_feed_xml_is_rejected(): void
    {
        $this->expectException(FeedFailure::class);
        $this->expectExceptionMessage('not an RSS');
        (new FeedParser)->candidates('<html><body>Service unavailable</body></html>', 'https://example.test/feed');
    }

    public function test_direct_address_urls_are_rejected_before_fetch(): void
    {
        $this->expectException(FeedFailure::class);
        $this->expectExceptionMessage('Invalid HTTPS feed URL');
        (new FeedTransport)->fetch('https://127.0.0.1/feed');
    }

    public function test_a_poll_only_takes_the_first_hundred_entries(): void
    {
        $items = array_map(fn ($i) => "<item><title>Title $i</title><link>https://example.test/$i</link></item>", range(1, 101));
        $result = (new FeedParser)->candidates('<rss><channel>'.implode($items).'</channel></rss>', 'https://example.test/feed');

        $this->assertCount(100, $result);
        $this->assertSame('https://example.test/100', $result[99]['source_url']);
    }
}
