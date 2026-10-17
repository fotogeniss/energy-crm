<?php

/**
 * Οι υποψήφιοι που έρχονται από το site σύγκρισης τιμών.
 *
 * Γράφει στον ΙΔΙΟ πίνακα `leads` με τον «σύνδεσμό μου»· ο πωλητής τους
 * βλέπει στην ίδια οθόνη, με τα ίδια κουμπιά. Χωριστή κλάση από τον
 * LeadRepository γιατί εδώ δεν υπάρχει συνδεδεμένος χρήστης άρα ούτε scope:
 * το αίτημα το εγγυάται η υπογραφή του site, και ο υποψήφιος ανήκει πάντα
 * στον χρήστη που όρισε ο admin (SiteIntakeConfig).
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\SiteIntake\Persistence;

use EnergyCRM\Infrastructure\FieldCipher;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\SiteIntake\Domain\SiteApplication;

final class SiteLeadRepository
{
    /** Όριο εγγράφων ανά υποψήφιο: 4 είδη του site, με περιθώριο για ξαναστάλσιμο. */
    public const MAX_FILES = 10;

    private string $leads;

    private string $files;

    public function __construct(private readonly FieldCipher $cipher)
    {
        $this->leads = Tables::name(Tables::LEADS);
        $this->files = Tables::name(Tables::FILES);
    }

    /** @return array{id: int, partner_user_id: int, contract_id: int}|null */
    public function findByRef(string $ref): ?array
    {
        global $wpdb;

        /** @var array<string, mixed>|null $row */
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT id, partner_user_id, contract_id FROM %i WHERE external_ref = %s',
                $this->leads,
                $ref
            ),
            ARRAY_A
        );

        return $row ? [
            'id'              => (int) $row['id'],
            'partner_user_id' => (int) $row['partner_user_id'],
            'contract_id'     => (int) ($row['contract_id'] ?? 0),
        ] : null;
    }

    /**
     * Νέος υποψήφιος, ή ο υπάρχων αν η ίδια αίτηση ήρθε ξανά.
     *
     * @return array{id: int, existing: bool}
     */
    public function createOnce(SiteApplication $app, string $phone, int $ownerId): array
    {
        global $wpdb;

        $existing = $this->findByRef($app->ref);
        if ($existing !== null) {
            return ['id' => $existing['id'], 'existing' => true];
        }

        $json = (string) json_encode($app->private, JSON_UNESCAPED_UNICODE);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $ok = $wpdb->insert($this->leads, [
            'partner_user_id' => $ownerId,
            'name'            => $app->fullName,
            'phone'           => $phone,
            'email'           => $app->email !== '' ? $app->email : null,
            'source'          => SiteApplication::SOURCE,
            'energy_type'     => 'power',
            'stage'           => 'new',
            'interest'        => $app->interest,
            'notes'           => $app->notes,
            'consent_at'      => $app->consentAt,
            'consent_ip'      => $app->consentIp !== '' ? $app->consentIp : null,
            'external_ref'    => $app->ref,
            'site_data'       => FieldCipher::isAvailable() ? $this->cipher->encrypt($json) : $json,
        ]);

        // Δύο ταυτόχρονα αιτήματα με το ίδιο ref: το UNIQUE κόβει το δεύτερο, που
        // βρίσκει τον υποψήφιο του πρώτου. Κανένας διπλός, κανένα σφάλμα προς το site.
        if ($ok === false) {
            $again = $this->findByRef($app->ref);

            return $again !== null ? ['id' => $again['id'], 'existing' => true] : ['id' => 0, 'existing' => false];
        }

        return ['id' => (int) $wpdb->insert_id, 'existing' => false];
    }

    /**
     * Τα στοιχεία του site για έναν υποψήφιο, ή null (δεν ήρθε από το site, ή
     * έχουν ήδη περάσει σε σύμβαση και σβηστεί).
     *
     * @param array<string, mixed> $lead Γραμμή του leads όπως τη φέρνει ο LeadRepository.
     *
     * @return array<string, mixed>|null
     */
    public function privateData(array $lead): ?array
    {
        $stored = (string) ($lead['site_data'] ?? '');
        if ($stored === '') {
            return null;
        }

        $data = json_decode($this->cipher->decrypt($stored), true);

        return is_array($data) ? $data : null;
    }

    /** Μετά τη Μετατροπή τα στοιχεία ζουν στον πελάτη/στη σύμβαση· δεύτερο αντίγραφο δεν κρατάμε. */
    public function forgetPrivateData(int $leadId): void
    {
        global $wpdb;

        $wpdb->update($this->leads, ['site_data' => null], ['id' => $leadId]);
    }

    public function fileCount(int $leadId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare('SELECT COUNT(*) FROM %i WHERE lead_id = %d', $this->files, $leadId)
        );
    }

    /** Το ίδιο έγγραφο του site (ίδιο όνομα) δεν μπαίνει δεύτερη φορά. */
    public function hasFile(int $leadId, string $filename): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT 1 FROM %i WHERE lead_id = %d AND filename = %s LIMIT 1',
                $this->files,
                $leadId,
                $filename
            )
        );
    }

    /**
     * @param array{path: string, filename: string, mime: string} $stored Από το ECRM_Files::put_bytes().
     */
    public function addFile(int $leadId, int $contractId, string $kind, array $stored): bool
    {
        global $wpdb;

        return $wpdb->insert($this->files, [
            // Αν ο υποψήφιος έχει ήδη γίνει σύμβαση, το έγγραφο πάει κατευθείαν
            // και εκεί — αλλιώς θα το έβλεπε μόνο όποιος άνοιγε τον υποψήφιο.
            'contract_id'   => $contractId > 0 ? $contractId : null,
            'lead_id'       => $leadId,
            'attachment_id' => null,
            'doc_kind'      => $kind,
            'filename'      => $stored['filename'],
            'mime'          => $stored['mime'],
            'path'          => $stored['path'],
            'protected'     => 1,
        ]) !== false;
    }
}
