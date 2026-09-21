<?php

namespace App\Console\Commands;

use App\Models\TicketAttachment;
use App\Support\Attachments\AttachmentStorage;
use Illuminate\Console\Command;

class PruneOrphanAttachments extends Command
{
    protected $signature = 'helpdesk:prune-orphan-attachments {--hours=24 : Only touch files older than this} {--dry-run : List what would be deleted}';

    protected $description = 'Delete attachment files that no database row points to (left by failed uploads or deleted tickets)';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');
        $disk = AttachmentStorage::disk();
        $cutoff = now()->subHours($hours)->getTimestamp();

        $known = TicketAttachment::query()->pluck('storage_path')->flip();
        $deleted = 0;

        foreach ($disk->allFiles('tickets') as $path) {
            // Recent files may belong to an upload that is still in progress.
            if ($known->has($path) || $disk->lastModified($path) > $cutoff) {
                continue;
            }

            $this->line(($dryRun ? 'Would delete ' : 'Deleting ').$path);
            $dryRun || $disk->delete($path);
            $deleted++;
        }

        // The reverse problem cannot be repaired automatically, so only report it.
        $missing = TicketAttachment::query()->pluck('storage_path')->reject(fn ($path) => $disk->exists($path));
        $missing->each(fn ($path) => $this->warn("Database row without a file: {$path}"));

        $this->info(($dryRun ? 'Would delete ' : 'Deleted ')."{$deleted} orphan file(s); {$missing->count()} row(s) without a file.");

        return self::SUCCESS;
    }
}
