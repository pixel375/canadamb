<?php

namespace App\Console\Commands;

use App\Support\MessageAttachments;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class MessageAttachmentOrphans extends Command
{
    protected $signature = 'messages:attachment-orphans
        {--limit=100 : Maximum number of orphan paths to report}';

    protected $description = 'Report unreferenced private message attachments older than 24 hours without deleting them';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000],
        ]);
        if ($limit === false) {
            $this->error('The limit must be between 1 and 1,000.');

            return self::INVALID;
        }
        if (! Cache::add('messages:attachment-orphans:recent', true, 60)) {
            $this->error('Run the orphan report at most once per minute.');

            return self::FAILURE;
        }

        $cutoff = now()->subDay()->timestamp;
        $count = 0;
        $entries = MessageAttachments::disk()->getDriver()->listContents(MessageAttachments::DIRECTORY, false);
        foreach ($entries as $entry) {
            if (! $entry->isFile() || ! MessageAttachments::acceptsPath($entry->path())
                || $entry->lastModified() === null || $entry->lastModified() >= $cutoff
                || DB::table('message_attachments')->useWritePdo()->where('path', $entry->path())->exists()) {
                continue;
            }

            $this->line($entry->path());
            if (++$count === $limit) {
                break;
            }
        }
        $this->info("Dry run found $count eligible message attachment file(s).");

        return self::SUCCESS;
    }
}
