<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio\Tests\Unit;

use Contenir\PageCache\Clear\ClearSigner;
use Contenir\PageCache\Mezzio\CacheClearMiddleware;
use Contenir\PageCache\Mezzio\CacheClearMiddlewareFactory;
use Contenir\PageCache\Mezzio\SystemClock;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Cache\InMemoryCache;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Container\InMemoryContainer;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Handler\CountingHandler;
use Contenir\SiteToken\SiteKeys;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

use function array_keys;

/**
 * Building the cache clear endpoint from the site's configuration.
 */
#[Group('unit')]
#[Group('cache')]
final class CacheClearMiddlewareFactoryTest extends TestCase
{
    private const array KEYS = [
        'issuer'    => 'https://admin.example.test',
        'audience'  => 'https://www.example.test',
        'secrets'   => ['2026-10' => 'a-secret-of-at-least-thirty-two-bytes!!'],
        'sign_with' => '2026-10',
    ];

    #[Test]
    public function buildsAnEndpointThatPassesEverythingOnWhenNoClearIsConfigured(): void
    {
        $next = new CountingHandler(new EmptyResponse(404));
        $this->middleware([])->process($this->post(), $next);

        static::assertSame(1, $next->calls);
    }

    #[Test]
    public function clearsThePagesAndEachListedCache(): void
    {
        $routes        = new InMemoryCache();
        $routes->items = ['routes' => 'cached'];

        $response = $this->middleware(
            ['caches' => ['workflow'], 'replay' => ['cache' => 'shared']],
            ['workflow' => $routes, 'shared' => new InMemoryCache(honoursTtl: true)],
        )
            ->process($this->post($this->bearer()), new CountingHandler(new EmptyResponse(404)));

        static::assertSame([204, []], [$response->getStatusCode(), $routes->items]);
    }

    #[Test]
    public function refusesAListedCacheThatIsNoCache(): void
    {
        $this->expectExceptionObject(new RuntimeException(
            'contenir/contenir-page-cache-mezzio: the cache service "workflow" listed in pagecache.clear.caches is a stdClass,'
                . ' not a Psr\SimpleCache\CacheInterface or a Laminas\Cache\Storage\StorageInterface.',
        ));

        $this->middleware(['caches' => ['workflow']], ['workflow' => new stdClass()]);
    }

    #[Test]
    public function remembersAcceptedClearsInTheReplayCacheItNames(): void
    {
        $replay     = new InMemoryCache(honoursTtl: true);
        $middleware = $this->middleware(['replay' => ['cache' => 'shared']], ['shared' => $replay]);
        $middleware->process($this->post($this->bearer()), new CountingHandler(new EmptyResponse(404)));

        static::assertSame(['contenir-cache-0123456789abcdef0123456789abcdef'], array_keys($replay->items));
    }

    private function bearer(): string
    {
        $keys = new SiteKeys(
            self::KEYS['issuer'],
            self::KEYS['audience'],
            self::KEYS['secrets'],
            self::KEYS['sign_with'],
        );

        return 'Bearer '
            . (new ClearSigner(
                $keys,
                new SystemClock(),
                static fn(): string => '0123456789abcdef0123456789abcdef',
            ))->sign('12');
    }

    /**
     * @param array<string, mixed>|array{} $clear
     * @param array<string, object>        $services
     */
    private function middleware(array $clear, array $services = []): CacheClearMiddleware
    {
        $pagecache = ['cache' => 'pages'] + ([] === $clear ? [] : ['clear' => $clear]);

        return (new CacheClearMiddlewareFactory())(new InMemoryContainer([
            'config' => ['pagecache' => $pagecache, 'contenir_preview' => self::KEYS],
            'pages'  => new InMemoryCache(),
        ] + $services));
    }

    private function post(?string $authorization = null): ServerRequest
    {
        $request = new ServerRequest(
            uri: 'https://www.example.test/api/cache',
            method: 'POST',
        );

        return null === $authorization ? $request : $request->withHeader('Authorization', $authorization);
    }
}
