<?php

/**
 * Which controllers exist, and what each one needs.
 *
 * This list used to be the Router's constructor: sixteen repositories threaded
 * in so that twenty-six controllers could each be handed two or three. The
 * Router used none of them itself — it was a postman carrying sixteen bags —
 * and every new controller widened a signature that already did not fit on a
 * screen.
 *
 * Splitting it puts each concern where it belongs. The Router registers routes.
 * This file decides what the HTTP surface consists of. Adding an endpoint means
 * one line here and nothing anywhere else.
 *
 * ## Why this file may call Services and the rest of src/ may not
 *
 * Services says, in its own docblock, that new code takes what it needs in its
 * constructor. That rule is what keeps the code testable, and it holds
 * everywhere — except at a composition root, which is by definition the place
 * that knows how everything is assembled. This is that place for the HTTP
 * layer. Pushing the sixteen arguments one class further up would have moved
 * the problem, not solved it.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Services;

final class ControllerFactory
{
    private function __construct()
    {
    }

    /**
     * Every controller the plugin serves, ready to register.
     *
     * Order is not significant to WordPress — each controller registers its own
     * routes — so the grouping below is for the reader: contracts first, since
     * they are the bulk of the surface, then the things around them.
     *
     * @return list<Controller>
     */
    public static function all(): array
    {
        $scope     = Services::scopeResolver();
        $lifecycle = Services::lifecycle();
        $queries   = Services::contractQueries();
        $details   = Services::contractDetails();
        $draftExit = Services::draftExitGate();
        $cancel    = Services::cancellationGate();

        return [
            // Contracts — read, write, status, bulk, documents.
            new ContractsReadController($scope, $queries, $details, Services::events(), Services::files()),
            new ContractSaveController(
                $scope,
                Services::contracts(),
                Services::customers(),
                $lifecycle,
                $draftExit,
                $cancel
            ),
            new ContractStatusController(
                $scope,
                Services::contracts(),
                Services::files(),
                $lifecycle,
                $draftExit,
                $cancel
            ),
            new ContractsBulkController($scope, Services::contracts(), Services::files(), $lifecycle),
            new ContractDocumentsController($scope, $details, Services::files(), Services::contractDocuments()),
            new DocumentsController($scope, Services::contracts(), Services::files()),
            new DuplicateCheckController($scope, $queries),
            new RenewalsController($scope, Services::contracts(), $queries, Services::events()),

            // Signing. Ο σύνδεσμος ΕΙΝΑΙ το tracking URL — δεν υπάρχει δεύτερο
            // token να λήξει ή να διαρρεύσει, και ο SigningController που
            // εξυπηρετούσε το παλιό διαγράφηκε μαζί με τη σελίδα του.
            new SignLinkController($scope, $details, Services::documents(), $lifecycle),

            // People.
            new CustomersController($scope, Services::customers()),
            new LeadsController($scope, Services::leads(), Services::contracts(), Services::customers()),
            new TeamController($scope, Services::team(), Services::contracts()),
            new TeamActivityController($scope, Services::teamActivity()),

            // Reporting.
            new DashboardController($scope, Services::dashboard()),
            new CommissionsController($scope, Services::commissions()),
            new AnalyticsController($scope, Services::analytics()),

            // Work management.
            new TasksController($scope, Services::tasks(), Services::contracts()),
            new NotificationsController($scope),
            new SavedFiltersController($scope),
            new ThemeController($scope),

            // Catalogue and form metadata.
            new CatalogueController($scope, Services::providers(), $queries),
            new ProviderFormController(),

            // Tools.
            new ImportController(),
            new ExtractionController(Services::extractionGate()),
            new VatLookupController(),
            new QuoteController(),
        ];
    }
}
