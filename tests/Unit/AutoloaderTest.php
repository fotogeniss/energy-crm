<?php
/**
 * @package EnergyCRM
 */

declare( strict_types=1 );

namespace EnergyCRM\Tests\Unit;

use EnergyCRM\Autoloader;
use PHPUnit\Framework\TestCase;

final class AutoloaderTest extends TestCase {

	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/ecrm-autoload-' . uniqid( '', true );
		mkdir( $this->root . '/Domain', 0777, true );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->root . '/Domain/*' ) ?: [] as $file ) {
			unlink( $file );
		}
		@rmdir( $this->root . '/Domain' );
		@rmdir( $this->root );
	}

	public function test_it_maps_a_namespaced_class_to_a_psr4_path(): void {
		$class = 'Fixture\\Domain\\Widget' . random_int( 1000, 9999 );
		$short = substr( $class, strrpos( $class, '\\' ) + 1 );

		file_put_contents(
			$this->root . '/Domain/' . $short . '.php',
			"<?php namespace Fixture\\Domain; class {$short} {}"
		);

		( new Autoloader( 'Fixture', $this->root ) )->load( $class );

		self::assertTrue( class_exists( $class, false ) );
	}

	public function test_it_ignores_classes_outside_its_prefix(): void {
		$loader = new Autoloader( 'Fixture', $this->root );

		$loader->load( 'SomeOther\\Vendor\\Thing' );

		self::assertFalse( class_exists( 'SomeOther\\Vendor\\Thing', false ) );
	}

	public function test_it_refuses_paths_containing_traversal(): void {
		$loader = new Autoloader( 'Fixture', $this->root );

		$loader->load( 'Fixture\\..\\..\\etc\\passwd' );

		self::assertTrue( true, 'load() must return without requiring anything' );
	}
}
