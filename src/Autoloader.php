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

declare(strict_types=1);

namespace EnergyCRM;

final class Autoloader
{
    /** Namespace prefix handled by this loader, with trailing separator. */
    private string $prefix;

    /** Absolute directory the prefix maps to, with trailing slash. */
    private string $baseDir;

    public function __construct(string $prefix, string $baseDir)
    {
        $this->prefix  = rtrim($prefix, '\\') . '\\';
        $this->baseDir = rtrim($baseDir, '/\\') . '/';
    }

    /** Instantiate and register on the SPL stack. */
    public static function register(string $prefix, string $baseDir): self
    {
        $loader = new self($prefix, $baseDir);
        spl_autoload_register([$loader, 'load']);

        return $loader;
    }

    /**
     * Resolve a fully-qualified class name to a file and require it.
     *
     * EnergyCRM\Domain\Contract\ContractId -> src/Domain/Contract/ContractId.php
     */
    public function load(string $fqcn): void
    {
        if (strncmp($fqcn, $this->prefix, strlen($this->prefix)) !== 0) {
            return;
        }

        $relative = substr($fqcn, strlen($this->prefix));

        // Guard against traversal from a malformed class name.
        if (str_contains($relative, '..')) {
            return;
        }

        $path = $this->baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($path)) {
            require_once $path;
        }
    }
}
