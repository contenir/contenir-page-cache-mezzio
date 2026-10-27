# Storage and purging

Which caches the middleware can use, why it never passes per-item TTLs to laminas-cache, and how the admin purges pages.

## Storage

`pagecache.cache` names a container service:

- a **PSR-16 cache** is used as it is. No TTL is passed per item unless
  `per_item_ttl` is on, so the cache's own default TTL applies.
- a **laminas-cache `StorageInterface`** (`^3.0 || ^4.0`, suggested, not
  required) has its storage-level TTL set to `ttl` and is wrapped in
  laminas' `SimpleCacheDecorator`. Per-item TTLs are never passed to it. The
  decorator requires the storage to have the `serializer` plugin when its
  adapter cannot store arrays (the Filesystem adapter in laminas-cache 3).

The setup the admin's purge button understands is a laminas-cache Filesystem
storage declared under `caches` (laminas-cache's abstract factory) in
`config/autoload/cache.global.php`, with its `cache_dir` under `data/cache`:

```php
// config/autoload/cache.global.php
return [
    'caches' => [
        'cache.pages' => [
            'adapter' => 'filesystem',
            'options' => [
                'cache_dir' => 'data/cache/pages',
                'namespace' => 'pages',
            ],
            'plugins' => [['name' => 'serializer']],
        ],
    ],
];

// config/autoload/pagecache.global.php
return ['pagecache' => ['cache' => 'cache.pages']];
```

### The TTL pitfall

laminas-cache 3's Filesystem adapter has no per-item TTL. Its
`SimpleCacheDecorator` reports that by returning `false` from any `set()` that
carries a TTL, without writing anything, so a page cache that passes TTLs to
it never caches a single page, silently. This package therefore never passes a
per-item TTL to a laminas storage, sets `ttl` on the storage itself instead,
and passes per-item TTLs to a PSR-16 cache only when `per_item_ttl` is on. The
integration suite runs against a real Filesystem storage to prove it.


## Purging

The CMS clears a site's caches by posting a signed request to the site's
`/api/cache`, which `CacheClearMiddleware` answers (see the README's
"Clearing from the CMS"). The site clears its own stores through their own
APIs, so any backend works, Redis included, and caches sharing a directory
keep apart by namespace. The page store's generation moves on with each
clear, so a page rendered from the old data while the clear lands is stored
where no later request looks.

Sites that don't pipe the middleware yet are purged the old way, which only
works for a laminas-cache Filesystem storage under `data/cache`:

- **Clear cache** (the admin's `ClearCache` registrar) empties every file
  under the site's `data/cache`; the middleware treats the missing entries as
  misses.
- **Page Cache → Clear cache** (the `PurgeCacheHandler` operation) builds the
  Filesystem storage `pagecache.cache` names from the site's config and flushes
  it.

[Back to the README](../README.md)
