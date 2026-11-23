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

use EnergyCRM\GatewayIntake\Http\DoNotCallController;
use EnergyCRM\GatewayIntake\Http\GatewayIntakeController;
use EnergyCRM\GatewayIntake\Persistence\GatewayLeadRepository;
use EnergyCRM\Infrastructure\CspReports;
use EnergyCRM\Infrastructure\SignatureState;
use EnergyCRM\Infrastructure\TeamInvite;
use EnergyCRM\Infrastructure\TwoFactor;
use EnergyCRM\Infrastructure\UserSessions;
use EnergyCRM\Persistence\CrossSellQueries;
use EnergyCRM\Persistence\CustomerHistoryQueries;
use EnergyCRM\Persistence\GuaranteeRuleRepository;
use EnergyCRM\Persistence\StoreRepository;
use EnergyCRM\Persistence\TargetRepository;
use EnergyCRM\Providers\Http\ProviderAccessController;
use EnergyCRM\Providers\Http\ProviderStatusMapController;
use EnergyCRM\Providers\Persistence\ProviderStatusMapRepository;
use EnergyCRM\Providers\Persistence\UsualChoiceRepository;
use EnergyCRM\Services;
use EnergyCRM\SiteIntake\Http\SiteIntakeController;

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
        $deletion  = Services::deletionGate();
        $stuck     = new StuckController($scope, Services::statusDwell(), Services::team());

        return [
            // Contracts — read, write, status, bulk, documents.
            new ContractsReadController(
                $scope,
                $queries,
                $details,
                Services::events(),
                Services::files(),
                Services::statusDwell(),
                Services::signatureState(),
            ),
            new ContractSaveController(
                $scope,
                Services::contracts(),
                Services::customers(),
                $lifecycle,
                $draftExit,
                Services::paperworkGate(),
                Services::requestKeys(),
                Services::providerVisibility(),
                Services::printedFields(),
            ),
            new ContractStatusController(
                $scope,
                Services::contracts(),
                Services::files(),
                $lifecycle,
                $draftExit,
                $deletion,
                Services::deletionLog()
            ),
            // (348) Παρατηρήσεις εκκρεμότητας.
            new PendingRemarksController($scope, Services::contracts(), Services::pendingRemarks()),
            new ContractsBulkController(
                $scope,
                Services::contracts(),
                Services::files(),
                $lifecycle,
                $deletion,
                $draftExit
            ),
            new ContractDocumentsController($scope, $details, Services::files(), Services::contractDocuments()),
            new DocumentsController($scope, Services::contracts(), Services::files()),
            // (341) Έλεγχος πριν την αποστολή: τα απαιτούμενα δικαιολογητικά.
            new RequiredDocsController($scope, Services::contracts(), Services::providers()),
            new DocumentKindController(
                $scope,
                Services::contracts(),
                Services::files(),
                Services::documentKindReview()
            ),
            new DocumentsOverviewController(
                $scope,
                Services::contractQueries(),
                Services::files(),
                Services::documentKindReview()
            ),
            new DuplicateCheckController($scope, $queries),
            new RenewalsController(
                $scope,
                Services::contracts(),
                $queries,
                Services::events(),
                Services::providerVisibility(),
                Services::providers(),
            ),
            // (326) «+ Αέριο / + Ρεύμα στον ίδιο πελάτη».
            new CompanionController(
                $scope,
                Services::contracts(),
                $queries,
                Services::events(),
                Services::providerVisibility(),
                Services::providers(),
            ),

            // Signing. Ο σύνδεσμος ΕΙΝΑΙ το tracking URL — δεν υπάρχει δεύτερο
            // token να λήξει ή να διαρρεύσει, και ο SigningController που
            // εξυπηρετούσε το παλιό διαγράφηκε μαζί με τη σελίδα του.
            new SignLinkController(
                $scope,
                $details,
                Services::documents(),
                $lifecycle,
                Services::events(),
                Services::signatureState(),
                $draftExit
            ),

            // People.
            new CustomersController(
                $scope,
                Services::customers(),
                $queries,
                Services::files(),
                Services::customerNotes(),
                Services::customerEvents(),
                Services::printedFields()
            ),
            new LeadsController(
                $scope,
                Services::leads(),
                Services::contracts(),
                Services::customers(),
                Services::siteLeads(),
                Services::providers(),
            ),

            // Αιτήσεις από το site σύγκρισης τιμών (310). Χωρίς login: υπογραφή HMAC.
            new SiteIntakeController(
                Services::siteIntakeConfig(),
                Services::siteLeads(),
                Services::notifications(),
            ),
            // (448) Υποψήφιοι από το AI τηλεφωνικό κέντρο. Χωρίς login: λίστα IP + υπογραφή HMAC.
            new GatewayIntakeController(
                Services::gatewayIntakeConfig(),
                new GatewayLeadRepository(),
                Services::notifications(),
            ),
            // (449) «Δεν θέλει κλήσεις»: από τον πωλητή προς το Gateway.
            new DoNotCallController(
                $scope,
                Services::leads(),
                Services::gatewayEventOutbox(),
            ),
            new TeamController(
                $scope,
                Services::team(),
                Services::contracts(),
                Services::leads(),
                Services::tasks(),
                Services::partnerCard(),
                Services::commissions(),
                new TeamInvite(),
                Services::providerVisibility(),
                Services::providerGrants(),
            ),
            // (352c) «Χωρίς ομάδα»: όσοι φτιάχτηκαν από το WordPress.
            new TeamPlacementController(Services::team()),
            // Ποιους παρόχους βλέπει κάθε μέλος (278).
            new ProviderAccessController(
                $scope,
                Services::providerVisibility(),
                Services::providerGrants(),
                Services::providers(),
                Services::team(),
            ),
            new TeamActivityController($scope, Services::teamActivity()),

            // Reporting.
            new DashboardController($scope, Services::dashboard(), new StoreRepository(), new TargetRepository()),
            new CommissionsController($scope, Services::commissions()),
            new PayoutsController($scope, Services::payouts()),
            new AnalyticsController($scope, Services::analytics()),
            new ProviderStatusMapController(new ProviderStatusMapRepository()),

            // Work management.
            new TasksController($scope, Services::tasks(), Services::contracts()),
            new NotificationsController($scope),
            new AssistantHistoryController($scope, Services::assistantHistory()),
            new SavedFiltersController($scope),
            new ThemeController($scope),
            // (353) Νέα εμφάνιση «Signature», σε δοκιμή από τον διαχειριστή.
            new UiController(),
            new ProfileController(),
            new ProfilePhotoController(Services::profilePhoto()),
            // (388) «Το προφίλ μου → Ασφάλεια».
            new SecurityController(new UserSessions()),
            // (406) Σύνδεση σε δύο βήματα, προαιρετική.
            new TwoFactorController(TwoFactor::make()),
            // (394) Αναφορές CSP από τον browser (δημόσιο, με όριο).
            new CspReportController(new CspReports()),

            // Catalogue and form metadata.
            new CatalogueController(
                $scope,
                Services::providers(),
                $queries,
                new UsualChoiceRepository(),
                Services::providerVisibility(),
                Services::contracts(),
            ),
            new ProviderFormController(),

            // Tools.
            new ImportController(),
            new ExtractionController(
                Services::extractionGate(),
                $scope,
                Services::contracts(),
                Services::files(),
                Services::customers(),
                Services::leads(),
                Services::printedFields()
            ),
            new VatLookupController(Services::aade()),
            // (331) «Τι λέω στον πελάτη».
            new SalesScriptController($scope, Services::providerVisibility(), Services::providers()),
            // (346) Ιστορικό πελάτη σε μία οθόνη.
            new CustomerHistoryController(
                $scope,
                Services::customers(),
                $queries,
                new CustomerHistoryQueries(),
                Services::tasks(),
                Services::customerNotes(),
                Services::customerEvents(),
            ),
            // (333) «Δώσε του και αέριο / ρεύμα».
            new CrossSellController(
                $scope,
                new CrossSellQueries(),
                Services::customers(),
                Services::customerEvents(),
                Services::providers(),
            ),
            // (339) Αναφορές προβλημάτων.
            new TicketsController(
                $scope,
                Services::tickets(),
                Services::ticketAttachments(),
                Services::ticketNotices(),
                Services::team(),
            ),
            // (338) Κολλημένες αιτήσεις.
            $stuck,
            // (343) Η καρτέλα «Κατάστημα» στην Αρχική.
            new StoreDashboardController(
                $scope,
                new StoreRepository(),
                Services::teamActivity(),
                Services::team(),
                $queries,
                $stuck,
                new TargetRepository(),
                Services::storeMonthReport(),
            ),
            // (332) Σωστό μέγεθος Picasso.
            new PackageFitController($scope, Services::providerVisibility(), Services::providers()),
            // (328) Πρόταση προγράμματος από λογαριασμό.
            new BillAdvisorController(
                Services::extractionGate(),
                $scope,
                Services::providerVisibility(),
                Services::providers(),
            ),
            new QuoteController(Services::profilePhoto()),
            new GuaranteeSuggestController(new GuaranteeRuleRepository(), Services::providers()),
            new PostalSuggestController(),
        ];
    }
}
