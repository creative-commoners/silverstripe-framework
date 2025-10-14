<?php

namespace SilverStripe\Control\SessionHandler;

use RuntimeException;
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
     * Check the PHP session ID i.e. PHPSESSID is valid against the default PHP session ID format.
     * This is a security measure to prevent people from injecting invalid session IDs in the request.
     *
     * This only needs to be called on read()
     * We do not need to call this on write(), destroy(), updateTimestamp(), or validateId()
     * as those methods are only called for session IDs that have already been accepted by PHP.
     */
    protected function checkSessionID(#[SensitiveParameter] string $id): void
    {
        // Allow characters that could appear in PHP session ID formats, including PHP 8.4, but probably
        // not PHP 9.0+. This regex includes session.sid_length which can be between 22 and 256 and for
        // a session.sid_bits_per_character which can be between 4 and 6
        //
        // Note that PHP 8.4 deprecated session.sid_length and session.sid_bits_per_character
        // The default values were 32 and 4 respectively, and it's likely these will be
        // the only supported values in PHP 9.0+. This means the regex would need to be /^[a-f0-9]{32}$/
        // https://www.php.net/manual/en/migration84.deprecated.php#migration84.deprecated.session
        if (preg_match('/^[a-zA-Z0-9,-]{22,256}$/', $id)) {
            return;
        }
        throw new RuntimeException('Invalid session ID');
    }
}
