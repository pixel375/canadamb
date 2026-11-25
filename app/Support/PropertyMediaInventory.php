<?php

namespace App\Support;

use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class PropertyMediaInventory
{
    public function inspect(int $limit): array
    {
        $limit = max(1, min(5000, $limit));

        return [
            'marketplace' => $this->scan('listing_media', 'listings', config('network.media_disk'), $limit),
            'private_properties' => $this->scan('property_photos', 'property-drafts', 'local', $limit),
        ];
    }

    private function scan(string $table, string $folder, string $diskName, int $limit): array
    {
        $result = ['schema_available' => Schema::hasTable($table), 'rows_examined' => 0,
            'missing_files' => 0, 'invalid_references' => 0, 'files_examined' => 0,
            'old_unreferenced_files' => 0, 'unexpected_entries' => 0,
            'rows_truncated' => false, 'files_truncated' => false, 'directory_symlink' => false];
        if (! $result['schema_available']) {
            return $result;
        }
        $disk = Storage::disk($diskName);
        $directory = $disk->path($folder);
        if (is_link($directory)) {
            $result['directory_symlink'] = true;

            return $result;
        }
        $pattern = '~^'.preg_quote($folder, '~').'/[a-f0-9-]{36}(-preview)?\\.webp$~D';
        $rows = DB::table($table)->orderBy('id')->limit($limit + 1)->get(['image_path', 'thumbnail_path']);
        $result['rows_truncated'] = $rows->count() > $limit;
        foreach ($rows->take($limit) as $row) {
            $result['rows_examined']++;
            foreach ([$row->image_path, $row->thumbnail_path] as $path) {
                if (! is_string($path) || ! preg_match($pattern, $path) || is_link($disk->path($path))) {
                    $result['invalid_references']++;
                } elseif (! $disk->exists($path)) {
                    $result['missing_files']++;
                }
            }
        }
        if (! is_dir($directory)) {
            return $result;
        }
        $cutoff = now()->subDays(2)->timestamp;
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
            if ($result['files_examined'] >= $limit) {
                $result['files_truncated'] = true;
                break;
            }
            $result['files_examined']++;
            $path = $folder.'/'.$file->getFilename();
            if ($file->isLink() || ! $file->isFile() || ! preg_match($pattern, $path)) {
                $result['unexpected_entries']++;

                continue;
            }
            if ($file->getMTime() < $cutoff && ! DB::table($table)->where('image_path', $path)->orWhere('thumbnail_path', $path)->exists()) {
                $result['old_unreferenced_files']++;
            }
        }

        return $result;
    }
}
