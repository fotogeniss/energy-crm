<?php

/**
 * Plugin bootstrap — the single composition root.
 *
 * Responsibilities are deliberately narrow: know the plugin's identity, wire
 * the WordPress lifecycle hooks, and delegate the actual work to the installer
 * and the module loaders. Business logic never lives here.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM;

use EnergyCRM\Access\NetworkSync;
use EnergyCRM\Access\Roles;
use EnergyCRM\Admin\FormCalibrator;
use EnergyCRM\Http\Router;
use EnergyCRM\Infrastructure\Retention;
use EnergyCRM\Legacy\Loader as LegacyLoader;

final class Plugin
{
    public const VERSION = '0.84.0';

    private static ?self $instance = null;

    /** Absolute path to the main plugin file. */
    private string $file;

    private function __construct(string $file)
    {
        $this->file = $file;
    }

    /** Boot once; repeated calls return the same instance. */
    public static function boot(string $file): self
    {
        if (self::$instance === null) {
            self::$instance = new self($file);
            self::$instance->registerHooks();
        }

        return self::$instance;
    }

    public static function instance(): ?self
    {
        return self::$instance;
    }

    public function file(): string
    {
        return $this->file;
    }

    public function dir(): string
    {
        return plugin_dir_path($this->file);
    }

    public function url(): string
    {
        return plugin_dir_url($this->file);
    }

    private function registerHooks(): void
    {
        register_activation_hook($this->file, [Installer::class, 'activate']);
        register_deactivation_hook($this->file, [Installer::class, 'deactivate']);

        add_action('plugins_loaded', [$this, 'onPluginsLoaded']);
    }

    /**
     * Runs on every request once WordPress and all plugins are available.
     *
     * Order matters: schema first, so every module can assume its tables exist,
     * then the modules themselves.
     */
    public function onPluginsLoaded(): void
    {
        Installer::maybeUpgrade();
        Roles::maybeSync();

        (new NetworkSync(Services::network()))->register();
        (new Retention(Services::contracts()))->register();
        (new Router(
            Services::scopeResolver(),
            Services::contracts(),
            Services::customers(),
            Services::tasks(),
            Services::events(),
            Services::files(),
            Services::leads(),
            Services::team(),
            Services::signatures()
        ))->register();

        if (is_admin()) {
            (new FormCalibrator())->register();
        }

        LegacyLoader::boot();
    }
}
