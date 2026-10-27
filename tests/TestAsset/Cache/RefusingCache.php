<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio\Tests\TestAsset\Cache;

use Override;
use Psr\SimpleCache\CacheInterface;

use function array_key_exists;

/**
 * A cache that answers false where told to: to clear(), to set(), or both, as a backend that
 * cannot write does, while reading what it holds.
 */
final class RefusingCache implements CacheInterface
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(
        private readonly bool $clears = false,
        private readonly bool $sets = false,
        public array $items = [],
    ) {}

    #[Override]
    public function clear(): bool
    {
        return $this->clears;
    }

    #[Override]
    public function delete($key): bool
    {
        return false;
    }

    #[Override]
    public function deleteMultiple($keys): bool
    {
        return false;
    }

    #[Override]
    public function get($key, $default = null): mixed
    {
        return $this->items[$key] ?? $default;
    }

    #[Override]
    public function getMultiple($keys, $default = null): iterable
    {
        return [];
    }

    #[Override]
    public function has($key): bool
    {
        return array_key_exists($key, $this->items);
    }

    #[Override]
    public function set($key, $value, $ttl = null): bool
    {
        return $this->sets;
    }

    #[Override]
    public function setMultiple($values, $ttl = null): bool
    {
        return false;
    }
}
