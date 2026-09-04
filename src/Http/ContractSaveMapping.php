<?php

/**
 * The save request, translated into the columns of the two rows it writes.
 *
 * Lifted out of ContractSaveController unchanged (2026-08-16). It was 220 of
 * that controller's 454 lines, and it has a different reason to change from the
 * rest of them: save() orchestrates — resolve the target, refuse what the actor
 * may not touch, write, audit, enqueue — while the four members here answer one
 * narrower question, "which columns does this payload mean, and which does it
 * deliberately not mention". A new provider field changes this file. A new
 * ownership rule changes the controller. They were changing together only
 * because they shared an address.
 *
 * Static, stateless, no dependencies: an array in, an array out. Nothing here
 * reaches a repository, so nothing here can write without a scope — that
 * guarantee stays exactly where it was, in the controller and the repositories
 * it calls, and this class is deliberately unable to weaken it.
 *
 * ## The distinction that runs through every method below
 *
 * A key the request never sent is not a key it sent empty. On a create there is
 * no row yet, so every column needs a value and the defaults here are it. On an
 * update the column already holds something, and a field the agent's screen did
 * not resend must be left alone rather than blanked — omitting the key is what
 * makes it a no-op instead of an erasure, because ContractRepository::update()
 * writes exactly the keys it is given.
 *
 * That is the whole of CHANGELOG 2026-08-16 (5), and it is why nearly every
 * block below is guarded by `! $isUpdate || isset(...)`. The guard is verbose
 * on purpose: the alternative — building the full row and diffing it against
 * the existing one — puts the decision somewhere the reader has to reconstruct.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use EnergyCRM\Domain\Contract\ContractAddresses;
use EnergyCRM\Domain\Contract\ContractStatus;
use EnergyCRM\Domain\Contract\ContractTerm;
use EnergyCRM\Domain\Contract\ExtraFields;
use ECRM_Validate;
use EnergyCRM\Domain\Customer\PostalAddress;
use EnergyCRM\Infrastructure\RequestIp;

final class ContractSaveMapping
{
    /** Customer columns accepted from the request. */
    private const CUSTOMER_FIELDS = [
        'customer_type', 'afm', 'doy', 'first_name', 'last_name', 'father_name',
        'company_name', 'adt', 'birth_date', 'region', 'city', 'street',
        'street_no', 'postal_code', 'phone', 'mobile', 'email',
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, string>
     */
    public static function customerFrom(array $params): array
    {
        $customer = [];

        foreach (self::CUSTOMER_FIELDS as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $customer[$field] = sanitize_text_field((string) $params[$field]);
            }
        }

        // Το ΑΦΜ αποθηκεύεται σκέτα ψηφία. Η αναζήτηση και ο έλεγχος διπλοτύπων
        // κόβουν ό,τι δεν είναι ψηφίο πριν ψάξουν, οπότε ένα «123 456 789»
        // γραμμένο με κενά έπαιρνε afm_hash που δεν ταιριάζει ποτέ σε τίποτα.
        // Δεν χάνεται τιμή: αν μείνει κενό, ο έλεγχος ψηφίου παρακάτω το κόβει.
        if (isset($customer['afm'])) {
            $customer['afm'] = ECRM_Validate::digits($customer['afm']);
        }

        return $customer;
    }

    /**
     * The contract row to write: every column, defaulted, on a fresh save; on
     * an edit, only the columns the request actually sent.
     *
     * ContractRepository::update() already writes exactly the columns present
     * in the array it is given and leaves the rest of the row untouched — the
     * same mechanism customerFrom()/CustomerRepository already relied on. The
     * bug this method used to have was including every column regardless,
     * defaulted to null or a hardcoded value when the request omitted it, which
     * turned "the agent didn't resend price_type" into "price_type is now
     * NULL". Omitting the key on an edit is what makes an omitted field a
     * no-op instead of an erasure.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public static function contractFrom(array $params, int $customerId, bool $isUpdate): array
    {
        $contract = [];

        // customer_id is never conditionally included: resolveCustomer() has
        // already decided the right value for both create and edit, including
        // "keep what's there" when the request didn't resend it.
        $contract['customer_id'] = $customerId ?: null;

        if (! $isUpdate || isset($params['provider_id'])) {
            $contract['provider_id'] = isset($params['provider_id']) ? (int) $params['provider_id'] : null;
        }

        if (! $isUpdate || isset($params['program_id'])) {
            $contract['program_id'] = isset($params['program_id']) ? (int) $params['program_id'] : null;
        }

        if (! $isUpdate || isset($params['energy_type'])) {
            $contract['energy_type'] = sanitize_text_field((string) ($params['energy_type'] ?? 'power'));
        }

        if (! $isUpdate || isset($params['category'])) {
            $contract['category'] = sanitize_text_field((string) ($params['category'] ?? 'home'));
        }

        if (! $isUpdate || isset($params['price_type'])) {
            $contract['price_type'] = isset($params['price_type'])
                ? sanitize_text_field((string) $params['price_type']) : null;
        }

        if (! $isUpdate || isset($params['customer_type'])) {
            $contract['customer_type'] = sanitize_text_field((string) ($params['customer_type'] ?? 'individual'));
        }

        if (! $isUpdate || isset($params['activation_type'])) {
            $contract['activation_type'] = isset($params['activation_type'])
                ? sanitize_text_field((string) $params['activation_type']) : null;
        }

        if (! $isUpdate || isset($params['supply_number'])) {
            $contract['supply_number'] = isset($params['supply_number'])
                ? sanitize_text_field((string) $params['supply_number']) : null;
        }

        if (! $isUpdate || isset($params['meter_number'])) {
            $contract['meter_number'] = isset($params['meter_number'])
                ? sanitize_text_field((string) $params['meter_number']) : null;
        }

        // Το κινητό/email του πελάτη ενέργειας στο COMBO, όταν είναι άλλο
        // πρόσωπο -- στήλη, όχι extra_json, γιατί χτίζει τον δεύτερο σύνδεσμο
        // υπογραφής (§1.17, βλ. AddComboEnergyContactColumns). Ίδιο μοτίβο
        // partial-update με το supply_number/meter_number από πάνω.
        if (! $isUpdate || isset($params['combo_energy_mobile'])) {
            $contract['combo_energy_mobile'] = isset($params['combo_energy_mobile'])
                ? sanitize_text_field((string) $params['combo_energy_mobile']) : null;
        }

        if (! $isUpdate || isset($params['combo_energy_email'])) {
            $contract['combo_energy_email'] = isset($params['combo_energy_email'])
                ? sanitize_email((string) $params['combo_energy_email']) : null;
        }

        if (! $isUpdate || isset($params['invoice_code'])) {
            $contract['invoice_code'] = isset($params['invoice_code'])
                ? sanitize_text_field((string) $params['invoice_code']) : null;
        }

        if (! $isUpdate || isset($params['status'])) {
            $contract['status'] = self::statusFrom($params)->value;
        }

        if (! $isUpdate || isset($params['notes'])) {
            $contract['notes'] = isset($params['notes'])
                ? sanitize_textarea_field((string) $params['notes']) : null;
        }

        if (! $isUpdate || isset($params['extracted_json'])) {
            $contract['extracted_json'] = isset($params['extracted_json'])
                ? wp_kses_post((string) $params['extracted_json']) : null;
        }

        // Κινητή: το «Τύπος Αίτησης» (request_type) έφυγε ως ξεχωριστό πεδίο
        // στη φόρμα 2026-08-24 — παράγεται εδώ από το activation_type, που
        // είναι πλέον η μοναδική επιλογή στην οθόνη (Βήμα 1 «Ενεργοποίηση»).
        // ΤΡΕΙΣ πραγματικές τιμές, όχι δύο — λάθος πρώτης γραφής αυτού του
        // σημείου, εντόπισε το ο ιδιοκτήτης: το 'new_connection' και το
        // 'renewal' είναι ΚΑΙ ΤΑ ΔΥΟ αφύλακτα στο $act_energy του
        // class-ecrm-shortcodes.php (καμία σχέση με «μόνο 2 τιμές για
        // mobile» — αυτό δεν ίσχυε ποτέ), άρα φαίνονται και τα δύο μαζί με
        // το 'portability' όταν είναι επιλεγμένη η κινητή. Μία ανανέωση που
        // θα έπεφτε στο ίδιο fallback με μια νέα σύνδεση θα υπέγραφε λάθος
        // κουτί στο χαρτί — ρητά αντιστοιχίζονται και οι τρεις τιμές εδώ.
        //
        // Γράφεται μόνο όταν πραγματικά έρχεται activation_type σε αίτημα
        // κινητής, ώστε ένα partial update που δεν το στέλνει να μην
        // ξαναγράψει κάτι που δεν άλλαξε. Ο κώδικας που διαβάζει request_type
        // (MobilePaperwork, includes/class-ecrm-formfill.php) έμεινε
        // αναλλοίωτος — διαβάζει ακόμα από το extra_json, απλώς αυτό το
        // γράφει τώρα αντί για ένα δεύτερο dropdown.
        //
        // Δεν φτιάχνει `extra` από το τίποτα: ένα partial update που στέλνει
        // activation_type αλλά όχι extra (π.χ. αλλαγή μόνο ενός πεδίου έξω
        // από τα extra) δεν πρέπει να ενεργοποιήσει τη γραμμή extra_json από
        // κάτω και να σβήσει ό,τι άλλο έχει ήδη η σύμβαση εκεί.
        if (
            isset($params['activation_type'])
            && is_array($params['extra'] ?? null)
            && (string) ($params['energy_type'] ?? 'power') === 'mobile'
        ) {
            $params['extra']['request_type'] = match ((string) $params['activation_type']) {
                'portability' => 'portability',
                'renewal'     => 'renewal',
                default       => 'new_number',
            };
        }

        if (! $isUpdate || isset($params['extra'])) {
            $contract['extra_json'] = ExtraFields::toJson($params['extra'] ?? null);
        }

        // The three are computed together — end_date depends on start_date and
        // term_months — so they are treated as one unit: touch the trio if the
        // request sent any of the three, otherwise leave all three alone.
        if (
            ! $isUpdate
            || isset($params['start_date'])
            || isset($params['term_months'])
            || isset($params['end_date'])
        ) {
            $start  = trim((string) ($params['start_date'] ?? ''));
            $months = (int) ($params['term_months'] ?? 0);

            $contract['start_date']  = $start !== '' ? $start : null;
            $contract['term_months'] = $months > 0 ? $months : null;
            $contract['end_date']    = ContractTerm::endDate(
                $start,
                $months,
                (string) ($params['end_date'] ?? '')
            );
        }

        // Where the meter is, and where the bill goes. Each provider form asks
        // for both and says "εφόσον είναι διαφορετική"; until now the agent
        // typed the meter address into the extras bag and nothing printed it.
        $contract += self::addressFrom($params, ContractAddresses::SUPPLY_PREFIX, $isUpdate);
        $contract += self::addressFrom($params, ContractAddresses::BILLING_PREFIX, $isUpdate);

        // GDPR consent: recorded with when and from where, or not at all. Never
        // cleared by omission — consent already given is not un-given by a
        // later save that simply didn't touch the checkbox.
        if (! empty($params['consent'])) {
            $contract['consent_at'] = current_time('mysql');
            // Όχι REMOTE_ADDR: πίσω από Cloudflare αυτό είναι το Cloudflare, και
            // η απόδειξη συναίνεσης θα έδειχνε το ίδιο edge node για κάθε πελάτη.
            // Δες Infrastructure\RequestIp.
            $contract['consent_ip'] = RequestIp::current();
        }

        return $contract;
    }

    /**
     * The status a payload means, resolved exactly once.
     *
     * ContractSaveController has to know the target before it writes anything,
     * so that a refused transition leaves the customer row untouched too. It
     * must arrive at the same answer this class does — including the fallback,
     * where an unrecognised slug becomes Draft rather than an error. Two places
     * spelling out `tryFromSlug(...) ?? Draft` agree until one of them is
     * edited, which is the whole reason this method exists instead.
     *
     * @param array<string, mixed> $params
     */
    public static function statusFrom(array $params): ContractStatus
    {
        return ContractStatus::tryFromSlug((string) ($params['status'] ?? ''))
            ?? ContractStatus::Draft;
    }

    /**
     * One of the contract's two extra addresses, read off the request.
     *
     * The "same as home" flag is stored rather than inferred, so a blank
     * address the agent deliberately marked as identical stays distinguishable
     * from one they simply never filled in. When it is set, the parts are
     * cleared too — leaving stale values behind is how a corrected address
     * reappears on the next printed form.
     *
     * The six keys of one address block (the flag plus five parts) are treated
     * as a single unit, the same way the form renders them: on an edit, if the
     * request sent none of the six, the block is left untouched; if it sent
     * any one of them, the whole block is recomputed exactly as it always was.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private static function addressFrom(array $params, string $prefix, bool $isUpdate): array
    {
        if ($isUpdate && ! self::addressSent($params, $prefix)) {
            return [];
        }

        $same = ! empty($params[$prefix . 'addr_same']);

        if ($same) {
            return [$prefix . 'addr_same' => 1] + (new PostalAddress())->toColumns($prefix);
        }

        // Only the five address keys are read, and each is scalar by the time
        // it is cast — the request also carries the extras bag, which is an
        // array and must never reach sanitize_text_field().
        $clean = [];

        foreach (['street', 'street_no', 'city', 'postal_code', 'region'] as $part) {
            $value = $params[$prefix . $part] ?? '';

            $clean[$prefix . $part] = is_scalar($value)
                ? sanitize_text_field((string) $value)
                : '';
        }

        return [$prefix . 'addr_same' => 0]
            + PostalAddress::fromRow($clean, $prefix)->toColumns($prefix);
    }

    /**
     * Whether the request touched any of this address block's six keys.
     *
     * @param array<string, mixed> $params
     */
    private static function addressSent(array $params, string $prefix): bool
    {
        foreach (['addr_same', 'street', 'street_no', 'city', 'postal_code', 'region'] as $part) {
            if (array_key_exists($prefix . $part, $params)) {
                return true;
            }
        }

        return false;
    }
}
