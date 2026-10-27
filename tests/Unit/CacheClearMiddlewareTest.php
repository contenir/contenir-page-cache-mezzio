<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio\Tests\Unit;

use Contenir\PageCache\Clear\ClearSigner;
use Contenir\PageCache\Clear\ClearVerifier;
use Contenir\PageCache\Mezzio\CacheClearMiddleware;
use Contenir\PageCache\Mezzio\PageGeneration;
use Contenir\PageCache\Mezzio\PageStore;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Cache\FailingCache;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Cache\InMemoryCache;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Cache\RefusingCache;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Clock\FrozenClock;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Handler\CountingHandler;
use Contenir\SiteToken\Replay\InMemoryReplayGuard;
use Contenir\SiteToken\SiteKeys;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\SimpleCache\CacheInterface;

/**
 * The CMS asking the site to clear its caches at /api/cache, and everything else passing by.
 */
#[Group('unit')]
#[Group('cache')]
final class CacheClearMiddlewareTest extends TestCase
{
    private InMemoryCache $pages;

    private InMemoryCache $workflow;

    private FrozenClock $clock;

    private CountingHandler $next;

    private static function keys(): SiteKeys
    {
        return new SiteKeys(
            'https://admin.example.test',
            'https://www.example.test',
            ['2026-10' => 'a-secret-of-at-least-thirty-two-bytes!!'],
            '2026-10',
        );
    }

    #[Test]
    public function answersOnlyAPost(): void
    {
        $response = $this->middleware()->process($this->post(method: 'GET'), $this->next);

        static::assertSame(
            [405, 'POST', 'no-store', 0],
            [
                $response->getStatusCode(),
                $response->getHeaderLine('Allow'),
                $response->getHeaderLine('Cache-Control'),
                $this->next->calls,
            ],
        );
    }

    #[Test]
    public function answersOnlyAPostInCapitals(): void
    {
        static::assertSame(405, $this->middleware()->process($this->post(
            method: 'post',
            authorization: $this->bearer(),
        ), $this->next)->getStatusCode());
    }

    #[Test]
    public function clearsThePagesAndEveryListedCache(): void
    {
        $response = $this->clear();

        static::assertSame(
            [204, 'no-store', [PageGeneration::KEY => 1], []],
            [
                $response->getStatusCode(),
                $response->getHeaderLine('Cache-Control'),
                $this->pages->items,
                $this->workflow->items,
            ],
        );
    }

    #[Test]
    public function namesEachCacheItCouldNotClearAndClearsTheRest(): void
    {
        $response = $this->clear([
            'broken'   => new FailingCache(),
            'workflow' => $this->workflow,
            'stuck'    => new RefusingCache(),
        ]);

        static::assertSame(
            [500, 'Could not clear: broken, stuck.', []],
            [$response->getStatusCode(), (string) $response->getBody(), $this->workflow->items],
        );
    }

    #[Test]
    public function namesThePagesWhenTheirStoreCannotBeCleared(): void
    {
        $response = $this->middleware(new RefusingCache())->process(
            $this->post(authorization: $this->bearer()),
            $this->next,
        );

        static::assertSame(['Could not clear: pages.', []], [(string) $response->getBody(), $this->workflow->items]);
    }

    #[Test]
    public function passesEveryOtherAddressOn(): void
    {
        $this->middleware()->process($this->post('/work'), $this->next);

        static::assertSame(1, $this->next->calls);
    }

    #[Test]
    public function passesTheEndpointOnWhenTheSiteConfiguresNoClear(): void
    {
        (new CacheClearMiddleware(null, new PageStore($this->pages, $this->clock)))->process(
            $this->post(),
            $this->next,
        );

        static::assertSame(1, $this->next->calls);
    }

    #[Test]
    public function refusesAClearWithoutATokenItTrusts(): void
    {
        $response = $this->middleware()->process($this->post(authorization: 'Bearer not.a.token'), $this->next);

        static::assertSame(
            [400, 'This is not a token.', 'no-store', ['pagecache_a_0' => 'page']],
            [
                $response->getStatusCode(),
                (string) $response->getBody(),
                $response->getHeaderLine('Cache-Control'),
                $this->pages->items,
            ],
        );
    }

    protected function setUp(): void
    {
        $this->pages           = new InMemoryCache();
        $this->pages->items    = ['pagecache_a_0' => 'page'];
        $this->workflow        = new InMemoryCache();
        $this->workflow->items = ['routes' => 'cached'];
        $this->clock           = new FrozenClock();
        $this->next            = new CountingHandler(new EmptyResponse(404));
    }

    private function bearer(): string
    {
        return 'Bearer ' . (new ClearSigner(self::keys(), $this->clock))->sign('12');
    }

    /**
     * @param array<string, CacheInterface>|null $caches
     */
    private function clear(?array $caches = null): ResponseInterface
    {
        return $this->middleware(caches: $caches)->process($this->post(authorization: $this->bearer()), $this->next);
    }

    /**
     * @param array<string, CacheInterface>|null $caches
     */
    private function middleware(?CacheInterface $pages = null, ?array $caches = null): CacheClearMiddleware
    {
        return new CacheClearMiddleware(
            new ClearVerifier(self::keys(), $this->clock, new InMemoryReplayGuard($this->clock)),
            new PageStore($pages ?? $this->pages, $this->clock),
            $caches ?? ['workflow' => $this->workflow],
        );
    }

    private function post(
        string $path = '/api/cache',
        string $method = 'POST',
        ?string $authorization = null,
    ): ServerRequest {
        $request = new ServerRequest(
            uri: "https://www.example.test{$path}",
            method: $method,
        );

        return null === $authorization ? $request : $request->withHeader('Authorization', $authorization);
    }
}
