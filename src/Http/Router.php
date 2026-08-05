<?php

/**
 * Registers every controller on rest_api_init.
 *
 * The list below is the map of the HTTP surface. As resources move out of
 * ECRM_REST they are added here and deleted there — a route must never live in
 * both, or WordPress silently keeps whichever registered last.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Access\ScopeResolver;
use EnergyCRM\Persistence\AnalyticsRepository;
use EnergyCRM\Persistence\CommissionRepository;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\DashboardRepository;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\FileRepository;
use EnergyCRM\Persistence\LeadRepository;
use EnergyCRM\Persistence\ProviderRepository;
use EnergyCRM\Persistence\SignatureRepository;
use EnergyCRM\Persistence\TaskRepository;
use EnergyCRM\Persistence\TeamRepository;

final class Router
{
    public const NAMESPACE = 'ecrm/v1';

    /** @var list<Controller> */
    private array $controllers;

    public function __construct(
        ScopeResolver $scope,
        ContractRepository $contracts,
        CustomerRepository $customers,
        TaskRepository $tasks,
        EventRepository $events,
        FileRepository $files,
        LeadRepository $leads,
        TeamRepository $team,
        SignatureRepository $signatures,
        ProviderRepository $providers,
        DashboardRepository $dashboard,
        CommissionRepository $commissions,
        AnalyticsRepository $analytics,
    ) {
        $this->controllers = [
            new ProviderFormController(),
            new NotificationsController($scope),
            new RenewalsController($scope, $contracts),
            new CustomersController($scope, $customers),
            new TasksController($scope, $tasks, $contracts),
            new ContractsReadController($scope, $contracts, $events, $files),
            new ContractStatusController($scope, $contracts, $files),
            new ContractSaveController($scope, $contracts, $customers),
            new ContractsBulkController($scope, $contracts, $files),
            new LeadsController($scope, $leads, $contracts, $customers),
            new TeamController($scope, $team),
            new ImportController(),
            new SigningController($signatures, $files),
            new DocumentsController($scope, $contracts, $files),
            new ContractDocumentsController($scope, $contracts, $files),
            new SavedFiltersController($scope),
            new CatalogueController($scope, $providers, $contracts),
            new DuplicateCheckController($scope, $contracts),
            new ExtractionController(),
            new VatLookupController(),
            new DashboardController($scope, $dashboard),
            new CommissionsController($scope, $commissions),
            new AnalyticsController($scope, $analytics),
        ];
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            foreach ($this->controllers as $controller) {
                $controller->routes();
            }
        });
    }
}
