<?php

namespace SilverStripe\Core\Cache;

use InvalidArgumentException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Returns the most performant combination of caches available on the system:
 * - `PhpFilesCache` (PHP 7 with opcache enabled)
 * - `ApcuCache` (requires APC) with a `FilesystemCache` fallback (for larger cache volumes)
 * - `FilesystemCache` if none of the above is available
 *
 * Modelled after `Symfony\Component\Cache\Adapter\AbstractAdapter::createSystemCache()`
 */
class DefaultCacheFactory implements CacheFactory
{
    /**
     * @var string Absolute directory path
     */
    protected $args = [];

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @param array $args List of global options to merge with args during create()
     * @param LoggerInterface $logger Logger instance to assign
     */
    public function __construct($args = [], LoggerInterface $logger = null)
    {
        $this->args = $args;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function create($service, array $args = [])
    {
        // merge args with default
        $args = array_merge($this->args, $args);
        $namespace = isset($args['namespace']) ? $args['namespace'] : '';
        $defaultLifetime = isset($args['defaultLifetime']) ? $args['defaultLifetime'] : 0;
        $directory = isset($args['directory']) ? $args['directory'] : null;
        $version = isset($args['version']) ? $args['version'] : null;

        // In-memory caches are typically more resource constrained (number of items and storage space).
        // Give cache consumers an opt-out if they are expecting to create large caches with long lifetimes.
        $useInMemoryCache = isset($args['useInMemoryCache']) ? $args['useInMemoryCache'] : true;

        // Check support
        $apcuSupported = ($this->isAPCUSupported() && $useInMemoryCache);
        $phpFilesSupported = $this->isPHPFilesSupported();

        // If apcu isn't supported, phpfiles is the next best preference
        if (!$apcuSupported && $phpFilesSupported) {
            return $this->createCache(PhpFilesAdapter::class, [$namespace, $defaultLifetime, $directory]);
        }

        // Create filesystem cache
        $fs = $this->createCache(FilesystemAdapter::class, [$namespace, $defaultLifetime, $directory]);
        if (!$apcuSupported) {
            return $fs;
        }

        // Chain this cache with ApcuCache
        // Note that the cache lifetime will be shorter there by default, to ensure there's enough
        // resources for "hot cache" items in APCu as a resource constrained in memory cache.
        $apcuNamespace = $namespace . ($namespace ? '_' : '') . md5(BASE_PATH);
        $apcu = $this->createCache(ApcuAdapter::class, [$apcuNamespace, (int) $defaultLifetime / 5, $version]);

        return $this->createCache(ChainAdapter::class, [[$apcu, $fs]]);
    }

    /**
     * Determine if apcu is supported
     *
     * @return bool
     */
    protected function isAPCUSupported()
    {
        static $apcuSupported = null;
        if (null === $apcuSupported) {
            // Need to check for CLI because Symfony won't: https://github.com/symfony/symfony/pull/25080
            $apcuSupported = Director::is_cli() ? ini_get('apc.enable_cli') && ApcuAdapter::isSupported() : ApcuAdapter::isSupported();
        }
        return $apcuSupported;
    }

    /**
     * Determine if PHP files is supported
     *
     * @return bool
     */
    protected function isPHPFilesSupported()
    {
        static $phpFilesSupported = null;
        if (null === $phpFilesSupported) {
            $phpFilesSupported = PhpFilesAdapter::isSupported();
        }
        return $phpFilesSupported;
    }

    /**
     * Creates an object with a PSR-16 interface from a PSR-6 class name
     *
     * Quick explanation of caching standards:
     * - Symfony cache implements the PSR-6 standard
     * - Symfony provides adapters wrap a PSR-6 backend with a PSR-16 interface
     * - Silverstripe uses the PSR-16 to interface with caches
     * - Psr\SimpleCache\CacheInterface is the php interface of the PSR-16 standard
     *
     * Further reading:
     * - https://symfony.com/doc/current/components/cache/psr6_psr16_adapters.html#using-a-psr-6-cache-object-as-a-psr-16-cache
     * - https://github.com/php-fig/simple-cache
     */
    public function createCache(string $psr6Class, array $args, bool $useInjector = true): CacheInterface
    {
        if (!is_a($psr6Class, CacheItemPoolInterface::class)) {
            throw new InvalidArgumentException(sprintf(
                'class %s should be PSR-6 compatible and implement %s',
                $psr6Class,
                CacheItemPoolInterface::class
            ));
        }
        // Create the PSR-6 class
        if ($useInjector) {
            // Injector is used for in most instances to allow modification of the cache implementations
            $psr6Cache = Injector::inst()->createWithArgs($psr6Class, $args);
        } else {
            // ManifestCacheFactory cannot use Injector because config is not available at that point
            $psr6Cache = new $psr6Class(...$args);
        }

        // Assign cache logger
        if ($this->logger && $psr6Cache instanceof LoggerAwareInterface) {
            $psr6Cache->setLogger($this->logger);
        }

        // Wrap the PSR-6 class inside a class with a PSR-16 interface
        if ($useInjector) {
            $psr16Cache = Injector::inst()->createWithArgs(Psr16Cache::class, [$psr6Cache]);
        } else {
            $psr16Cache = new Psr16Cache($psr6Cache);
        }

        return $psr16Cache;
    }
}
