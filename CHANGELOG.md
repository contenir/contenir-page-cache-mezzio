# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
adheres to [Semantic Versioning](https://semver.org/).

## [2.0.0-RC2] - 2026-10-27

### Added

- `CacheClearMiddleware`, answering the CMS's signed request to clear the site's caches at
  `/api/cache` (contenir/contenir-page-cache's clear protocol): it clears the page store and each
  cache `pagecache.clear.caches` lists, through their own stores, so any backend works and caches
  sharing a directory keep apart. Configured by `pagecache.clear`; the keys default to the ones
  previews use. Requires contenir/contenir-site-token 0.1.
- `PageStore::stamped()` and `PageStore::clear()`: the store keeps a generation, which a clear
  moves on, and every page is stored under the generation its request began in, so a page rendered
  from data a clear has since replaced is never served.

### Changed

- Pages are stored under their ticket's key and the store's generation, so entries stored before
  the upgrade are no longer read; they are misses until the TTL or a clear removes them.

## [2.0.0-RC1] - 2026-10-07

The first 2.0 pre-release, published as
`contenir/contenir-page-cache-mezzio`. The API keeps its shape apart from the
namespace, which moves from `Contenir\Cache\Mezzio\` to
`Contenir\PageCache\Mezzio\`. The major version also aligns the package with
the other Contenir 2.x packages: the same supported PHP versions, the shared
QA toolchain and CI. See [UPGRADE-2.0.md](UPGRADE-2.0.md) and
[UPGRADE-page-cache.md](UPGRADE-page-cache.md).

The 2.0.0 and 2.1.0 tags published on 2026-10-05 as
`contenir/contenir-cache-mezzio` were withdrawn and are folded into this
release.

### Changed

- Renamed from `contenir/cache-mezzio` (and the short-lived
  `contenir/contenir-cache-mezzio`) to `contenir/contenir-page-cache-mezzio`,
  and the namespace from `Contenir\Cache\Mezzio\` to
  `Contenir\PageCache\Mezzio\`, following the core package's rename to
  `contenir/contenir-page-cache`. No `class_alias` shims are shipped.
- Declares `conflict` (any version) with `contenir/cache-mezzio` and
  `contenir/contenir-cache-mezzio` instead of replacing them, because the
  namespace change means it cannot stand in for either.
- Requires `contenir/contenir-page-cache` `^2.0` and
  `contenir/contenir-config` `^2.1` (were `contenir/cache` and
  `contenir/config`).
- Requires PHP 8.3, 8.4 or 8.5 (`~8.3.0 || ~8.4.0 || ~8.5.0`; was `^8.3`).
- `LICENSE` names Contenir as the copyright holder, in line with the other
  Contenir packages.
- The local path and VCS repository entries are gone from `composer.json`;
  everything resolves from Packagist.
- The detailed documentation moved from the README into `docs/`.

### Fixed

- A numeric-string `pagecache.ttl` (such as `'600'` from an environment
  variable) was ignored and the 300-second default used. It is now read as
  whole seconds, as the per-path `ttl` option already was.

### Added

- Continuous integration through contenir-qa-tools on PHP 8.3, 8.4 and 8.5
  against lowest, locked and latest dependencies, with coverage reported to
  Codecov and Infection mutation testing at MSI 100%. `composer.lock` is
  committed.
- 100% line and branch coverage across the unit and integration suites.

### Removed

- `Repository\LayeredFileRepository`. It moved to contenir-page-cache as
  `Contenir\PageCache\Repository\LayeredFileRepository`, unchanged, so the
  Laminas MVC adapter can share it. `CachePolicyFactory` uses it from there.
- Dead code: `ActiveOptions::resolve()` no longer spreads `DEFAULTS` into the
  options (every reader already falls back to the same values), and
  `PageStore::save()` no longer strips the veto header from stored responses
  (a vetoed response is never stored). Behaviour is unchanged.
- The package's own `quality.yml` workflow, replaced by the shared one.

## [0.1.0] - 2026-10-05

- Initial release: `PageCacheMiddleware`, `CachePolicy`, `PageStore`, their
  factories, `LayeredFileRepository` and `ConfigProvider`. A full-page cache
  controlled by the admin's Page Cache screen, with the MVC adapter's
  `cache_with_*` / `make_id_with_*` options and route overrides, response
  vetoes and mutators, and PSR-16 or laminas-cache storage.
