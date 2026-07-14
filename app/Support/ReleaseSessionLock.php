<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Release the PHP session lock so Turbo/other requests are not blocked by
 * long-lived SSE streams or frequent polls.
 */
class ReleaseSessionLock
{
    public static function now(?Request $request = null): void
    {
        try {
            $session = $request?->session() ?? session();
            if ($session) {
                $session->save();
            }
        } catch (\Throwable) {
            // Session may already be closed.
        }

        if (function_exists('session_write_close') && session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
    }
}
