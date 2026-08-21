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

final class DashboardRepository
{
    /**
     * Οι καταστάσεις όπου η σύμβαση περιμένει ΤΟΝ ΣΥΝΕΡΓΑΤΗ, όχι τον πάροχο.
     *
     * `routed` και `processing` λείπουν επίτηδες: εκεί η μπάλα είναι στον
     * πάροχο και ο συνεργάτης δεν έχει τι να κάνει. Το dashboard δείχνει
     * δουλειά, όχι αναμονή.
     *
     * @var list<string>
     */
    private const NEEDS_ME = ['pending', 'awaiting_signature', 'draft'];

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
                 WHERE c.partner_user_id = %d AND c.status IN (%s, %s, %s)
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
}
