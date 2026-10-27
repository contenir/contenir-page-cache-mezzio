<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio\Tests\Unit;

use Contenir\PageCache\CacheControl;
use Contenir\PageCache\Mezzio\CachePolicy;
use Contenir\PageCache\Mezzio\CacheTicket;
use Contenir\PageCache\Mezzio\PageCacheMiddleware;
use Contenir\PageCache\Mezzio\PageGeneration;
use Contenir\PageCache\Mezzio\PageStore;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Cache\FailingCache;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Cache\InMemoryCache;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Cache\RefusingCache;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Clock\FrozenClock;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Handler\ClearingHandler;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Handler\CountingHandler;
use Contenir\PageCache\Mezzio\Tests\Trait\ServerRequestTrait;
use Contenir\PageCache\Repository\InMemoryRepository;
use Laminas\Diactoros\Response\HtmlResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A page store's generation, which a clear moves on so that a page rendered before the clear is
 * never served after it.
 */
#[Group('unit')]
#[Group('cache')]
final class PageGenerationTest extends TestCase
{
    use ServerRequestTrait;

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function halfClearProvider(): array
    {
        return [
            'the store would not empty'         => [false, true],
            'the generation could not be moved' => [true, false],
        ];
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unreadableGenerationProvider(): array
    {
        return [
            'nothing stored' => [null],
            'text stored'    => ['3'],
            'a float stored' => [3.0],
        ];
    }

    #[Test]
    public function aClearEmptiesTheStoreAndMovesTheGenerationOn(): void
    {
        $cache                             = new InMemoryCache();
        $cache->items[PageGeneration::KEY] = 4;
        $cache->items['pagecache_a_4']     = 'page';

        static::assertSame(
            [true, [PageGeneration::KEY => 5]],
            [(new PageGeneration($cache))->clear(), $cache->items],
        );
    }

    #[Test]
    public function aPageRenderedWhileTheStoreIsClearedIsNotServedAfterward(): void
    {
        $cache      = new InMemoryCache();
        $store      = new PageStore($cache, new FrozenClock());
        $middleware = new PageCacheMiddleware(new CachePolicy(new InMemoryRepository(CacheControl::enabled())), $store);
        $middleware->process($this->request(), new ClearingHandler($store, new HtmlResponse('old menu')));

        $fresh = new CountingHandler(new HtmlResponse('new menu'));
        $page  = $middleware->process($this->request(), $fresh);

        static::assertSame([1, 'new menu'], [$fresh->calls, (string) $page->getBody()]);
    }

    #[Test]
    #[DataProvider('halfClearProvider')]
    public function saysAClearDidNotGoThroughUnlessBothHalvesDid(bool $clears, bool $sets): void
    {
        static::assertFalse((new PageGeneration(new RefusingCache($clears, $sets)))->clear());
    }

    #[Test]
    public function stampsATicketWithTheStoresGeneration(): void
    {
        $cache = new InMemoryCache();
        $store = new PageStore($cache, new FrozenClock());
        $first = $store->stamped(new CacheTicket('pagecache_a', 60));
        $store->clear();

        static::assertEquals(
            [new CacheTicket('pagecache_a_0', 60), new CacheTicket('pagecache_a_1', 60), 1],
            [$first, $store->stamped(new CacheTicket('pagecache_a', 60)), $store->generation()],
        );
    }

    #[Test]
    #[DataProvider('unreadableGenerationProvider')]
    public function startsAtNoughtUntilAWholeNumberIsStored(mixed $stored): void
    {
        $cache = new InMemoryCache();
        if (null !== $stored) {
            $cache->items[PageGeneration::KEY] = $stored;
        }

        static::assertSame(0, (new PageGeneration($cache))->current());
    }

    #[Test]
    public function startsAtNoughtWhenTheStoreCannotBeRead(): void
    {
        static::assertSame([0, false], [
            (new PageGeneration(new FailingCache()))->current(),
            (new PageGeneration(new FailingCache()))->clear(),
        ]);
    }
}
