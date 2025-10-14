<?php

namespace SilverStripe\Control\SessionHandler;

use SensitiveParameter;
use SessionHandlerInterface;
use SessionUpdateTimestampHandlerInterface;
use SilverStripe\Control\Session;

abstract class AbstractSessionHandler implements SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    /**
     * Get the session lifetime in seconds.
     * Returns the cookie lifetime if it's non-zero, otherwise returns the garbage collection lifetime.
     */
    protected function getLifetime(): int
    {
        $cookieLifetime = (int) Session::config()->get('timeout');
        if ($cookieLifetime) {
            return $cookieLifetime;
        }
        return (int) ini_get('session.gc_maxlifetime');
    }

    /**
     * Validate a PHP session ID against the default PHP session ID format.
     * This is a security measure to prevent people from injecting invalid session IDs in the request.
     */
    protected function validatePhpSessId(#[SensitiveParameter] string $id): bool
    {
        if (PHP_VERSION_ID >= 90000) {
            // PHP 9.0+
            // PHP 8.4 deprecated session.sid_length and session.sid_bits_per_character
            // The default values were 32 and 4 respectively, and it's assumed that's what
            // will be used going forward.
            // https://www.php.net/manual/en/migration84.deprecated.php#migration84.deprecated.session
            if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
                return false;
            }
        } else {
            // <= PHP 9.0
            // Allow characters that could appear in older PHP session ID formats, including PHP 8.4
            if (!preg_match('/^[a-zA-Z0-9,-]{22,128}$/', $id)) {
                return false;
            }
        }
        return true;
    }
}
