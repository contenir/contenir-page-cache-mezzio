<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio\Tests\Unit;

use Contenir\PageCache\Mezzio\CacheTicket;
use Contenir\PageCache\Mezzio\PageCodec;
use Contenir\PageCache\Mezzio\PageStore;
use Contenir\PageCache\Mezzio\StoredResponse;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Cache\FailingCache;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Cache\InMemoryCache;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Clock\FrozenClock;
use Laminas\Diactoros\CallbackStream;
use Laminas\Diactoros\Response;
use Laminas\Diactoros\Response\HtmlResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

use function array_key_exists;

#[Group('unit')]
#[Group('store')]
final class PageStoreTest extends TestCase
{
    private InMemoryCache $cache;

    private FrozenClock $clock;

    /**
     * @return array<string, array{ResponseInterface, bool}>
     */
    public static function cacheableProvider(): array
    {
        return [
            '200 OK'                       => [new HtmlResponse('x', 200), true],
            '203 non-authoritative'        => [new HtmlResponse('x', 203), true],
            '301 moved permanently'        => [new Response\RedirectResponse('/new', 301), true],
            '308 permanent redirect'       => [new Response\RedirectResponse('/new', 308), true],
            '302 found'                    => [new Response\RedirectResponse('/new', 302), false],
            '204 no content'               => [new Response\EmptyResponse(204), false],
            '404 not found'                => [new HtmlResponse('x', 404), false],
            '500 server error'             => [new HtmlResponse('x', 500), false],
            'vetoed'                       => [new HtmlResponse('x', 200, ['X-Page-Cache' => 'off']), false],
            'sets a cookie'                => [new HtmlResponse('x', 200, ['Set-Cookie' => 'PHPSESSID=abc']), false],
            'no-store'                     => [new HtmlResponse('x', 200, ['Cache-Control' => 'no-store']), false],
            'private'                      => [new HtmlResponse('x', 200, [
                'Cache-Control' => 'max-age=0, private',
            ]), false],
            'no-store in mixed case'       => [new HtmlResponse('x', 200, ['Cache-Control' => 'No-Store']), false],
            'public max-age'               => [new HtmlResponse('x', 200, [
                'Cache-Control' => 'public, max-age=60',
            ]), true],
            'no-cache is still storable'   => [new HtmlResponse('x', 200, ['Cache-Control' => 'no-cache']), true],
            'body that cannot be replayed' => [new Response(new CallbackStream(static fn(): string => 'x')), false],
        ];
    }

    /**
     * @return array<string, array{null|int, int}>
     */
    public static function perItemTtlProvider(): array
    {
        return [
            'the ttl option for the path' => [60, 60],
            'the configured ttl'          => [null, 600],
        ];
    }

    #[Test]
    public function aFailingCacheBackendIsAMiss(): void
    {
        static::assertNull((new PageStore(new FailingCache(), $this->clock))->fetch(new CacheTicket('page')));
    }

    #[Test]
    public function aFailingCacheBackendStoresNothing(): void
    {
        static::assertFalse((new PageStore(new FailingCache(), $this->clock))->save(
            new CacheTicket('page'),
            new HtmlResponse('x'),
        ));
    }

    #[Test]
    public function aHitCarriesItsAgeInSeconds(): void
    {
        $store = $this->store();
        $store->save(new CacheTicket('page'), new HtmlResponse('x'));
        $this->clock->advance(90);

        static::assertSame('90', $store->fetch(new CacheTicket('page'))?->getHeaderLine('Age'));
    }

    #[Test]
    public function aHitFetchedAsItIsStoredHasAnAgeOfZero(): void
    {
        $store = $this->store();
        $store->save(new CacheTicket('page'), new HtmlResponse('x'));

        static::assertSame('0', $store->fetch(new CacheTicket('page'))?->getHeaderLine('Age'));
    }

    #[Test]
    public function aHitIsMarkedAsAHit(): void
    {
        $store = $this->store();
        $store->save(new CacheTicket('page'), new HtmlResponse('x'));

        static::assertSame('HIT', $store->fetch(new CacheTicket('page'))?->getHeaderLine('X-PK-Cache'));
    }

    #[Test]
    public function aHitKeepsAnAgeHeaderThePageAlreadyHad(): void
    {
        $store = $this->store();
        $store->save(new CacheTicket('page'), new HtmlResponse('x', 200, ['Age' => '5']));
        $this->clock->advance(90);

        static::assertSame('5', $store->fetch(new CacheTicket('page'))?->getHeaderLine('Age'));
    }

    #[Test]
    public function aHitKeepsTheCacheControlThePageWasStoredWith(): void
    {
        $store = $this->store();
        $store->save(new CacheTicket('page'), new HtmlResponse('x', 200, ['Cache-Control' => 'public, max-age=30']));

        static::assertSame(
            'public, max-age=30',
            $store->fetch(new CacheTicket('page'))?->getHeaderLine('Cache-Control'),
        );
    }

