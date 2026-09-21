<?php

namespace App\Support\Attachments;

use RuntimeException;

/** A file that must not be stored. The message is written for the person who uploaded it. */
final class AttachmentRejected extends RuntimeException {}
