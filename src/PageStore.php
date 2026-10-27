<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio;

use JsonException;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheException;
use Psr\SimpleCache\CacheInterface;

use function in_array;
use function max;
use function preg_match;

/**
 * Reads and writes pages in a PSR-16 cache.
 *
 * No TTL is passed with set() unless per-item TTLs are switched on: the
 * laminas-cache Filesystem adapter cannot honour one, and laminas'
 * SimpleCacheDecorator then refuses the write (set() returns false), so a page
 * cache that passes TTLs to it never caches anything. Give such a storage its
 * TTL at storage level instead; the factory does this for laminas storages.
 *
 * A cache backend that fails (an unwritable directory, an unreachable server)
 * degrades to serving uncached pages rather than failing the request.
 *
 * @api
 */
final readonly class PageStore
{
    private const array CACHEABLE_STATUSES = [200, 203, 301, 308];

    private PageGeneration $generation;

    /**
     * @param bool $perItemTtl Pass a TTL with every set(). Only for caches that
     *     honour per-item TTLs.
     * @param int $ttl The TTL passed per item when $perItemTtl is on and no
     *     `ttl` option is set for the path.
     * @param string $cacheControl The Cache-Control a hit is sent with when the
     *     stored page has none.
     */
    public function __construct(
        private CacheInterface $cache,
        private ClockInterface $clock = new SystemClock(),
        private bool $perItemTtl = false,
        private int $ttl = 300,
        private string $cacheControl = 'no-cache',
    ) {
        $this->generation = new PageGeneration($cache);
    }

    /**
     * Empties the store and moves its generation on. True when both went
     * through.
     */
    public function clear(): bool
    {
        return $this->generation->clear();
    }

    /**
     * The stored page, ready to send (with `Age`, a Cache-Control when it had
     * none, and `X-PK-Cache: HIT`), or null on a miss.
     */
    public function fetch(CacheTicket $ticket): ?ResponseInterface
    {
        try {
            $hit = PageCodec::decode($this->cache->get($ticket->key));
        } catch (CacheException) {
            return null;
        }

        if (null === $hit) {
            return null;
        }

        $response = $hit->toResponse();
        if (! $response->hasHeader('Age')) {
            $age      = max(0, $this->clock->now()->getTimestamp() - $hit->storedAt);
            $response = $response->withHeader('Age', (string) $age);
        }

        if (! $response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', $this->cacheControl);
        }

        return $response->withHeader(PageCacheMiddleware::STATUS_HEADER, 'HIT');
    }

    /**
     * The store's generation: 0 until it is first cleared.
     */
    public function generation(): int
    {
        return $this->generation->current();
    }

    /**
     * Stores the response when it is cacheable; true when it was stored.
     *
     * Cacheable means: status 200, 203, 301 or 308; a replayable body; no
     * Set-Cookie; no `no-store` or `private` in Cache-Control; and no veto
     * header.
     */
    public function save(CacheTicket $ticket, ResponseInterface $response): bool
    {
        if (! $this->isCacheable($response)) {
            return false;
        }

        $stored = StoredResponse::fromResponse(
            $response,
            $this->clock->now()->getTimestamp(),
            [PageCacheMiddleware::STATUS_HEADER],
        );

        try {
            return $this->cache->set(
                $ticket->key,
                PageCodec::encode($stored),
                $this->perItemTtl ? $ticket->ttl ?? $this->ttl : null,
            );
        } catch (CacheException|JsonException) {
            return false;
        }
    }

    /**
     * $ticket for the store's current generation, which a request takes once,
     * before its page is rendered, and fetches and saves with, so a page
     * rendered before a clear and saved after it is stored where no later
     * request looks.
     */
    public function stamped(CacheTicket $ticket): CacheTicket
    {
        return new CacheTicket("{$ticket->key}_{$this->generation->current()}", $ticket->ttl);
    }

    private function isCacheable(ResponseInterface $response): bool
    {
        return (
            in_array($response->getStatusCode(), self::CACHEABLE_STATUSES, strict: true)
                && ! $response->hasHeader(PageCacheMiddleware::VETO_HEADER)
                && ! $response->hasHeader('Set-Cookie')
                && 1 !== preg_match('/(^|[\s,])(no-store|private)\b/i', $response->getHeaderLine('Cache-Control'))
                && $response->getBody()->isSeekable()
        );
    }
}
