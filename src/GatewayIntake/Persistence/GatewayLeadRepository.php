<?php

/**
 * Οι υποψήφιοι από το AI τηλεφωνικό κέντρο.
 *
 * Ίδιος πίνακας `leads` με όλους τους άλλους: ο πωλητής τους βλέπει στην ίδια
 * οθόνη, με πηγή «Κλήση AI». Ίδιο κλειδί επανάληψης με το site, το UNIQUE
 * `external_ref` (με πρόθεμα `gw-`, ώστε να μη συναντά ποτέ `ref` του site):
 * το Gateway ξαναστέλνει μέχρι να πάρει απάντηση, και κάθε επανάληψη βρίσκει
 * τον ίδιο υποψήφιο.
 *
 * Τίποτα στο `site_data`: εκεί η «Μετατροπή» περιμένει τα στοιχεία του site
 * (ΑΦΜ, παροχή), που το AI δεν συλλέγει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\GatewayIntake\Persistence;

use EnergyCRM\GatewayIntake\Domain\GatewayLead;
use EnergyCRM\Persistence\Tables;

final class GatewayLeadRepository
{
    private string $leads;

    public function __construct()
    {
        $this->leads = Tables::name(Tables::LEADS);
    }

    public function findIdByRef(string $ref): ?int
    {
        global $wpdb;

        $id = $wpdb->get_var(
            $wpdb->prepare('SELECT id FROM %i WHERE external_ref = %s', $this->leads, $ref)
        );

        return $id === null ? null : (int) $id;
    }

    /**
     * Νέος υποψήφιος, ή ο υπάρχων αν το Gateway τον έστειλε ξανά.
     *
     * @param string|null $consentAt Ώρα UTC (μετατρέπεται σε ώρα site εδώ, K12), ή null σε εισερχόμενη.
     *
     * @return array{id: int, existing: bool}
     */
    public function createOnce(GatewayLead $lead, string $phone, int $ownerId, ?string $consentAt): array
    {
        global $wpdb;

        $existing = $this->findIdByRef($lead->ref);
        if ($existing !== null) {
            return ['id' => $existing, 'existing' => true];
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $ok = $wpdb->insert($this->leads, [
            'partner_user_id' => $ownerId,
            'name'            => $lead->fullName,
            'phone'           => $phone,
            'source'          => GatewayLead::SOURCE,
            'energy_type'     => 'power',
            'stage'           => 'new',
            'interest'        => $lead->interest,
            'notes'           => $lead->notes,
            // K12: ήρθε σε UTC, η στήλη κρατά ώρα site (όπως το created_at).
            'consent_at'      => $consentAt === null ? null : get_date_from_gmt($consentAt),
            'external_ref'    => $lead->ref,
        ]);

        // Δύο ταυτόχρονες αποστολές του ίδιου ref: το UNIQUE κόβει τη δεύτερη.
        if ($ok === false) {
            $again = $this->findIdByRef($lead->ref);

            return $again !== null ? ['id' => $again, 'existing' => true] : ['id' => 0, 'existing' => false];
        }

        return ['id' => (int) $wpdb->insert_id, 'existing' => false];
    }
}
