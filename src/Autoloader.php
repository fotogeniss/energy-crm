<?php
/**
 * Minimal PSR-4 autoloader.
 *
 * Composer is a *development* dependency here (PHPUnit / PHPStan / PHPCS).
 * Production installs must work from a plain zip with no `vendor/` directory,
 * so the plugin ships its own loader and only defers to Composer when the
 * generated autoloader happens to be present.
 *
 * @package EnergyCRM
 */

declare( strict_types=1 );

namespace EnergyCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Autoloader {

	/** Namespace prefix handled by this loader, e.g. "EnergyCRM\". */
	private string $prefix;

	/** Absolute directory the prefix maps to, with trailing slash. */
	private string $base_dir;

	public function __construct( string $prefix, string $base_dir ) {
		$this->prefix   = rtrim( $prefix, '\\' ) . '\\';
		$this->base_dir = rtrim( $base_dir, '/\\' ) . '/';
	}

	/** Instantiate and register on the SPL stack. */
	public static function register( string $prefix, string $base_dir ): self {
		$loader = new self( $prefix, $base_dir );
		spl_autoload_register( [ $loader, 'load' ] );
		return $loader;
	}

	/**
	 * Resolve a fully-qualified class name to a file and require it.
	 *
	 * EnergyCRM\Domain\Contract\ContractId  ->  src/Domain/Contract/ContractId.php
	 */
	public function load( string $class ): void {
		if ( strncmp( $class, $this->prefix, strlen( $this->prefix ) ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( $this->prefix ) );
		$path     = $this->base_dir . str_replace( '\\', '/', $relative ) . '.php';

		// Guard against traversal from a malformed class name.
		if ( strpos( $relative, '..' ) !== false ) {
			return;
		}

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
