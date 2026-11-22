<?php

namespace App\Console\Commands;

use App\Models\ListingMedia;
use App\Support\ListingPhotos;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ListingPhotoOrphans extends Command
{
    protected $signature = 'marketplace:photo-orphans
        {--apply : Delete eligible files after verifying they have no database references}
        {--limit=100 : Maximum eligible files to process per run}';

    protected $description = 'Inspect Marketplace WebP files older than 24 hours for orphan recovery (dry run by default)';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1000],
        ]);
        if ($limit === false) {
            $this->error('The limit must be between 1 and 1,000.');

            return self::INVALID;
        }

        $apply = $this->option('apply');
        $cutoff = now()->subHours(24)->getTimestamp();
        $disk = Storage::disk(config('network.media_disk'));
        $directory = ListingPhotos::DIRECTORY;
        $eligible = $deleted = 0;
        foreach ($disk->files($directory) as $path) {
            if (! preg_match('#^'.$directory.'/[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}(?:-preview)?\.webp$#D', $path)
                || $disk->lastModified($path) >= $cutoff
                || ListingMedia::referencesPath($path)) {
                continue;
            }

            if (! $apply) {
                $this->line($path);
            } elseif ($disk->delete($path)) {
                $deleted++;
            }
            if (++$eligible === $limit) {
                break;
            }
        }

        if (! $apply) {
            $this->info("Dry run found $eligible eligible Marketplace photo file(s).");

            return self::SUCCESS;
        }

        $this->info("Deleted $deleted of $eligible eligible Marketplace photo file(s).");

        return $deleted === $eligible ? self::SUCCESS : self::FAILURE;
    }
}
