<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio;

use Contenir\SiteToken\SiteKeys;
use InvalidArgumentException;

use function array_is_list;
use function is_array;
use function is_string;

/**
 * Typed reads of `config['pagecache']['clear']`:
 *
 * ```php
 * 'pagecache' => [
 *     'clear' => [
 *         'keys'   => 'contenir_preview',     // the config holding issuer, audience, secrets and sign_with
 *         'caches' => ['workflow'],           // other cache services to clear, besides the pages
 *         'replay' => ['cache' => 'Shared'],  // a PSR-16 service shared by servers; or
 *                                             // ['directory' => '/var/app/clear'], default the temp dir
 *     ],
 * ],
 * ```
 *
 * The keys are the ones previews use unless `keys` names another config, so a site holds one
 * secret for both.
 *
 * @internal
 */
final readonly class ClearOptions
{
    /** The config the keys are read from unless `keys` names another. */
    public const string DEFAULT_KEYS = 'contenir_preview';

    /**
     * @param list<string> $caches
     */
    private function __construct(
        public SiteKeys $keys,
        public array $caches,
        public ?string $replayCache,
        public ?string $replayDirectory,
    ) {}

    /**
     * The options, or null when the site configures no clear.
     *
     * @param array<array-key, mixed> $appConfig The application's merged configuration
     *
     * @throws InvalidArgumentException When a setting cannot be used as it stands.
     */
    public static function fromAppConfig(array $appConfig): ?self
    {
        /** @var mixed $pagecache */
        $pagecache = $appConfig['pagecache'] ?? null;
        /** @var mixed $clear */
        $clear = is_array($pagecache) ? $pagecache['clear'] ?? null : null;
        if (null === $clear) {
            return null;
        }

        if (! is_array($clear)) {
            throw new InvalidArgumentException('pagecache.clear must be an array.');
        }

        $keysName = ClearSetting::text($clear, 'keys', 'pagecache.clear.keys') ?? self::DEFAULT_KEYS;
        /** @var mixed $replay */
        $replay = $clear['replay'] ?? [];
        if (! is_array($replay)) {
            throw new InvalidArgumentException('pagecache.clear.replay must be an array.');
        }

        return new self(
            ClearKeys::from($appConfig[$keysName] ?? null, $keysName),
            self::caches($clear['caches'] ?? []),
            ClearSetting::text($replay, 'cache', 'pagecache.clear.replay.cache'),
            ClearSetting::text($replay, 'directory', 'pagecache.clear.replay.directory'),
        );
    }

    /**
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private static function caches(mixed $caches): array
    {
        if (! is_array($caches) || ! array_is_list($caches)) {
            throw new InvalidArgumentException('pagecache.clear.caches must list cache service names.');
        }

        $names = [];
        /** @var mixed $name */
        foreach ($caches as $name) {
            if (! is_string($name) || '' === $name) {
                throw new InvalidArgumentException('pagecache.clear.caches must list cache service names.');
            }

            $names[] = $name;
        }

        return $names;
    }
}
