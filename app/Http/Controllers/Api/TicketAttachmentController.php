<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Support\Attachments\AttachmentStorage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The only way to get at an attachment's bytes. The route already checked that the
 * viewer may see the ticket; here the file must belong to that ticket and be
 * visible to the viewer (internal-note files are not visible to customers). A file
 * the viewer may not see is answered with 404, so ids cannot be probed.
 */
class TicketAttachmentController extends Controller
{
    public function download(Request $request, Ticket $ticket, int $attachment): Response
    {
        $file = $ticket->attachments()->visibleTo($request->user())->findOrFail($attachment);
        $disk = AttachmentStorage::disk();

        if (! $disk->exists($file->storage_path)) {
            report(new \RuntimeException("Attachment {$file->id} is missing from storage ({$file->storage_path})."));
            abort(404, 'The file is no longer available.');
        }

        return $disk->response($file->storage_path, $file->original_name, [
            // The type we verified, never the client's, and never sniffed by the browser.
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ], 'attachment');
    }
}
