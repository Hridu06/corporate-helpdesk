<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\TicketAttachment */
class TicketAttachmentResource extends JsonResource
{
    /** Deliberately omits the storage path and hash: files are only reachable through the download endpoint. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'extension' => $this->extension,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'is_image' => $this->is_image,
            'created_at' => $this->created_at,
        ];
    }
}
