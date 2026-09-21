<?php

namespace App\Support\Attachments;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Stores attachments in the order that keeps the database and the disk consistent:
 * every file is inspected first (nothing touched), then files are written and rows
 * inserted inside the caller's transaction. The caller passes a `$written` list so
 * that, if anything fails or the transaction rolls back, the files already on disk
 * can be removed with deleteFiles().
 */
final class AttachmentStorage
{
    public function __construct(private readonly AttachmentInspector $inspector) {}

    public static function disk(): Filesystem
    {
        return Storage::disk(config('helpdesk.attachments.disk'));
    }

    /**
     * Check every file before anything is stored.
     *
     * @param  array<int, UploadedFile>  $files
     * @return list<array{file: UploadedFile, meta: array{extension: string, mime_type: string, size: int, sha256: string}}>
     */
    public function inspectAll(array $files): array
    {
        $files = array_values($files);
        $config = config('helpdesk.attachments');

        $total = array_sum(array_map(fn (UploadedFile $f) => (int) $f->getSize(), $files));
        if ($total > $config['max_request_kb'] * 1024) {
            throw ValidationException::withMessages([
                'attachments' => 'The files together are larger than '.round($config['max_request_kb'] / 1024, 1).' MB.',
            ]);
        }

        $errors = [];
        $inspected = [];

        foreach ($files as $index => $file) {
            try {
                $inspected[] = ['file' => $file, 'meta' => $this->inspector->inspect($file)];
            } catch (AttachmentRejected $e) {
                $clientExtension = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo((string) $file->getClientOriginalName(), PATHINFO_EXTENSION)));
                $name = AttachmentInspector::cleanName((string) $file->getClientOriginalName(), substr($clientExtension ?: 'file', 0, 8));
                $errors["attachments.{$index}"] = [$name.': '.$e->getMessage()];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $inspected;
    }

    /** Refuse when this ticket would end up with too many files or bytes. Call inside the ticket lock. */
    public function assertTicketCapacity(Ticket $ticket, array $inspected): void
    {
        if ($inspected === []) {
            return;
        }

        $config = config('helpdesk.attachments');
        $existing = TicketAttachment::query()->where('ticket_id', $ticket->id);
        $files = (clone $existing)->count() + count($inspected);
        $bytes = (int) (clone $existing)->sum('size') + array_sum(array_column(array_column($inspected, 'meta'), 'size'));

        if ($files > $config['max_ticket_files'] || $bytes > $config['max_ticket_kb'] * 1024) {
            throw new HttpResponseException(response()->json([
                'message' => 'This ticket has reached its attachment limit ('.$config['max_ticket_files'].' files, '.round($config['max_ticket_kb'] / 1024).' MB).',
                'code' => 'attachment_limit',
            ], 422));
        }
    }

    /**
     * Write the files and insert their rows. Every path written is appended to
     * `$written` immediately, so the caller can clean up after a failure.
     *
     * @param  list<array{file: UploadedFile, meta: array}>  $inspected
     * @param  list<string>  $written
     * @return list<TicketAttachment>
     */
    public function store(Ticket $ticket, ?TicketMessage $message, User $actor, array $inspected, array &$written): array
    {
        $rows = [];

        foreach ($inspected as ['file' => $file, 'meta' => $meta]) {
            // Server-generated; nothing the user typed is part of the path or the stored name.
            $path = "tickets/{$ticket->id}/".Str::lower((string) Str::ulid());

            $stream = fopen($file->getRealPath(), 'rb');
            try {
                self::disk()->put($path, $stream);
                $written[] = $path;
            } finally {
                is_resource($stream) && fclose($stream);
            }

            $rows[] = TicketAttachment::create([
                'ticket_id' => $ticket->id,
                'ticket_message_id' => $message?->id,
                'uploaded_by' => $actor->id,
                'storage_path' => $path,
                'original_name' => AttachmentInspector::cleanName((string) $file->getClientOriginalName(), $meta['extension']),
                'extension' => $meta['extension'],
                'mime_type' => $meta['mime_type'],
                'size' => $meta['size'],
                'sha256' => $meta['sha256'],
            ]);
        }

        return $rows;
    }

    /** @param list<string> $paths */
    public function deleteFiles(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                self::disk()->delete($path);
            } catch (Throwable $e) {
                report($e); // never hide the original failure; the orphan command will retry
            }
        }
    }
}
