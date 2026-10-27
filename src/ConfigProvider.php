<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio;

/**
 * Registers the page-cache middleware and the `pagecache` defaults.
 *
 * The site still has to name its cache service in `pagecache.cache` and pipe
 * the middleware (see README).
 *
 * @api
 */
final readonly class ConfigProvider
{
    /**
     * @return array<string, mixed>
     */
    public function getDependencies(): array
    {
        return [
            'factories' => [
                PageCacheMiddleware::class  => PageCacheMiddlewareFactory::class,
                CacheClearMiddleware::class => CacheClearMiddlewareFactory::class,
                CachePolicy::class          => CachePolicyFactory::class,
                PageStore::class            => PageStoreFactory::class,
            ],
        ];
    }

    /**
     * The `pagecache` defaults, in the shape the Laminas MVC adapter uses.
     * `options.cache` is the master switch the admin's pagecache.local.php
     * overrides; it defaults to off.
     *
     * @return array<string, mixed>
     */
    public function getPageCacheDefaults(): array
    {
        return [
            'cache'          => null,
            'ttl'            => PageCacheConfig::DEFAULT_TTL,
            'per_item_ttl'   => false,
            'cache_control'  => 'no-cache',
            'session_cookie' => 'PHPSESSID',
            'options'        => ActiveOptions::DEFAULTS,
            'routes'         => [],
            'bypass'         => null,
            'mutators'       => [],
            'file'           => null,
            'clear'          => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(): array
    {
        return [
            'dependencies' => $this->getDependencies(),
            'pagecache'    => $this->getPageCacheDefaults(),
        ];
    }
}
