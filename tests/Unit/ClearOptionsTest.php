<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio\Tests\Unit;

use Contenir\PageCache\Mezzio\ClearOptions;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading the cache clear's configuration, and refusing what can't be used.
 */
#[Group('unit')]
#[Group('cache')]
final class ClearOptionsTest extends TestCase
{
    private const array KEYS = [
        'issuer'    => 'https://admin.example.test',
        'audience'  => 'https://www.example.test',
        'secrets'   => ['2026-10' => 'a-secret-of-at-least-thirty-two-bytes!!'],
        'sign_with' => '2026-10',
    ];

    /**
     * @return array<string, array{array<array-key, mixed>, string}>
     */
    public static function unusableProvider(): array
    {
        $keys = ['contenir_preview' => self::KEYS];

        return [
            'a clear that is not an array' => [
                ['pagecache' => ['clear' => 'yes']],
                'pagecache.clear must be an array.',
            ],
            'keys named by a number'       => [
                ['pagecache' => ['clear' => ['keys' => 7]]],
                'pagecache.clear.keys must be text.',
            ],
            'keys from nowhere'            => [
                ['pagecache' => ['clear' => ['keys' => 'site_keys']]],
                'The clear\'s keys are read from site_keys, which is not configured.',
            ],
            'replay that is not an array'  => [
                ['pagecache' => ['clear' => ['replay' => 'files']]] + $keys,
                'pagecache.clear.replay must be an array.',
            ],
            'a replay cache named empty'   => [
                ['pagecache' => ['clear' => ['replay' => ['cache' => '']]]] + $keys,
                'pagecache.clear.replay.cache must be text.',
            ],
            'caches as a map'              => [
                ['pagecache' => ['clear' => ['caches' => ['workflow' => true]]]] + $keys,
                'pagecache.clear.caches must list cache service names.',
            ],
            'caches as text'               => [
                ['pagecache' => ['clear' => ['caches' => 'workflow']]] + $keys,
                'pagecache.clear.caches must list cache service names.',
            ],
            'a cache named empty'          => [
                ['pagecache' => ['clear' => ['caches' => ['']]]] + $keys,
                'pagecache.clear.caches must list cache service names.',
            ],
            'a cache named by a number'    => [
                ['pagecache' => ['clear' => ['caches' => [7]]]] + $keys,
                'pagecache.clear.caches must list cache service names.',
            ],
            'secrets as a list'            => [
                ['pagecache' => ['clear' => []], 'contenir_preview' => ['secrets' => ['a']] + self::KEYS],
                'contenir_preview.secrets must map key ids to secrets.',
            ],
            'no secrets'                   => [
                ['pagecache' => ['clear' => []], 'contenir_preview' => ['secrets' => []] + self::KEYS],
                'contenir_preview.secrets must map key ids to secrets.',
            ],
            'secrets as text'              => [
                ['pagecache' => ['clear' => []], 'contenir_preview' => ['secrets' => 'a'] + self::KEYS],
                'contenir_preview.secrets must map key ids to secrets.',
            ],
            'a secret that is not text'    => [
                ['pagecache' => ['clear' => []], 'contenir_preview' => ['secrets' => ['2026-10' => 7]] + self::KEYS],
                'contenir_preview.secrets must map key ids to secrets.',
            ],
            'an issuer that is not text'   => [
                ['pagecache' => ['clear' => []], 'contenir_preview' => ['issuer' => 7] + self::KEYS],
                'contenir_preview.issuer must be text.',
            ],
        ];
    }

    #[Test]
    public function configuresNoClearUnlessTheSiteAsks(): void
    {
        static::assertSame([null, null, null], [
            ClearOptions::fromAppConfig([]),
            ClearOptions::fromAppConfig(['pagecache' => 'off']),
            ClearOptions::fromAppConfig(['pagecache' => ['clear' => null]]),
        ]);
    }

    #[Test]
    public function readsTheKeysFromTheConfigItNamesAndWhatElseToClear(): void
    {
        $options = ClearOptions::fromAppConfig([
            'pagecache' => [
                'clear' => [
                    'keys'   => 'site_keys',
                    'caches' => ['workflow', 'thumbnails'],
                    'replay' => ['cache' => 'Shared', 'directory' => '/var/clear'],
                ],
            ],
            'site_keys' => ['audience' => 'https://shop.example.test'] + self::KEYS,
        ]);

        static::assertSame(
            ['https://shop.example.test', ['workflow', 'thumbnails'], 'Shared', '/var/clear'],
            [$options?->keys->audience, $options?->caches, $options?->replayCache, $options?->replayDirectory],
        );
    }

    #[Test]
    public function readsTheKeysPreviewsUseAndClearsOnlyThePagesByDefault(): void
    {
        $options = ClearOptions::fromAppConfig(['pagecache' => ['clear' => []], 'contenir_preview' => self::KEYS]);

        static::assertSame(
            ['https://admin.example.test', 'https://www.example.test', '2026-10', [], null, null],
            [
                $options?->keys->issuer,
                $options?->keys->audience,
                $options?->keys->signWith,
                $options?->caches,
                $options?->replayCache,
                $options?->replayDirectory,
            ],
        );
    }

    /**
     * @param array<array-key, mixed> $config
     */
    #[Test]
    #[DataProvider('unusableProvider')]
    public function refusesWhatItCannotUse(array $config, string $reason): void
    {
        $this->expectExceptionObject(new InvalidArgumentException($reason));

        ClearOptions::fromAppConfig($config);
    }
}
