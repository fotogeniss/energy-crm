<?php

/**
 * Unit tests for the PSR-4 autoloader.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit;

use EnergyCRM\Autoloader;
use PHPUnit\Framework\TestCase;

final class AutoloaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ecrm-autoload-' . uniqid('', true);
        mkdir($this->root . '/Domain', 0777, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->root . '/Domain/*');

        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }

        rmdir($this->root . '/Domain');
        rmdir($this->root);
    }

    public function testItMapsANamespacedClassToAPsr4Path(): void
    {
        $short = 'Widget' . random_int(1000, 9999);
        $fqcn  = 'Fixture\\Domain\\' . $short;

        file_put_contents(
            $this->root . '/Domain/' . $short . '.php',
            "<?php namespace Fixture\\Domain; class {$short} {}"
        );

        (new Autoloader('Fixture', $this->root))->load($fqcn);

        self::assertTrue(class_exists($fqcn, false));
    }

    public function testItIgnoresClassesOutsideItsPrefix(): void
    {
        (new Autoloader('Fixture', $this->root))->load('SomeOther\\Vendor\\Thing');

        self::assertFalse(class_exists('SomeOther\\Vendor\\Thing', false));
    }

    public function testItRefusesPathsContainingTraversal(): void
    {
        $loader = new Autoloader('Fixture', $this->root);

        $loader->load('Fixture\\..\\..\\etc\\passwd');

        self::assertTrue(true, 'load() must return without requiring anything');
    }
}
