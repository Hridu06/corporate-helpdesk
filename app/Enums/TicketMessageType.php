<?php

namespace App\Enums;

enum TicketMessageType: string
{
    case PublicReply = 'public_reply';
    case InternalNote = 'internal_note';
}
