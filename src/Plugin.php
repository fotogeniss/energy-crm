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
use EnergyCRM\Infrastructure\KeyFingerprint;
use EnergyCRM\Infrastructure\PiiBackfill;
use EnergyCRM\Infrastructure\Retention;
use EnergyCRM\Legacy\Loader as LegacyLoader;
use EnergyCRM\Persistence\CustomerFields;
use EnergyCRM\Persistence\PersonalDataEraser;
use EnergyCRM\Persistence\PersonalDataExporter;
use EnergyCRM\Persistence\PiiBackfillRepository;

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
        (new DocumentProtection(Services::unprotectedDocuments()))->register();

        // Record which key this site's data belongs to, once. Only while
        // encryption is on: stamping a site that has never encrypted anything
        // would name a key that nothing was written under, and the first real
        // rotation afterwards would be reported against a fiction.
        if (CustomerFields::isEnabled()) {
            KeyFingerprint::default()->remember();
        }

        // Scheduled unconditionally, and inert until ECRM_ENCRYPT_PII is on.
        // Registering only when the flag is set would mean the sweep never
        // starts on the site that turns encryption on later — which is every
        // site, since the flag is opt-in.
        (new PiiBackfill(PiiBackfillRepository::default()))->register();

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
