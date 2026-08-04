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
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\FileRepository;
use EnergyCRM\Persistence\TaskRepository;

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
    ) {
        $this->controllers = [
            new ProviderFormController(),
            new NotificationsController($scope),
            new RenewalsController($scope, $contracts),
            new CustomersController($scope, $customers),
            new TasksController($scope, $tasks, $contracts),
            new ContractsReadController($scope, $contracts, $events, $files),
            new ContractStatusController($scope, $contracts, $files),
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
