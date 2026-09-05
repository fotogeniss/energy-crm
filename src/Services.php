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
use EnergyCRM\Domain\Contract\AutoProcess;
use EnergyCRM\Domain\Contract\CancellationGate;
use EnergyCRM\Domain\Contract\DeletionGate;
use EnergyCRM\Domain\Contract\ContractLifecycle;
use EnergyCRM\Infrastructure\ContractDocuments;
use EnergyCRM\Infrastructure\ContractNotices;
use EnergyCRM\Infrastructure\RejectionFollowUp;
use EnergyCRM\Infrastructure\DocumentQueue;
use EnergyCRM\Infrastructure\DraftExitGate;
use EnergyCRM\Infrastructure\DocumentKindReview;
use EnergyCRM\Infrastructure\ExtractionGate;
use EnergyCRM\Infrastructure\ProviderFormRenderer;
use EnergyCRM\Infrastructure\SecretStore;
use EnergyCRM\Infrastructure\SignatureState;
use EnergyCRM\Persistence\AnalyticsRepository;
use EnergyCRM\Persistence\AssistantHistoryRepository;
use EnergyCRM\Persistence\CommissionRepository;
use EnergyCRM\Persistence\ContractDetails;
use EnergyCRM\Persistence\ContractQueries;
use EnergyCRM\Persistence\ContractRepository;
use EnergyCRM\Persistence\ContractTransitions;
use EnergyCRM\Persistence\CustomerRepository;
use EnergyCRM\Persistence\CustomerNoteRepository;
use EnergyCRM\Persistence\DashboardRepository;
use EnergyCRM\Persistence\DeletionLogRepository;
use EnergyCRM\Persistence\EventRepository;
use EnergyCRM\Persistence\StatusDwellRepository;
use EnergyCRM\Persistence\DocumentStorage;
use EnergyCRM\Persistence\FileRepository;
use EnergyCRM\Persistence\LeadRepository;
use EnergyCRM\Persistence\NetworkRepository;
use EnergyCRM\Persistence\NotificationRepository;
use EnergyCRM\Persistence\PartnerCardRepository;
use EnergyCRM\Persistence\PayoutRepository;
use EnergyCRM\Persistence\ProviderRepository;
use EnergyCRM\Persistence\TaskRepository;
use EnergyCRM\Persistence\UnprotectedDocuments;
use EnergyCRM\Persistence\TeamActivityRepository;
use EnergyCRM\Persistence\TeamRepository;

final class Services
{
    private static ?ScopeResolver $scopeResolver = null;

    private static ?ContractRepository $contracts = null;

    private static ?CustomerRepository $customers = null;

    private static ?CustomerNoteRepository $customerNotes = null;

    private static ?NetworkRepository $network = null;

    private static ?FileRepository $files = null;

    private static ?SignatureState $signatureState = null;

    private static ?UnprotectedDocuments $unprotectedDocuments = null;

    private static ?SecretStore $secrets = null;

    private static ?TaskRepository $tasks = null;

    private static ?EventRepository $events = null;
    private static ?DeletionLogRepository $deletionLog = null;

    private static ?StatusDwellRepository $statusDwell = null;

    private static ?LeadRepository $leads = null;

    private static ?PartnerCardRepository $partnerCard = null;

    private static ?TeamRepository $team = null;

    private static ?ProviderRepository $providers = null;

    private static ?DashboardRepository $dashboard = null;

    private static ?CommissionRepository $commissions = null;

    private static ?PayoutRepository $payouts = null;
    private static ?AssistantHistoryRepository $assistantHistory = null;

    private static ?AnalyticsRepository $analytics = null;

    private static ?TeamActivityRepository $teamActivity = null;

    private static ?DocumentQueue $documents = null;

    private static ?ContractDocuments $contractDocuments = null;

    private static ?ContractNotices $contractNotices = null;

    private static ?ContractQueries $contractQueries = null;

    private static ?ContractDetails $contractDetails = null;

    private static ?ContractTransitions $contractTransitions = null;

    private static ?NotificationRepository $notifications = null;

    private static ?ExtractionGate $extractionGate = null;

    private static ?DocumentKindReview $documentKindReview = null;

    private static ?DraftExitGate $draftExitGate = null;

    private static ?CancellationGate $cancellationGate = null;

    private static ?DeletionGate $deletionGate = null;

    private static ?ContractLifecycle $lifecycle = null;

    private static ?AutoProcess $autoProcess = null;

    private static ?RejectionFollowUp $rejectionFollowUp = null;

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

    /**
     * Ποιοι ρόλοι απαιτούνται/έχουν υπογράψει, για μια σύμβαση. Δες
     * `SignatureState` -- το μόνο σημείο που ενώνει το `SignatureRoles`
     * (κανόνας) με το `FileRepository` (ποιος έχει ήδη υπογράψει).
     */
    public static function signatureState(): SignatureState
    {
        return self::$signatureState ??= new SignatureState(self::files());
    }

