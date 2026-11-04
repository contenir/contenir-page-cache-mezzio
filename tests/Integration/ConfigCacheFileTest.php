<?php

declare(strict_types=1);

namespace Contenir\PageCache\Mezzio\Tests\Integration;

use Contenir\PageCache\Mezzio\ConfigCacheFile;
use Contenir\PageCache\Mezzio\Tests\Trait\TemporaryDirectoryTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chmod;
use function file_put_contents;
use function is_file;

/**
 * Removing the site's cached configuration, so the next request merges config/autoload afresh.
 */
#[Group('integration')]
#[Group('cache')]
final class ConfigCacheFileTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $path;

    #[Test]
    public function failsWhenTheCachedConfigurationCannotBeRemoved(): void
    {
        file_put_contents($this->path, data: '<?php return [];');
        chmod($this->temporaryDirectory, permissions: 0o555);

        static::assertSame([false, true], [(new ConfigCacheFile($this->path))->clear(), is_file($this->path)]);
    }

    #[Test]
    public function isClearWhenNothingIsCached(): void
    {
        static::assertTrue((new ConfigCacheFile($this->path))->clear());
    }

    #[Test]
    public function removesTheCachedConfiguration(): void
    {
        file_put_contents($this->path, data: '<?php return [];');

        static::assertSame([true, false], [(new ConfigCacheFile($this->path))->clear(), is_file($this->path)]);
    }

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->path = "{$this->temporaryDirectory}/config-cache.php";
    }

    protected function tearDown(): void
    {
        chmod($this->temporaryDirectory, permissions: 0o777);
        $this->tearDownTemporaryDirectory();
    }
}
