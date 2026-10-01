<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * One-off after upgrading: copies images saved before the image repository moved to public/media (the old `public`
 * disk in storage/app/public) into the `media` disk. Paths in the database don't change, so nothing else is needed.
 * Safe to run more than once: files already copied are skipped.
 */
class MoveMediaToPublic extends Command
{
    protected $signature = 'media:move-to-public {--from=public : the old disk} {--delete : remove each original after copying}';

    protected $description = 'Copy images from storage/app/public into the public/media image repository';

    public function handle(): int
    {
        $from = Storage::disk((string) $this->option('from'));
        $to = Storage::disk('media');
        $copied = $skipped = 0;

        foreach ($from->allFiles() as $path) {
            if (str_starts_with(basename($path), '.')) {
                continue;
            }
            if ($to->exists($path)) {
                $skipped++;
            } else {
                $stream = $from->readStream($path);
                $to->writeStream($path, $stream, ['visibility' => 'public']);
                if (is_resource($stream)) {
                    fclose($stream);
                }
                $copied++;
            }
            if ($this->option('delete')) {
                $from->delete($path);
            }
        }

        $this->info("Copied {$copied} files, {$skipped} already there.");

        return self::SUCCESS;
    }
}
