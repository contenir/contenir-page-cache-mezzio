<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio;

use InvalidArgumentException;

use function is_string;
use function sprintf;

/**
 * Reads one text setting of the cache clear's configuration.
 *
 * @internal
 */
final class ClearSetting
{
    /**
     * The setting, or null when it is not set.
     *
     * @param array<array-key, mixed> $settings
     *
     * @throws InvalidArgumentException When the setting is there but not text.
     */
    public static function text(array $settings, string $key, string $path): ?string
    {
        /** @var mixed $value */
        $value = $settings[$key] ?? null;
        if (null === $value) {
            return null;
        }

        return is_string($value) && '' !== $value
            ? $value
            : throw new InvalidArgumentException(sprintf('%s must be text.', $path));
    }
}
