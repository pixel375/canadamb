<?php

namespace App\Console\Commands;

use App\Support\NewsFeeds\FeedPoller;
use Illuminate\Console\Command;

class PollNewsFeeds extends Command
{
    protected $signature = 'news:poll';

    protected $description = 'Discover editorial candidates from due, approved news feeds';

    public function handle(FeedPoller $poller): int
    {
        if (config('network.prelaunch') || ! config('network.news_polling')) {
            $this->info('News feed polling is disabled.');

            return self::SUCCESS;
        }

        $counts = $poller->poll();
        $this->info("Checked {$counts['checked']} feed(s). Failures: {$counts['failed']}.");

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
