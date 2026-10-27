# contenir/contenir-page-cache-mezzio

Formerly `contenir/contenir-cache-mezzio`, and before that `contenir/cache-mezzio`.
See [UPGRADE-page-cache.md](UPGRADE-page-cache.md) to move from either.

[![Continuous Integration](https://github.com/contenir/contenir-cache-mezzio/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/contenir/contenir-cache-mezzio/actions/workflows/continuous-integration.yml)
[![codecov](https://codecov.io/gh/contenir/contenir-cache-mezzio/graph/badge.svg)](https://codecov.io/gh/contenir/contenir-cache-mezzio)

Mezzio (PSR-15) adapter for [`contenir/contenir-page-cache`](https://github.com/contenir/contenir-page-cache).

A full-page output cache middleware, controlled by the Contenir admin's
Page Cache screen. It is the sibling of
[`contenir/contenir-cache-laminas-mvc`](https://github.com/contenir/contenir-cache-laminas-mvc):
same `pagecache` config key, same `cache_with_*` / `make_id_with_*` options,
same route overrides, so a site's settings mean the same thing on either
framework.

## Requirements

- PHP 8.3, 8.4 or 8.5
- `contenir/contenir-page-cache` 2.0+, `contenir/contenir-config` 2.1+
- `laminas/laminas-diactoros` 3.x, PSR-7, PSR-11, PSR-15, PSR-16 and PSR-20
- Optional: `laminas/laminas-cache` 3.x or 4.x, for the storage the admin's
  purge buttons understand (see [Storage and purging](docs/storage.md))

The 0.x releases remain available from the `0.x` branch and `v0.*` tags; see
[UPGRADE-2.0.md](UPGRADE-2.0.md).

## Install

```bash
composer require contenir/contenir-page-cache-mezzio:^2.0@RC
```

`laminas/laminas-component-installer` adds `Contenir\PageCache\Mezzio\ConfigProvider`
to `config/config.php`. Without it, add the provider yourself:

```php
$aggregator = new ConfigAggregator([
    // …
    \Contenir\PageCache\Mezzio\ConfigProvider::class,
    // …
]);
```

The provider registers `PageCacheMiddleware` (and the `CachePolicy` and
`PageStore` it is built from) and declares the `pagecache` defaults, with
caching off.


## Configure

Point the middleware at a cache service and set the site's defaults:

```php
// config/autoload/pagecache.global.php

return [
    'pagecache' => [
        // Service name of a Psr\SimpleCache\CacheInterface, or of a
        // Laminas\Cache\Storage\StorageInterface (wrapped for you).
        'cache'   => 'cache.pages',

        // Seconds. Applied to a laminas storage at storage level.
        'ttl'     => 300,

        // The site's defaults. The admin's pagecache.local.php overrides
        // them key by key; `cache` is the master switch.
        'options' => [
            'cache'              => true,
            'cache_with_query'   => true,
            'make_id_with_query' => true,
        ],

        // Regex => option overrides; the last matching pattern wins.
        'routes'  => [
            '/api.*' => ['cache' => false],
        ],
    ],
];
```

Every key, the options and the route overrides are described in
[docs/configuration.md](docs/configuration.md).

## Pipe it

```php
// config/pipeline.php

$app->pipe(ErrorHandler::class);
$app->pipe(ServerUrlMiddleware::class);
$app->pipe(MaintenanceMiddleware::class);   // a 503 must never be cached, or served from cache
$app->pipe(SessionMiddleware::class);       // if the site has one: authenticated visitors then bypass
$app->pipe(\Contenir\PageCache\Mezzio\CacheClearMiddleware::class);  // the CMS's clear, at /api/cache
$app->pipe(\Contenir\PageCache\Mezzio\PageCacheMiddleware::class);
$app->pipe(RouteMiddleware::class);
// … ImplicitHeadMiddleware, DispatchMiddleware, NotFoundHandler
```

- **After maintenance**, so a site in maintenance answers 503 rather than a
  cached page (and a 503 is never stored).
- **After session**, if the site pipes one, so the `session` attribute is
  there to recognise a logged-in user. Without it, the session cookie alone
  stands in for the session.
- **Before routing**, so a hit skips routing and dispatch altogether.
- **The clear before the page cache**, so the CMS's request to clear the
  site's caches is answered before anything is read from them.

## Clearing from the CMS

The CMS clears the site's caches by posting a signed token to the site's
fixed `/api/cache` (the protocol is contenir/contenir-page-cache's
`Clear\ClearProtocol`). `CacheClearMiddleware` checks it, then clears the page
store and each other cache the site lists, through their own stores: a PSR-16
cache's `clear()`, or a laminas-cache storage's own namespace only, so caches
sharing a directory are left alone. The page store's generation moves on with
it, so a page rendered from old data while the clear lands is never served.

```php
'pagecache' => [
    'clear' => [
        'keys'   => 'contenir_preview',     // the config holding issuer, audience, secrets and sign_with
        'caches' => ['workflow'],           // other cache services to clear, besides the pages
        'replay' => ['cache' => 'Shared'],  // a PSR-16 service shared by servers; or
                                            // ['directory' => '/var/app/clear'], default the temp dir
    ],
],
```

The keys default to the ones previews use, so a site holds one secret for
both. With no `clear` configured, the middleware passes everything through and
the endpoint does not exist. It answers `204` once everything is cleared,
`500` naming what could not be, `405` to anything but a POST, and `400`,
`401` or `409` to a refused token.


## Documentation

- [Configuration](docs/configuration.md): every `pagecache` key, the
  `cache_with_*` / `make_id_with_*` options and route overrides.
- [How it works](docs/how-it-works.md): bypass, hit and miss rules, and why the
  admin's file is read on every request.
- [Extending](docs/extending.md): vetoing a response, response mutators and
  the public API.
- [Storage and purging](docs/storage.md): PSR-16 and laminas-cache storages,
  the TTL pitfall and the admin's purge buttons.

## Public API

| Type | Purpose |
|------|---------|
| `ConfigProvider` | Registers the four factories below and the `pagecache` defaults (`getDependencies()`, `getPageCacheDefaults()`). |
| `PageCacheMiddleware` | The PSR-15 middleware: `(CachePolicy $policy, PageStore $store, list<ResponseMutatorInterface> $mutators = [])`. `veto(ResponseInterface)` marks a response as not to be stored; `VETO_HEADER` and `STATUS_HEADER` name the headers it uses. |
| `CachePolicy` | Decides whether a request may use the cache and under which key: `ticketFor(ServerRequestInterface): ?CacheTicket`. `USER_ATTRIBUTE` is the request attribute that marks a logged-in user. |
| `CacheTicket` | The key and per-path TTL one request reads and writes. |
| `PageStore` | Reads and writes pages in a PSR-16 cache: `fetch(CacheTicket)`, `save(CacheTicket, ResponseInterface)`, `stamped(CacheTicket)` for the store's current generation, and `clear()`, which empties it and moves the generation on. |
| `CacheClearMiddleware` | Answers the CMS's signed clear at `/api/cache`: `(?ClearVerifier $verifier, PageStore $store, array<string, CacheInterface> $caches = [])`. |
| `CacheResult` | `Hit`, `Miss` or `Bypass`, handed to every mutator. |
| `ResponseMutatorInterface` | The extension point for per-request changes after storage. |
| `SystemClock` | The PSR-20 wall clock `PageStore` uses by default. |
| `Contenir\PageCache\Repository\LayeredFileRepository` | From contenir-page-cache: the admin's file laid over the site's defaults (a `CacheControlRepositoryInterface`). |
| `PageCacheMiddlewareFactory`, `CachePolicyFactory`, `PageStoreFactory`, `CacheClearMiddlewareFactory` | Build the services above from `config['pagecache']`; invalid configuration throws `RuntimeException`. `CachePolicyFactory::DEFAULT_FILE` is the admin file's path relative to the working directory. |

`ActiveOptions`, `CacheKeyGenerator`, `ClearKeys`, `ClearOptions`,
`ClearSetting`, `PageCacheConfig`, `PageCodec`, `PageGeneration`,
`SessionInspector` and `StoredResponse` are internal.

## Development

The QA toolchain is [contenir/contenir-qa-tools](https://github.com/contenir/contenir-qa-tools).
[Mago](https://mago.carthage.software/) is a standalone binary, installed
separately (`brew install mago`).

```bash
composer check             # everything below
composer cs-check          # mago format --check && mago lint
composer static-analysis   # mago analyze
composer test              # unit suite: in-memory caches, clocks, sessions and containers, no I/O
composer test-integration  # integration suite: real admin files and a laminas-cache Filesystem storage
composer test-coverage     # both suites, clover.xml for Codecov
composer mutation-test     # Infection mutation testing over both suites
```

## License

MIT. See [LICENSE](LICENSE).
