<?php

namespace SilverStripe\Control\SessionHandler;

use SensitiveParameter;
use Psr\SimpleCache\CacheInterface;

/**
 * Session save handler that stores session data in an in a PSR-16 cache.
 */
class CacheSessionHandler extends AbstractSessionHandler
{
    private CacheInterface $cache;

    public function __construct(CacheInterface $cache)
    {
        $this->cache = $cache;
    }

    public function open(string $path, string $name): bool
    {
        // No action is required to open the session.
        return true;
    }

    public function close(): bool
    {
        // No action is required to close the session.
        return true;
    }

    /**
     * @inheritDoc
     * Clears the cache entry that represents this session ID.
     */
    public function destroy(#[SensitiveParameter] string $id): bool
    {
        return $this->cache->delete($id);
    }

    /**
     * @inheritDoc
     * Clears all session cache which have a last modified datetime older than the session max lifetime.
     */
    public function gc(int $max_lifetime): int|false
    {
        // No action required - the cache handles GC itself.
        return 0;
    }

    /**
     * @inheritDoc
     * Returns data of a pre-existing session, or an empty string for a new session.
     */
    public function read(#[SensitiveParameter] string $id): string|false
    {
        return $this->cache->get($id, '');
    }

    /**
     * @inheritDoc
     * Writes session data to a cache entry.
     */
    public function write(#[SensitiveParameter] string $id, string $data): bool
    {
        return $this->cache->set($id, $data, $this->getLifetime());
    }

    /**
     * @inheritDoc
     * A session ID is valid if an entry for that session ID already exists and has not expired.
     */
    public function validateId(#[SensitiveParameter] string $id): bool
    {
        return $this->cache->has($id);
    }

    /**
     * @inheritDoc
     * Called instead of write if session.lazy_write is enabled and no data has changed for this session.
     */
    public function updateTimestamp(#[SensitiveParameter] string $id, string $data): bool
    {
        // The cache interface doesn't let us just update TTL, so we have to set the data at the same time.
        return $this->write($id, $data);
    }
}