    #[Test]
    public function aHitStoredByAClockAheadOfOursHasAnAgeOfZero(): void
    {
        $this->store()->save(new CacheTicket('page'), new HtmlResponse('x'));
        $store = new PageStore($this->cache, new FrozenClock($this->clock->now()->modify('-30 seconds')));

        static::assertSame('0', $store->fetch(new CacheTicket('page'))?->getHeaderLine('Age'));
    }

    #[Test]
    public function aHitWithoutCacheControlGetsTheConfiguredOne(): void
    {
        $store = new PageStore($this->cache, $this->clock, cacheControl: 'public, max-age=60');
        $store->save(new CacheTicket('page'), new HtmlResponse('x'));

        static::assertSame(
            'public, max-age=60',
            $store->fetch(new CacheTicket('page'))?->getHeaderLine('Cache-Control'),
        );
    }

    #[Test]
    public function anEntryThePageCacheDidNotWriteIsAMiss(): void
    {
        $this->cache->items['page'] = ['status' => 200, 'headers' => [], 'body' => 'legacy array entry'];

        static::assertNull($this->store()->fetch(new CacheTicket('page')));
    }

    #[Test]
    public function aPageWhoseHeadersCannotBeEncodedIsNotStored(): void
    {
        static::assertFalse($this->store()->save(new CacheTicket('page'), new HtmlResponse('x', 200, [
            'X-Name' => "caf\xe9",
        ])));
    }

    #[Test]
    public function aStoredEntryRoundTripsThroughTheCodec(): void
    {
        $this->store()->save(new CacheTicket('page'), new HtmlResponse('x'));

        static::assertInstanceOf(StoredResponse::class, PageCodec::decode($this->cache->items['page']));
    }

    #[Test]
    public function aStoredPageIsFetchedWithItsStatusAndBody(): void
    {
        $store = $this->store();
        $store->save(new CacheTicket('page'), new HtmlResponse('<p>Work</p>', 203));

        $hit = $store->fetch(new CacheTicket('page'));

        static::assertSame([203, '<p>Work</p>'], [$hit?->getStatusCode(), (string) $hit?->getBody()]);
    }

    #[Test]
    public function fetchingAPageThatWasNeverStoredIsAMiss(): void
    {
        static::assertNull($this->store()->fetch(new CacheTicket('page')));
    }

    #[Test]
    public function noTtlIsPassedSoACacheThatRefusesPerItemTtlsStillStores(): void
    {
        $stored = (new PageStore($this->cache, $this->clock, ttl: 600))->save(
            new CacheTicket('page', ttl: 60),
            new HtmlResponse('x'),
        );

        static::assertSame([true, true, null], [
            $stored,
            array_key_exists('page', $this->cache->ttls),
            $this->cache->ttls['page'],
        ]);
    }

    #[Test]
    #[DataProvider('cacheableProvider')]
    public function onlyCacheableResponsesAreStored(ResponseInterface $response, bool $expected): void
    {
        static::assertSame($expected, $this->store()->save(new CacheTicket('page'), $response));
    }

    #[Test]
    #[DataProvider('perItemTtlProvider')]
    public function perItemTtlsArePassedOnlyWhenSwitchedOn(?int $optionTtl, int $expected): void
    {
        $cache = new InMemoryCache(honoursTtl: true);

        (new PageStore($cache, $this->clock, perItemTtl: true, ttl: 600))->save(
            new CacheTicket('page', ttl: $optionTtl),
            new HtmlResponse('x'),
        );

        static::assertSame($expected, $cache->ttls['page']);
    }

    #[Test]
    public function perItemTtlsDefaultToFiveMinutes(): void
    {
        $cache = new InMemoryCache(honoursTtl: true);

        (new PageStore($cache, $this->clock, perItemTtl: true))->save(new CacheTicket('page'), new HtmlResponse('x'));

        static::assertSame(300, $cache->ttls['page']);
    }

    #[Test]
    public function thePageIsStampedWithTheTimeItWasStored(): void
    {
        $this->store()->save(new CacheTicket('page'), new HtmlResponse('x'));

        static::assertSame(
            $this->clock->now()->getTimestamp(),
            PageCodec::decode($this->cache->items['page'])?->storedAt,
        );
    }

    #[Test]
    public function theVetoAndStatusHeadersAreNotStored(): void
    {
        $this->store()->save(new CacheTicket('page'), new HtmlResponse('x', 200, ['X-PK-Cache' => 'MISS']));

        static::assertArrayNotHasKey('X-PK-Cache', PageCodec::decode($this->cache->items['page'])->headers ?? []);
    }

    protected function setUp(): void
    {
        $this->cache = new InMemoryCache();
        $this->clock = new FrozenClock();
    }

    private function store(): PageStore
    {
        return new PageStore($this->cache, $this->clock);
    }
}
