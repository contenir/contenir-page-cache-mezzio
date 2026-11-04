<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio\Tests\Integration;

use Contenir\PageCache\Clear\ClearSigner;
use Contenir\PageCache\Mezzio\CacheClearMiddleware;
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
use Psr\Http\Message\ResponseInterface;

use function chmod;
use function count;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;

/**
 * Clearing the laminas-cache Filesystem storages most Contenir sites keep under one directory:
 * the page store and each listed cache are emptied, and a cache the site didn't list, sharing the
 * directory, is left as it was. The site's cached configuration goes too, so the settings the CMS
 * saved are read.
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

    #[Test]
    public function namesTheConfigurationWhenItCannotBeRemovedAndClearsTheRest(): void
    {
        $path  = "{$this->temporaryDirectory}/config/config-cache.php";
        $pages = $this->storage('pages');
        $pages->setItem('pagecache_a_0', 'page');
        $this->cacheConfig($path);
        chmod("{$this->temporaryDirectory}/config", permissions: 0o555);

        $response = $this->clear($path, $pages);

        static::assertSame(
            [500, 'Could not clear: ' . CacheClearMiddleware::CONFIG . '.', false],
            [$response->getStatusCode(), (string) $response->getBody(), $pages->hasItem('pagecache_a_0')],
        );
    }

    #[Test]
    public function removesTheCachedConfigurationTheSiteNames(): void
    {
        $path = "{$this->temporaryDirectory}/config/config-cache.php";
        $this->cacheConfig($path);

        static::assertSame([204, false], [$this->clear($path)->getStatusCode(), is_file($path)]);
    }

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
    }

    protected function tearDown(): void
    {
        if (is_dir("{$this->temporaryDirectory}/config")) {
            chmod("{$this->temporaryDirectory}/config", permissions: 0o777);
        }

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

    private function cacheConfig(string $path): void
    {
        mkdir("{$this->temporaryDirectory}/config");
        file_put_contents($path, data: '<?php return [];');
    }

    private function clear(string $configCache, ?Filesystem $pages = null): ResponseInterface
    {
        $middleware = (new CacheClearMiddlewareFactory())(new InMemoryContainer([
            'config' => [
                'config_cache_path' => $configCache,
                'pagecache'         => [
                    'cache' => 'pages',
                    'clear' => ['replay' => ['directory' => $this->temporaryDirectory]],
                ],
                'contenir_preview'  => self::KEYS,
            ],
            'pages'  => $pages ?? $this->storage('pages'),
        ]));

        return $middleware->process(
            (new ServerRequest(
                uri: 'https://www.example.test/api/cache',
                method: 'POST',
            ))
                ->withHeader('Authorization', $this->bearer()),
            new CountingHandler(new EmptyResponse(404)),
        );
    }

    private function storage(string $namespace): Filesystem
    {
        $storage = new Filesystem(['cache_dir' => $this->temporaryDirectory, 'namespace' => $namespace]);
        $storage->addPlugin(new Serializer());

        return $storage;
    }
}
