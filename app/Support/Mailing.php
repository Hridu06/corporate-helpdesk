<?php

namespace App\Support;

use Closure;
use Throwable;

/**
 * Sending mail happens inside the request (no queue worker is assumed). A mail server
 * outage must not turn into a 500, and above all must not behave differently for an
 * existing account than for an unknown one, which would let anyone enumerate accounts.
 * Failures are reported (logged) and the caller carries on with its normal response.
 */
final class Mailing
{
    /** @return bool whether the mail was handed to the transport without an error */
    public static function attempt(Closure $send): bool
    {
        try {
            $send();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
