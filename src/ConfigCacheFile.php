<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio;

use function dirname;
use function function_exists;
use function is_file;
use function is_writable;
use function opcache_invalidate;
use function restore_error_handler;
use function set_error_handler;
use function unlink;

/**
 * The file laminas-config-aggregator caches the site's merged configuration in, which a clear
 * removes so the next request merges it afresh: the CMS writes settings to config/autoload, and
 * a site that caches its configuration otherwise never reads them. Its path is the one the site
 * hands the aggregator, relative to the working directory as the aggregator reads it.
 *
 * @api
 */
final readonly class ConfigCacheFile
{
    /** The config key the Mezzio skeleton keeps the cache file's path under. */
    public const string PATH_KEY = 'config_cache_path';

    public function __construct(
        public string $path,
    ) {}

    /**
     * Drops the file's compiled copy, quietly where opcache.restrict_api forbids the call, so a
     * clear never fails over it.
     */
    private static function invalidateOpcache(string $path): void
    {
        if (! function_exists('opcache_invalidate')) {
            return;
        }

        set_error_handler(static fn(): bool => true);

        try {
            opcache_invalidate($path, force: true);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Removes the file, and its compiled copy from OPcache, so the next request neither finds the
     * old file nor runs the old one in place of the file merged next; false when the file is there
     * but cannot be removed.
     */
    public function clear(): bool
    {
        if (! is_file($this->path)) {
            return true;
        }

        if (! is_writable(dirname($this->path)) || ! unlink($this->path)) {
            return false;
        }

        self::invalidateOpcache($this->path);

        return true;
    }
}
