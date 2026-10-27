<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio;

use Psr\SimpleCache\CacheException;
use Psr\SimpleCache\CacheInterface;

use function is_int;

/**
 * A page store's generation, kept in the store itself, which a clear moves on. Every page is
 * stored under its ticket's key and the generation its request began in, so a page rendered from
 * data a clear has since replaced is stored where no later request looks.
 *
 * @internal
 */
final readonly class PageGeneration
{
    /** The key holding the generation. */
    public const string KEY = 'pagecache_generation';

    public function __construct(
        private CacheInterface $cache,
    ) {}

    /**
     * Empties the store, then sets the generation one on from what it was. True when both went
     * through.
     */
    public function clear(): bool
    {
        $next = $this->current() + 1;

        try {
            $cleared = $this->cache->clear();

            return $this->cache->set(self::KEY, $next) && $cleared;
        } catch (CacheException) {
            return false;
        }
    }

    /**
     * The generation: 0 until the store is first cleared, or when it can't be read.
     */
    public function current(): int
    {
        try {
            /** @var mixed $generation */
            $generation = $this->cache->get(self::KEY);
        } catch (CacheException) {
            return 0;
        }

        return is_int($generation) ? $generation : 0;
    }
}
