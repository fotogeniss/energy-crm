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
use EnergyCRM\Admin\PrivacyTools;
use EnergyCRM\Http\ControllerFactory;
use EnergyCRM\Http\Router;
use EnergyCRM\Infrastructure\DocumentProtection;
use EnergyCRM\Infrastructure\Retention;
use EnergyCRM\Legacy\Loader as LegacyLoader;
use EnergyCRM\Persistence\PersonalDataEraser;
use EnergyCRM\Persistence\PersonalDataExporter;

final class Plugin
{
    public const VERSION = '1.11.0';

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
        (new DocumentProtection(Services::files()))->register();
        (new Router(...ControllerFactory::all()))->register();

        // Signed contracts advance themselves after a delay: a one-off event per
        // signature, plus a sweep that heals a missed one.
        Services::autoProcess()->register();

        // The PDF builder listens for its own scheduled events; without this
        // the queue fills and nothing ever drains it.
        Services::documents()->register();

        if (is_admin()) {
            (new FormCalibrator())->register();

            // Tools → Export/Erase Personal Data. Admin-only, and admin-ajax
            // counts as admin, which is where WordPress actually runs them.
            (new PrivacyTools(
                new PersonalDataExporter(),
                new PersonalDataEraser(Services::files())
            ))->register();
        }

        LegacyLoader::boot();
    }
}
