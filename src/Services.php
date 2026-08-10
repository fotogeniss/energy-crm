<?php

/**
 * Service locator — a temporary bridge, not the destination.
 *
 * The legacy ECRM_* classes are static and take no constructor arguments, so
 * they cannot receive dependencies the honest way. Until they migrate, they
 * reach their collaborators through here.
 *
 * New code under `src/` must NOT use this: take what you need in your
 * constructor. Every remaining call site here is a to-do item, and when the
 * legacy classes are gone this file goes with them.
 *
 * One sanctioned exception, and only one: `Http\ControllerFactory`. A
 * composition root is the place that knows how everything is assembled, so
 * asking it to receive its dependencies is asking the wrong question — the
 * arguments have to stop somewhere. Anywhere else, a `Services::` call inside
 * `src/` is a bug.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM;

use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Access\WordPressScopeResolver;
use EnergyCRM\Infrastructure\DocumentQueue;
use EnergyCRM\Infrastructure\ExtractionGate;
use EnergyCRM\Infrastructure\SecretStore;
use EnergyCRM\Persistence\AnalyticsRepository;
use EnergyCRM\Persistence\CommissionRepository;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\DashboardRepository;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\FileRepository;
use EnergyCRM\Persistence\LeadRepository;
use EnergyCRM\Persistence\NetworkRepository;
use EnergyCRM\Persistence\ProviderRepository;
use EnergyCRM\Persistence\SignatureRepository;
use EnergyCRM\Persistence\TaskRepository;
use EnergyCRM\Persistence\TeamActivityRepository;
use EnergyCRM\Persistence\TeamRepository;

final class Services
{
    private static ?ScopeResolver $scopeResolver = null;

    private static ?ContractRepository $contracts = null;

    private static ?CustomerRepository $customers = null;

    private static ?NetworkRepository $network = null;

    private static ?FileRepository $files = null;

    private static ?SecretStore $secrets = null;

    private static ?TaskRepository $tasks = null;

    private static ?EventRepository $events = null;

    private static ?LeadRepository $leads = null;

    private static ?TeamRepository $team = null;

    private static ?SignatureRepository $signatures = null;

    private static ?ProviderRepository $providers = null;

    private static ?DashboardRepository $dashboard = null;

    private static ?CommissionRepository $commissions = null;

    private static ?AnalyticsRepository $analytics = null;

    private static ?TeamActivityRepository $teamActivity = null;

    private static ?DocumentQueue $documents = null;

    private static ?ExtractionGate $extractionGate = null;

    private function __construct()
    {
    }

    public static function scopeResolver(): ScopeResolver
    {
        return self::$scopeResolver ??= new WordPressScopeResolver(self::network());
    }

    public static function network(): NetworkRepository
    {
        return self::$network ??= new NetworkRepository();
    }

    public static function files(): FileRepository
    {
        return self::$files ??= new FileRepository(\ECRM_Files::dir());
    }

    public static function extractionGate(): ExtractionGate
    {
        return self::$extractionGate ??= new ExtractionGate();
    }

    public static function documents(): DocumentQueue
    {
        return self::$documents ??= new DocumentQueue(self::files());
    }

    public static function teamActivity(): TeamActivityRepository
    {
        return self::$teamActivity ??= new TeamActivityRepository(self::network());
    }

    public static function analytics(): AnalyticsRepository
    {
        return self::$analytics ??= new AnalyticsRepository();
    }

    public static function commissions(): CommissionRepository
    {
        return self::$commissions ??= new CommissionRepository();
    }

    public static function dashboard(): DashboardRepository
    {
        return self::$dashboard ??= new DashboardRepository();
    }

    public static function providers(): ProviderRepository
    {
        return self::$providers ??= new ProviderRepository();
    }

    public static function signatures(): SignatureRepository
    {
        return self::$signatures ??= new SignatureRepository();
    }

    public static function team(): TeamRepository
    {
        return self::$team ??= new TeamRepository();
    }

    public static function leads(): LeadRepository
    {
        return self::$leads ??= new LeadRepository();
    }

    public static function events(): EventRepository
    {
        return self::$events ??= new EventRepository();
    }

    public static function tasks(): TaskRepository
    {
        return self::$tasks ??= new TaskRepository();
    }

    public static function secrets(): SecretStore
    {
        return self::$secrets ??= new SecretStore();
    }

    public static function contracts(): ContractRepository
    {
        return self::$contracts ??= new ContractRepository();
    }

    public static function customers(): CustomerRepository
    {
        return self::$customers ??= new CustomerRepository();
    }

    /** Test seam: swap implementations, then reset(). */
    public static function swap(
        ?ScopeResolver $scopeResolver = null,
        ?ContractRepository $contracts = null,
        ?CustomerRepository $customers = null,
        ?NetworkRepository $network = null,
        ?FileRepository $files = null,
    ): void {
        self::$scopeResolver = $scopeResolver ?? self::$scopeResolver;
        self::$contracts     = $contracts ?? self::$contracts;
        self::$customers     = $customers ?? self::$customers;
        self::$network       = $network ?? self::$network;
        self::$files         = $files ?? self::$files;
    }

    public static function reset(): void
    {
        self::$scopeResolver = null;
        self::$contracts     = null;
        self::$customers     = null;
        self::$network       = null;
        self::$files         = null;
        self::$secrets       = null;
        self::$tasks         = null;
        self::$events        = null;
        self::$leads         = null;
        self::$team          = null;
        self::$signatures    = null;
        self::$providers     = null;
        self::$dashboard     = null;
        self::$commissions   = null;
        self::$analytics     = null;
        self::$teamActivity  = null;
        self::$documents      = null;
        self::$extractionGate = null;
    }
}