    /**
     * Η μετακόμιση των παλαιών εγγράφων από τη media library.
     *
     * Δικός της provider και όχι μέσω του `files()`: είναι η μόνη υπηρεσία με
     * ημερομηνία λήξης — όταν κάθε site αδειάσει το backlog του, σβήνεται
     * ολόκληρη, και τότε αυτή η γραμμή είναι το μόνο που πρέπει να φύγει.
     */
    public static function unprotectedDocuments(): UnprotectedDocuments
    {
        return self::$unprotectedDocuments ??= new UnprotectedDocuments(
            new DocumentStorage(\ECRM_Files::dir())
        );
    }

    public static function extractionGate(): ExtractionGate
    {
        return self::$extractionGate ??= new ExtractionGate();
    }

    public static function documentKindReview(): DocumentKindReview
    {
        return self::$documentKindReview ??= new DocumentKindReview(
            self::files(),
            self::extractionGate(),
            self::events()
        );
    }

    public static function draftExitGate(): DraftExitGate
    {
        return self::$draftExitGate ??= new DraftExitGate(self::customers());
    }

    public static function cancellationGate(): CancellationGate
    {
        return self::$cancellationGate ??= new CancellationGate(self::events(), self::payouts());
    }

    public static function deletionGate(): DeletionGate
    {
        return self::$deletionGate ??= new DeletionGate(self::events());
    }

    public static function documents(): DocumentQueue
    {
        return self::$documents ??= new DocumentQueue(self::files(), self::contractDocuments());
    }

    public static function contractDocuments(): ContractDocuments
    {
        return self::$contractDocuments ??= new ContractDocuments(
            self::contractDetails(),
            self::files(),
            new ProviderFormRenderer()
        );
    }

    public static function contractNotices(): ContractNotices
    {
        return self::$contractNotices ??= new ContractNotices(
            self::contractDetails(),
            self::notifications(),
            self::network()
        );
    }

    public static function notifications(): NotificationRepository
    {
        return self::$notifications ??= new NotificationRepository();
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

    public static function payouts(): PayoutRepository
    {
        return self::$payouts ??= new PayoutRepository();
    }

    public static function assistantHistory(): AssistantHistoryRepository
    {
        return self::$assistantHistory ??= new AssistantHistoryRepository();
    }

    public static function dashboard(): DashboardRepository
    {
        return self::$dashboard ??= new DashboardRepository();
    }

    public static function providers(): ProviderRepository
    {
        return self::$providers ??= new ProviderRepository();
    }

    public static function partnerCard(): PartnerCardRepository
    {
        return self::$partnerCard ??= new PartnerCardRepository();
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

    public static function deletionLog(): DeletionLogRepository
    {
        return self::$deletionLog ??= new DeletionLogRepository();
    }

    public static function statusDwell(): StatusDwellRepository
    {
        return self::$statusDwell ??= new StatusDwellRepository();
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

    /** The list queries behind the screens. */
    public static function contractQueries(): ContractQueries
    {
        return self::$contractQueries ??= new ContractQueries();
    }

    /**
     * The joined view of one contract — and the only copy of that join.
     *
     * Handed out on its own so that a caller which only needs the join does not
     * receive the whole repository, writes included.
     */
    public static function contractDetails(): ContractDetails
    {
        return self::$contractDetails ??= new ContractDetails();
    }

    /** The status rows and the cron sweep. Takes no UserScope — see ARCHITECTURE. */
    public static function contractTransitions(): ContractTransitions
    {
        return self::$contractTransitions ??= new ContractTransitions();
    }

    public static function lifecycle(): ContractLifecycle
    {
        return self::$lifecycle ??= new ContractLifecycle(
            self::contractTransitions(),
            self::events(),
            self::cancellationGate(),
            self::payouts(),
        );
    }

    public static function autoProcess(): AutoProcess
    {
        return self::$autoProcess ??= new AutoProcess(self::contractTransitions(), self::lifecycle());
    }

    public static function rejectionFollowUp(): RejectionFollowUp
    {
        return self::$rejectionFollowUp ??= new RejectionFollowUp(
            self::contractDetails(),
            self::tasks(),
        );
    }

    public static function customers(): CustomerRepository
    {
        return self::$customers ??= new CustomerRepository();
    }

    public static function customerNotes(): CustomerNoteRepository
    {
        return self::$customerNotes ??= new CustomerNoteRepository();
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
        self::$customerNotes = null;
        self::$network       = null;
        self::$files         = null;
        self::$unprotectedDocuments = null;
        self::$secrets       = null;
        self::$tasks         = null;
        self::$events        = null;
        self::$leads         = null;
        self::$team          = null;
        self::$partnerCard   = null;
        self::$providers     = null;
        self::$dashboard     = null;
        self::$commissions   = null;
        self::$analytics     = null;
        self::$teamActivity  = null;
        self::$documents         = null;
        self::$contractDocuments = null;
        self::$extractionGate    = null;
        self::$lifecycle         = null;
        self::$autoProcess       = null;
        // Οι δύο πύλες κρατούν repositories που μηδενίζονται από πάνω· χωρίς
        // αυτές τις δύο γραμμές ένα reset() άφηνε πίσω αντικείμενα που
        // δείχνουν σε παλιά.
        self::$draftExitGate     = null;
        self::$cancellationGate  = null;
        self::$deletionGate      = null;
        self::$payouts           = null;
        self::$assistantHistory  = null;
        self::$deletionLog       = null;
    }
}
