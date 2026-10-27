<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio;

use Contenir\PageCache\Clear\ClearVerifier;
use Contenir\SiteToken\Replay\CacheReplayGuard;
use Contenir\SiteToken\Replay\FileReplayGuard;
use Contenir\SiteToken\Replay\ReplayGuardInterface;
use InvalidArgumentException;
use Laminas\Cache\Psr\SimpleCache\SimpleCacheDecorator;
use Laminas\Cache\Storage\StorageInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;
use RuntimeException;

use function get_debug_type;
use function is_array;
use function sprintf;
use function sys_get_temp_dir;

/**
 * Builds the cache clear endpoint from `config['pagecache']['clear']` (see ClearOptions), with
 * the page store and each other cache it lists: a PSR-16 cache as it is, a laminas-cache storage
 * wrapped so that only its own namespace is cleared.
 *
 * @api
 */
final readonly class CacheClearMiddlewareFactory
{
    /** Starts each accepted clear's file name or cache key. */
    public const string REPLAY_PREFIX = 'contenir-cache-';

    /**
     * @throws RuntimeException
     */
    private static function cache(mixed $service, string $name): CacheInterface
    {
        return match (true) {
            $service instanceof CacheInterface => $service,
            $service instanceof StorageInterface => new SimpleCacheDecorator($service),
            default => throw new RuntimeException(sprintf(
                'contenir/contenir-page-cache-mezzio: the cache service "%s" listed in pagecache.clear.caches is a %s,'
                    . ' not a Psr\SimpleCache\CacheInterface or a Laminas\Cache\Storage\StorageInterface.',
                $name,
                get_debug_type($service),
            )),
        };
    }

    /**
     * @throws ContainerExceptionInterface
     * @throws RuntimeException When the replay cache is not a PSR-16 cache.
     */
    private static function replay(
        ContainerInterface $container,
        ClearOptions $options,
        SystemClock $clock,
    ): ReplayGuardInterface {
        if (null === $options->replayCache) {
            return new FileReplayGuard($options->replayDirectory ?? sys_get_temp_dir(), $clock, self::REPLAY_PREFIX);
        }

        return new CacheReplayGuard(
            self::cache($container->get($options->replayCache), $options->replayCache),
            $clock,
            self::REPLAY_PREFIX,
        );
    }

    /**
     * @throws ContainerExceptionInterface When a service the configuration names cannot be built.
     * @throws InvalidArgumentException    When a setting cannot be used as it stands.
     * @throws RuntimeException            When the page cache or a listed cache is neither PSR-16 nor a laminas-cache storage.
     */
    public function __invoke(ContainerInterface $container): CacheClearMiddleware
    {
        /** @var mixed $config */
        $config  = $container->has('config') ? $container->get('config') : null;
        $options = ClearOptions::fromAppConfig(is_array($config) ? $config : []);
        $store   = (new PageStoreFactory())($container);

        if (null === $options) {
            return new CacheClearMiddleware(null, $store);
        }

        $caches = [];
        foreach ($options->caches as $name) {
            $caches[$name] = self::cache($container->get($name), $name);
        }

        $clock = new SystemClock();

        return new CacheClearMiddleware(
            new ClearVerifier($options->keys, $clock, self::replay($container, $options, $clock)),
            $store,
            $caches,
        );
    }
}
