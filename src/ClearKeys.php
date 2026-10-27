<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio;

use Contenir\SiteToken\SiteKeys;
use InvalidArgumentException;

use function array_is_list;
use function is_array;
use function is_string;
use function sprintf;

/**
 * The keys a cache clear is checked with, read from the config ClearOptions names: `issuer`,
 * `audience`, `secrets` (key id => secret) and `sign_with`, as previews hold them.
 *
 * @internal
 */
final class ClearKeys
{
    /**
     * @throws InvalidArgumentException When the config is missing or a setting cannot be used.
     */
    public static function from(mixed $config, string $name): SiteKeys
    {
        if (! is_array($config)) {
            throw new InvalidArgumentException(sprintf(
                'The clear\'s keys are read from %s, which is not configured.',
                $name,
            ));
        }

        return new SiteKeys(
            ClearSetting::text($config, 'issuer', "{$name}.issuer") ?? '',
            ClearSetting::text($config, 'audience', "{$name}.audience") ?? '',
            self::secrets($config['secrets'] ?? null, $name),
            ClearSetting::text($config, 'sign_with', "{$name}.sign_with") ?? '',
        );
    }

    /**
     * @return non-empty-array<string, string>
     *
     * @throws InvalidArgumentException
     */
    private static function secrets(mixed $secrets, string $name): array
    {
        if (! is_array($secrets) || [] === $secrets || array_is_list($secrets)) {
            throw new InvalidArgumentException(sprintf('%s.secrets must map key ids to secrets.', $name));
        }

        $valid = [];
        /** @var mixed $secret */
        foreach ($secrets as $keyId => $secret) {
            if (! is_string($keyId) || ! is_string($secret)) {
                throw new InvalidArgumentException(sprintf('%s.secrets must map key ids to secrets.', $name));
            }

            $valid[$keyId] = $secret;
        }

        return $valid;
    }
}
