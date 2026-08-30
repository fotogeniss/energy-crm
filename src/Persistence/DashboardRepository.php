<?php

/**
 * The counts behind the dashboard, for one partner.
 *
 * Everything here is scoped to a single user id rather than a UserScope: the
 * dashboard is deliberately personal — "my day", not "my team's" — and the team
 * view lives under analytics.
 *
 * See ContractRepository for the note on the phpcs exemptions.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence;

use ECRM_Commissions;
use EnergyCRM\Domain\Commission\CommissionAmount;

final class DashboardRepository
{
    /**
     * Οι καταστάσεις όπου η σύμβαση περιμένει ΤΟΝ ΣΥΝΕΡΓΑΤΗ, όχι τον πάροχο.
     *
     * `routed` και `processing` λείπουν επίτηδες: εκεί η μπάλα είναι στον
     * πάροχο και ο συνεργάτης δεν έχει τι να κάνει. Το dashboard δείχνει
     * δουλειά, όχι αναμονή.
     *
     * AUDIT 30/08: `pending_signature` έλειπε. Δεν είναι δεύτερο «περιμένει
     * τον πάροχο» -- είναι ο πάροχος που ΓΥΡΝΑΕΙ πίσω την αίτηση ζητώντας
     * νέα υπογραφή (βλ. `ContractStatus::allowedNext()`, το σχόλιο πάνω
     * από το `Routed`), και η μόνη ενέργεια που την προχωράει είναι του
     * συνεργάτη -- να ξαναστείλει τον σύνδεσμο υπογραφής
     * (`SignLinkController::create()`). Ίδια κατηγορία με το
     * `awaiting_signature`, απλά διαφορετικό σημείο εισόδου στον γράφο.
     *
     * @var list<string>
     */
    private const NEEDS_ME = ['pending', 'awaiting_signature', 'pending_signature', 'draft'];

    /**
     * Οι στατικές καταστάσεις που ΔΕΝ μετρούν ως «ανοιχτή» αίτηση: η
     * `active` ολοκλήρωσε την πορεία της (πάει στο «Κλεισμένες»), οι δύο
     * τερματικές δεν μετρούν πουθενά. Ίδια λίστα με το
     * `ContractStatus::isTerminal()` συν την `active`, αλλά εδώ γραμμένη ως
     * τιμές SQL — το enum ζει στο Domain, εδώ ζει η Persistence, και δεν
     * χρειάζεται τρίτο επίπεδο για τέσσερις λέξεις.
     *
     * @var list<string>
     */
    private const NOT_OPEN = ['active', 'cancelled', 'terminated'];

    private CustomerFields $fields;

    public function __construct(?CustomerFields $fields = null)
    {
        $this->fields = $fields ?? CustomerFields::default();
    }

    /**
     * @return array{today: int, pending: int, routed: int, month: int}
     */
    public function cards(int $userId, string $todayStart, string $monthStart, string $yesterdayStart): array
    {
        return [
            'today'   => $this->countSince($userId, $todayStart),
            'pending' => $this->countWithStatus($userId, 'pending'),
            'routed'  => $this->countWithStatus($userId, 'routed'),
            'month'   => $this->countSince($userId, $monthStart),

            // Χθες, για τη μεταβολή της κάρτας «Σήμερα». Είναι ΡΟΗ — γεγονότα
            // μέσα σε περίοδο — οπότε η σύγκριση έχει νόημα και ο αριθμός
            // υπάρχει. Οι άλλες δύο κάρτες ΔΕΝ παίρνουν μεταβολή, και ο λόγος
            // είναι γραμμένος στην oldestPerStatus() παρακάτω.
            'yesterday' => $this->countBetween($userId, $yesterdayStart, $todayStart),

            'oldest'    => $this->oldestPerStatus($userId, ['pending', 'routed']),
        ];
    }

    /**
     * Πόσες συμβάσεις άνοιξαν μέσα σε ένα κλειστό παράθυρο.
     *
     * Ξεχωριστή από την countSince() επειδή θέλει ΚΑΙ πάνω ΚΑΙ κάτω όριο: η
     * countSince($yesterdayStart) θα μετρούσε και τις σημερινές.
     */
    private function countBetween(int $userId, string $from, string $to): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE partner_user_id = %d AND created_at >= %s AND created_at < %s',
                Tables::name(Tables::CONTRACTS),
                $userId,
                $from,
                $to
            )
        );
    }

    /**
     * Πόσες μέρες κάθεται η παλαιότερη σύμβαση σε κάθε κατάσταση.
     *
     * ## Γιατί ηλικία και όχι «έναντι προηγούμενου μήνα»
     *
     * Οι κάρτες «Εκκρεμότητες» και «Δρομολογήθηκαν» μετρούν ΑΠΟΘΕΜΑ: πόσες
     * είναι ΤΩΡΑ σε αυτή την κατάσταση. Το `status` είναι τρέχουσα τιμή στη
     * γραμμή, όχι ιστορικό — η βάση δεν ξέρει πόσες ήταν σε εκκρεμότητα στις 31
     * Ιουλίου, γιατί καμία στήλη δεν το κράτησε. Ένα «↑3» εκεί δεν θα ήταν
     * μέτρηση αλλά ισχυρισμός για τον φόρτο ενός ανθρώπου.
     *
     * Ανακατασκευάζεται θεωρητικά από τα `events` (κρατούν `to_status` και
     * `created_at`), αλλά είναι ερώτημα με παράθυρο σε διαδρομή που φορτώνει σε
     * κάθε είσοδο — και θα ήταν σωστό μόνο από το go-live και μετά, όπως γράφει
     * ήδη το CancellationGate για το ίδιο ιστορικό. Απόφαση ιδιοκτήτη 21/08:
     * εκδοχή Β, docs/UI-KPI-DELTA.html.
     *
     * ## Δύο πράγματα που πρέπει να ξέρει ο επόμενος
     *
     * **Μετριέται από το `updated_at`, όχι το `created_at`.** Η ερώτηση δεν
     * είναι «πόσο παλιά είναι η σύμβαση» αλλά «πόσο καιρό κάθεται χωρίς
     * κίνηση». Το `updated_at` έχει `ON UPDATE CURRENT_TIMESTAMP`, οπότε
     * οποιαδήποτε επεξεργασία το ανανεώνει — δηλαδή μετράει ακριβώς «πόσο
     * καιρό δεν την άγγιξε κανείς», που είναι και το ζητούμενο.
     *
     * **Η οθόνη το έλεγε λάθος μέχρι τις 22/08.** Το `ageFoot()` έγραφε «η
     * παλαιότερη μπήκε σήμερα» — δηλαδή ισχυριζόταν χρόνο ΣΤΗΝ ΚΑΤΑΣΤΑΣΗ, ενώ
     * εδώ μετριέται χρόνος ΧΩΡΙΣ ΚΙΝΗΣΗ. Αίτηση 30 μερών σε εκκρεμότητα που
     * κάποιος διόρθωσε σήμερα εμφανιζόταν ως «μπήκε σήμερα», δηλαδή η κάρτα που
     * υπάρχει για να δείχνει τι σαπίζει έκρυβε ακριβώς αυτό. Η μέτρηση δεν
     * άλλαξε· τα λόγια ναι. Αν κάποτε χρειαστεί ο πραγματικός χρόνος στην
     * κατάσταση, πηγή είναι τα γεγονότα (`to_status`), όχι αυτή η στήλη.
     *
     * **Οι μέρες βγαίνουν από τη ΒΑΣΗ, όχι από τη JavaScript.** Ένα timestamp
     * που ταξιδεύει ως συμβολοσειρά και ερμηνεύεται από τον browser είναι
     * ακριβώς η παγίδα της (72): το `created_at`/`updated_at` γράφεται σε ώρα
     * site, ενώ οι fmtDate()/timeAgo() διαβάζουν UTC. Ένας ακέραιος αριθμός
     * ημερών δεν έχει ζώνη ώρας.
     *
     * @param list<string> $statuses
     *
     * @return array<string, int|null> status => μέρες, ή null αν δεν υπάρχει καμία
     */
    private function oldestPerStatus(int $userId, array $statuses): array
    {
        global $wpdb;

        $out = [];

        foreach ($statuses as $status) {
            $days = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT DATEDIFF(NOW(), MIN(updated_at)) FROM %i
                     WHERE partner_user_id = %d AND status = %s',
                    Tables::name(Tables::CONTRACTS),
                    $userId,
                    $status
                )
            );

            // MIN() πάνω σε κενό σύνολο δίνει NULL, και το DATEDIFF το περνά.
            // Το null είναι η ειλικρινής τιμή: ένα 0 θα διαβαζόταν «μπήκε
            // σήμερα» εκεί που δεν υπάρχει τίποτα.
            $out[$status] = null === $days ? null : (int) $days;
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function byProviderSince(int $userId, string $since): array
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT p.name, COUNT(*) c
                 FROM %i ct LEFT JOIN %i p ON p.id = ct.provider_id
                 WHERE ct.partner_user_id = %d AND ct.created_at >= %s
                 GROUP BY ct.provider_id ORDER BY c DESC',
                Tables::name(Tables::CONTRACTS),
                Tables::name(Tables::PROVIDERS),
                $userId,
                $since
            ),
            ARRAY_A
        );

        return $rows;
    }

    /**
     * Contracts per month for a year, indexed 1-12 with gaps filled.
     *
     * @return array<int, int>
     */
    public function monthlyTotals(int $userId, int $year): array
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT MONTH(created_at) m, COUNT(*) c FROM %i
                 WHERE partner_user_id = %d AND YEAR(created_at) = %d
                 GROUP BY MONTH(created_at)',
                Tables::name(Tables::CONTRACTS),
                $userId,
                $year
            ),
            ARRAY_A
        );

        $monthly = array_fill(1, 12, 0);

        foreach ($rows as $row) {
            $monthly[(int) $row['m']] = (int) $row['c'];
        }

        return $monthly;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentActivity(int $userId, int $limit = 8): array
    {
        global $wpdb;

        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT e.type, e.to_status, e.message, e.created_at, c.code
                 FROM %i e LEFT JOIN %i c ON c.id = e.contract_id
                 WHERE c.partner_user_id = %d
                 ORDER BY e.created_at DESC LIMIT %d',
                Tables::name(Tables::EVENTS),
                Tables::name(Tables::CONTRACTS),
                $userId,
                max(1, $limit)
            ),
            ARRAY_A
        );

        return $rows;
    }

    /**
     * Οι συμβάσεις που περιμένουν τον συνεργάτη, οι πιο στάσιμες πρώτα.
     *
     * Το dashboard μέχρι τώρα έλεγε «7 εκκρεμότητες» και τίποτα άλλο: ένας
     * αριθμός που δεν οδηγεί πουθενά. Ο συνεργάτης έπρεπε να πάει στη λίστα,
     * να φιλτράρει και να μαντέψει ποια είναι η επείγουσα.
     *
     * **Η σειρά είναι κατά παλαιότητα, όχι κατά κατάσταση**, και είναι
     * συνειδητό: μια εκκρεμότητα δύο ημερών δεν είναι πιο επείγουσα από ένα
     * πρόχειρο τριών εβδομάδων που κανείς δεν άγγιξε. Το `updated_at` ξέρει
     * ποιο ξεχάστηκε.
     *
     * Δύο ερωτήματα και όχι ένα με prefixes: τα ονόματα πελατών είναι
     * κρυπτογραφημένα και πρέπει να περάσουν από `CustomerFields::fromStorage`,
     * που περιμένει σχήμα ΠΕΛΑΤΗ. Ανακατεμένες στήλες σε ένα join θα το
     * έσπαγαν σιωπηλά — και σιωπηλά σημαίνει κρυπτοκείμενο στην οθόνη.
     *
     * @return list<array<string, mixed>>
     */
    public function needsAttention(int $userId, int $limit = 5): array
    {
        global $wpdb;

        $limit = max(1, min(20, $limit));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT c.id, c.code, c.status, c.customer_id, c.updated_at, p.name provider,
                        DATEDIFF(UTC_TIMESTAMP(), c.updated_at) days
                 FROM %i c LEFT JOIN %i p ON p.id = c.provider_id
                 WHERE c.partner_user_id = %d AND c.status IN (%s, %s, %s, %s)
                 ORDER BY c.updated_at ASC LIMIT %d',
                [
                    Tables::name(Tables::CONTRACTS),
                    Tables::name(Tables::PROVIDERS),
                    $userId,
                    ...self::NEEDS_ME,
                    $limit,
                ]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        if ($rows === []) {
            return [];
        }

        return $this->withCustomerNames($rows);
    }

    /**
     * @param  list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withCustomerNames(array $rows): array
    {
        global $wpdb;

        $ids = [];

        foreach ($rows as $row) {
            $id = (int) ($row['customer_id'] ?? 0);

            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        $byId = [];

        if ($ids !== []) {
            $keys = array_keys($ids);

            // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
            /** @var list<array<string, mixed>> $people */
            $people = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM %i WHERE id IN (' . implode(',', array_fill(0, count($keys), '%d')) . ')',
                    [Tables::name(Tables::CUSTOMERS), ...$keys]
                ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

            foreach ($this->fields->fromStorageAll($people) as $person) {
                $byId[(int) ($person['id'] ?? 0)] = $person;
            }
        }

        $out = [];

        foreach ($rows as $row) {
            $person = $byId[(int) ($row['customer_id'] ?? 0)] ?? [];

            $name = trim((string) ($person['company_name'] ?? ''));

            if ($name === '') {
                $name = trim(
                    (string) ($person['first_name'] ?? '') . ' ' . (string) ($person['last_name'] ?? '')
                );
            }

            $row['customer'] = $name;

            // Ένα πρόχειρο χωρίς ΑΦΜ ΔΕΝ οριστικοποιείται — το λέει ο
            // DraftExitGate — και είναι η μόνη περίπτωση όπου η οθόνη μπορεί να
            // πει στον συνεργάτη τι ακριβώς λείπει αντί για «δες το».
            $row['blocked_no_afm'] = ($row['status'] ?? '') === 'draft'
                && trim((string) ($person['afm'] ?? '')) === '';

            unset($row['customer_id']);

            $out[] = $row;
        }

        return $out;
    }

    private function countSince(int $userId, string $since): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE partner_user_id = %d AND created_at >= %s',
                Tables::name(Tables::CONTRACTS),
                $userId,
                $since
            )
        );
    }

    private function countWithStatus(int $userId, string $status): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM %i WHERE partner_user_id = %d AND status = %s',
                Tables::name(Tables::CONTRACTS),
                $userId,
                $status
            )
        );
    }

    /**
     * Τα τέσσερα πλακίδια της πρώτης οθόνης — απόφαση ιδιοκτήτη 25/08/2026,
     * ευθυγράμμιση με το `docs/UI-UX-KIT.html` A1 (δες
     * `docs/UI-DASHBOARD-VS-KIT.html`). Ένα ερώτημα ανά πλακίδιο, όχι ένα
     * μεγάλο join — το καθένα μετράει διαφορετικό πράγμα (απόθεμα, απόθεμα με
     * προθεσμία, ροή ενός μήνα, εργασίες) και η ένωσή τους σε ένα ερώτημα θα
     * έκρυβε ποιο μετράει τι.
     *
     * @return array{
     *     open: int,
     *     open_this_week: int,
     *     awaiting_signature: int,
     *     expiring_today: int,
     *     closed_month: int,
     *     closed_month_commission: float,
     *     tasks_open: int,
     *     tasks_overdue: int
     * }
     */
    public function tiles(int $userId, string $monthStart): array
    {
        [$closedCount, $closedCommission] = $this->closedThisMonth($userId, $monthStart);

        return [
            'open'                    => $this->countOpen($userId),
            'open_this_week'          => $this->countOpenedThisWeek($userId),
            'awaiting_signature'      => $this->countWithStatuses(
                $userId,
                ['pending_signature', 'awaiting_signature']
            ),
            'expiring_today'          => $this->countExpiringToday($userId),
            'closed_month'            => $closedCount,
            'closed_month_commission' => $closedCommission,
            'tasks_open'              => $this->countOpenTasks($userId),
            'tasks_overdue'           => $this->countOverdueTasks($userId),
        ];
    }

    /** «Ανοιχτές αιτήσεις» — ό,τι δεν έκλεισε ακόμα, με τη μία ή την άλλη έννοια. */
    private function countOpen(int $userId): int
    {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count(self::NOT_OPEN), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE partner_user_id = %d AND status NOT IN ({$placeholders})",
                [Tables::name(Tables::CONTRACTS), $userId, ...self::NOT_OPEN]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
    }

    /**
     * Πόσες από τις ανοιχτές μπήκαν τις τελευταίες επτά ημέρες — ο υπότιτλος
     * «↑ N αυτή την εβδομάδα» του πρώτου πλακιδίου (kit A1).
     *
     * Επτά ημέρες ΚΥΛΙΟΜΕΝΕΣ και όχι «από τη Δευτέρα»: το νούμερο από πάνω
     * είναι απόθεμα χωρίς αρχή και τέλος, οπότε ένας υπότιτλος που μηδενίζεται
     * κάθε Δευτέρα πρωί θα έδειχνε βουτιά εκεί που δεν συνέβη τίποτα.
     *
     * Ίδιο φίλτρο `NOT_OPEN` με το countOpen(), σκόπιμα: ο υπότιτλος πρέπει να
     * είναι ΥΠΟΣΥΝΟΛΟ του νούμερου που επιγράφει. Μια αίτηση που μπήκε και
     * ακυρώθηκε μέσα στην ίδια εβδομάδα δεν μετράει σε κανένα από τα δύο —
     * αλλιώς το «↑ 12» θα μπορούσε να βγει μεγαλύτερο από το ίδιο το σύνολο.
     *
     * `NOW()` και όχι `UTC_TIMESTAMP()`: η στήλη `created_at` γεμίζει από
     * `DEFAULT CURRENT_TIMESTAMP` (δες `class-ecrm-db.php`), δηλαδή από το
     * ρολόι του ίδιου του server — σύγκριση με UTC θα μετατόπιζε το παράθυρο
     * κατά τη ζώνη της βάσης.
     */
    private function countOpenedThisWeek(int $userId): int
    {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count(self::NOT_OPEN), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i
                 WHERE partner_user_id = %d
                   AND status NOT IN ({$placeholders})
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)",
                [Tables::name(Tables::CONTRACTS), $userId, ...self::NOT_OPEN]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
    }

    /**
     * @param list<string> $statuses
     */
    private function countWithStatuses(int $userId, array $statuses): int
    {
        global $wpdb;

        if ($statuses === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($statuses), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE partner_user_id = %d AND status IN ({$placeholders})",
                [Tables::name(Tables::CONTRACTS), $userId, ...$statuses]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
    }

    /**
     * Πόσες αιτήσεις σε αναμονή υπογραφής λήγουν ΣΗΜΕΡΑ.
     *
     * Δεν υπάρχει στήλη λήξης — το ρολόι είναι υπολογισμένο, ξεκινά από το
     * τελευταίο `sign_sent_*` γεγονός κάθε σύμβασης και κλείνει
     * `ECRM_Tracking::SIGN_WINDOW_HOURS` (48) ώρες μετά, ίδια λογική με το
     * `ECRM_Tracking::sign_expired()` που ήδη ελέγχει ΜΙΑ σύμβαση. Εδώ
     * χρειάζεται μέτρημα σε πολλές, οπότε ξαναγράφεται σε SQL αντί να καλείται
     * σε βρόχο PHP ανά αίτηση.
     *
     * «Σήμερα» σημαίνει: η λήξη πέφτει μέσα στη σημερινή ημερολογιακή μέρα ΚΑΙ
     * δεν έχει περάσει ακόμα — μια ήδη ληγμένη αίτηση δεν «λήγει σήμερα», έχει
     * ήδη λήξει, και δεν έχει νόημα να μπει στην ίδια μέτρηση.
     */
    private function countExpiringToday(int $userId): int
    {
        global $wpdb;

        $sendEvents = ['sign_sent_sms', 'sign_sent_email', 'sign_sent_link'];
        $statuses   = ['pending_signature', 'awaiting_signature'];

        $eventPh  = implode(',', array_fill(0, count($sendEvents), '%s'));
        $statusPh = implode(',', array_fill(0, count($statuses), '%s'));

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM (
                     SELECT MAX(e.created_at) last_sent
                     FROM %i c
                     JOIN %i e ON e.contract_id = c.id AND e.type IN ({$eventPh})
                     WHERE c.partner_user_id = %d AND c.status IN ({$statusPh})
                     GROUP BY c.id
                 ) sent
                 WHERE DATE(DATE_ADD(last_sent, INTERVAL 48 HOUR)) = CURDATE()
                   AND DATE_ADD(last_sent, INTERVAL 48 HOUR) >= NOW()",
                [
                    Tables::name(Tables::CONTRACTS),
                    Tables::name(Tables::EVENTS),
                    ...$sendEvents,
                    $userId,
                    ...$statuses,
                ]
            )
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
    }

    /**
     * Πόσες συμβάσεις έγιναν `active` μέσα στον μήνα, και η προμήθειά τους.
     *
     * «Έγιναν active», όχι «είναι active»: ψάχνει το γεγονός `to_status =
     * active` μέσα στο παράθυρο, όχι μόνο τη σημερινή στήλη `status` — αλλιώς
     * μια σύμβαση που έγινε ενεργή τον προηγούμενο μήνα θα μετρούσε κάθε μήνα
     * μετά, για πάντα. Φιλτράρεται ΚΑΙ σε `status = 'active' ΤΩΡΑ`, ώστε μια
     * σύμβαση που έγινε ενεργή και μετά ακυρώθηκε να μη μείνει στο «κλεισμένες
     * με προμήθεια» — η προμήθεια διαβάζεται από τη ΣΗΜΕΡΙΝΗ στήλη
     * `payout_amount`, όχι από ιστορικό.
     *
     * Το άθροισμα γίνεται σε PHP και όχι με SQL SUM(): μια σύμβαση μπορεί να
     * έχει περισσότερα από ένα γεγονός `to_status=active` μέσα στο ίδιο
     * παράθυρο (π.χ. active → pending → active ξανά), και ένα JOIN θα την
     * μέτραγε τόσες φορές όσα τα γεγονότα — το ίδιο λάθος διπλομέτρησης που
     * προειδοποιεί το σχόλιο του `CommissionRepository::payable()`.
     *
     * @return array{0: int, 1: float}
     */
    private function closedThisMonth(int $userId, string $monthStart): array
    {
        global $wpdb;

        // phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders
        /** @var list<array<string, mixed>> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT DISTINCT c.id, c.payout_amount, c.provider_id, c.program_id,
                        c.energy_type, c.category, c.status
                 FROM %i c
                 WHERE c.partner_user_id = %d AND c.status = %s
                   AND c.id IN (
                       SELECT e.contract_id FROM %i e
                       WHERE e.to_status = %s AND e.created_at >= %s
                   )',
                [
                    Tables::name(Tables::CONTRACTS),
                    $userId,
                    'active',
                    Tables::name(Tables::EVENTS),
                    'active',
                    $monthStart,
                ]
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders

        // Πριν 25/08 (build queue 04): διάβαζε κατευθείαν το payout_amount, που
        // είναι NULL για κάθε σύμβαση που δεν έχει μπει ακόμα σε εκκαθάριση —
        // δηλαδή η πλειοψηφία των «κλεισμένων τον μήνα». Το πλακίδιο έδειχνε
        // λιγότερα χρήματα απ' όσα η ίδια η οθόνη Προμηθειών, για τις ίδιες
        // ακριβώς συμβάσεις. Ο CommissionAmount::of() είναι το ένα σημείο που
        // αποφασίζει «στιγμιότυπο ή ζωντανός υπολογισμός» — το ίδιο που ήδη
        // χρησιμοποιούν CommissionsController/TeamController/AnalyticsController.
        $commission = 0.0;

        foreach ($rows as $row) {
            $commission += CommissionAmount::of($row, [ECRM_Commissions::class, 'amount_for']);
        }

        return [count($rows), $commission];
    }

    /** «Εργασίες μου» — προσωπικές, όπως όλη η υπόλοιπη οθόνη. */
    private function countOpenTasks(int $userId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i WHERE assigned_to = %d AND status = 'open'",
                Tables::name(Tables::TASKS),
                $userId
            )
        );
    }

    /** Ίδιο ορισμός εκπρόθεσμου με το `TaskRepository::search()` — μη ζόρι πριν την ώρα. */
    private function countOverdueTasks(int $userId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM %i
                 WHERE assigned_to = %d AND status = 'open' AND due_at IS NOT NULL AND due_at < %s",
                Tables::name(Tables::TASKS),
                $userId,
                current_time('mysql')
            )
        );
    }
}
