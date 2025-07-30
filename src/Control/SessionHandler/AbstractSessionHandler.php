<?php

namespace SilverStripe\Control\SessionHandler;

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
}
