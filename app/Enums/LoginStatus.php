<?php

namespace App\Enums;

enum LoginStatus: string
{
    /** Credentials correct and a session was started. */
    case Success = 'success';

    /** Wrong password for an existing account. */
    case Failed = 'failed';

    /** Correct password, but the account is deactivated or the email is unverified. */
    case Blocked = 'blocked';
}
