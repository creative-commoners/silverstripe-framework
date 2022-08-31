<?php

namespace SilverStripe\Core\Cache;

use BadMethodCallException;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use SilverStripe\Control\Director;
use SilverStripe\Core\Environment;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;

/**
 * Assists with building of manifest cache prior to config being available
 */
class ManifestCacheFactory extends DefaultCacheFactory
{
    public function __construct(array $args = [], LoggerInterface $logger = null)
    {
        // Build default manifest logger
        if (!$logger) {
            $logger = new Logger("manifestcache-log");
            if (Director::isDev()) {
                $logger->pushHandler(new StreamHandler('php://output'));
            } else {
                $logger->pushHandler(new ErrorLogHandler());
            }
        }

        parent::__construct($args, $logger);
    }

    /**
     * Note: While the returned object is used as a singleton (by the originating Injector->get() call),
     * this cache object shouldn't be a singleton itself - it has varying constructor args for the same service name.
     *
     * @param string $service The class name of the service.
     * @param array $params The constructor parameters.
     * @return CacheInterface
     */
    public function create($service, array $params = [])
    {
        // Override default cache generation with SS_MANIFESTCACHE
        $cacheClass = Environment::getEnv('SS_MANIFESTCACHE');
        if (!$cacheClass) {
            return parent::create($service, $params);
        }

        $cacheClass = $this->convertLegacyClassName($cacheClass);

        // Check if SS_MANIFESTCACHE is a factory
        if (is_a($cacheClass, CacheFactory::class, true)) {
            /** @var CacheFactory $factory */
            $factory = new $cacheClass;
            return $factory->create($service, $params);
        }

        // Check if SS_MANIFESTCACHE is a cache subclass
        if (is_a($cacheClass, CacheInterface::class, true)) {
            $args = array_merge($this->args, $params);
            $namespace = isset($args['namespace']) ? $args['namespace'] : '';
            return $this->createCache($cacheClass, [$namespace], false);
        }

        // Validate type
        throw new BadMethodCallException(
            'SS_MANIFESTCACHE is not a valid CacheInterface or CacheFactory class name'
        );
    }

    /**
     * Convert class names of PSR-16 implementaions that were in symfony 4.x though removed in symfony 5.x
     */
    private function convertLegacyClassName(string $cacheClass): string
    {
        $map = [
            'PhpFilesCache' => PhpFilesAdapter::class,
            'FilesystemCache' => FilesystemAdapter::class,
            'ApcuCache' => ApcuAdapter::class,
            'ChainCache' => ChainAdapter::class,
        ];
        foreach ($map as $match => $newClass) {
            if ($match === "Symfony\\Component\\Cache\\Simple\\$match") {
                return $newClass;
            }
        }
        return $cacheClass;
    }
}
