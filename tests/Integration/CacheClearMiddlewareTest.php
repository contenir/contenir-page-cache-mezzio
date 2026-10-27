<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio\Tests\Integration;

use Contenir\PageCache\Clear\ClearSigner;
use Contenir\PageCache\Mezzio\CacheClearMiddlewareFactory;
use Contenir\PageCache\Mezzio\SystemClock;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Container\InMemoryContainer;
use Contenir\PageCache\Mezzio\Tests\TestAsset\Handler\CountingHandler;
use Contenir\PageCache\Mezzio\Tests\Trait\TemporaryDirectoryTrait;
use Contenir\SiteToken\SiteKeys;
use Laminas\Cache\Storage\Adapter\Filesystem;
use Laminas\Cache\Storage\Plugin\Serializer;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\ServerRequest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function glob;

/**
 * Clearing the laminas-cache Filesystem storages most Contenir sites keep under one directory:
 * the page store and each listed cache are emptied, and a cache the site didn't list, sharing the
 * directory, is left as it was.
 */
#[Group('integration')]
#[Group('cache')]
final class CacheClearMiddlewareTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private const array KEYS = [
        'issuer'    => 'https://admin.example.test',
        'audience'  => 'https://www.example.test',
        'secrets'   => ['2026-10' => 'a-secret-of-at-least-thirty-two-bytes!!'],
        'sign_with' => '2026-10',
    ];

    #[Test]
    public function clearsOnlyTheStoragesTheSiteListsInTheirSharedDirectory(): void
    {
        $pages      = $this->storage('pages');
        $workflow   = $this->storage('workflow');
        $thumbnails = $this->storage('thumbnails');
        $pages->setItem('pagecache_a_0', 'page');
        $workflow->setItem('routes', 'cached');
        $thumbnails->setItem('thumbnail', 'kept');

        $middleware = (new CacheClearMiddlewareFactory())(new InMemoryContainer([
            'config'   => [
                'pagecache'        => [
                    'cache' => 'pages',
                    'clear' => ['caches' => ['workflow'], 'replay' => ['directory' => $this->temporaryDirectory]],
                ],
                'contenir_preview' => self::KEYS,
            ],
            'pages'    => $pages,
            'workflow' => $workflow,
        ]));
        $response = $middleware->process(
            (new ServerRequest(
                uri: 'https://www.example.test/api/cache',
                method: 'POST',
            ))
                ->withHeader('Authorization', $this->bearer()),
            new CountingHandler(new EmptyResponse(404)),
        );

        static::assertSame(
            [204, false, false, true, 1, 1],
            [
                $response->getStatusCode(),
                $pages->hasItem('pagecache_a_0'),
                $workflow->hasItem('routes'),
                $thumbnails->hasItem('thumbnail'),
                $pages->getItem('pagecache_generation'),
                count((array) glob("{$this->temporaryDirectory}/contenir-cache-*")),
            ],
        );
    }

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownTemporaryDirectory();
    }

    private function bearer(): string
    {
        $keys = new SiteKeys(
            self::KEYS['issuer'],
            self::KEYS['audience'],
            self::KEYS['secrets'],
            self::KEYS['sign_with'],
        );

        return 'Bearer ' . (new ClearSigner($keys, new SystemClock()))->sign('12');
    }

    private function storage(string $namespace): Filesystem
    {
        $storage = new Filesystem(['cache_dir' => $this->temporaryDirectory, 'namespace' => $namespace]);
        $storage->addPlugin(new Serializer());

        return $storage;
    }
}
