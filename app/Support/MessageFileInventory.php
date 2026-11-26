<?php

namespace App\Support;

use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

final class MessageFileInventory
{
    public function inspect(int $limit): array
    {
        $limit = max(1, min(5000, $limit));
        $result = ['schema_available' => Schema::hasTable('message_attachments'), 'rows_examined' => 0,
            'missing_files' => 0, 'invalid_references' => 0, 'files_examined' => 0, 'old_unreferenced_files' => 0,
            'unexpected_entries' => 0, 'rows_truncated' => false, 'files_truncated' => false, 'directory_symlink' => false];
        if (! $result['schema_available']) {
            return $result;
        }
        $disk = Storage::disk(MessageAttachments::DISK);
        $directory = $disk->path('attachments');
        if (is_link($directory)) {
            $result['directory_symlink'] = true;

            return $result;
        }
        $rows = DB::table('message_attachments')->orderBy('id')->limit($limit + 1)->get(['image_path', 'thumbnail_path']);
        $result['rows_truncated'] = $rows->count() > $limit;
        foreach ($rows->take($limit) as $row) {
            $result['rows_examined']++;
            foreach ([$row->image_path, $row->thumbnail_path] as $path) {
                if (! $this->valid($path) || is_link($disk->path($path))) {
                    $result['invalid_references']++;
                } elseif (! $disk->exists($path)) {
                    $result['missing_files']++;
                }
            }
        }
        if (! is_dir($directory)) {
            return $result;
        }
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
            if ($result['files_examined'] >= $limit) {
                $result['files_truncated'] = true;
                break;
            }
            $result['files_examined']++;
            $path = 'attachments/'.$file->getFilename();
            if ($file->isLink() || ! $file->isFile() || ! $this->valid($path)) {
                $result['unexpected_entries']++;

                continue;
            }
            // Grace period avoids classifying in-flight uploads as candidates.
            if ($file->getMTime() < now()->subDays(2)->timestamp && ! DB::table('message_attachments')
                ->where('image_path', $path)->orWhere('thumbnail_path', $path)->exists()) {
                $result['old_unreferenced_files']++;
            }
        }

        return $result;
    }

    private function valid(string $path): bool
    {
        return (bool) preg_match('/^attachments\/[a-f0-9-]{36}(-preview)?\.webp$/D', $path);
    }
}
