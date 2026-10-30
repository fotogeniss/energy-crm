<?php

/**
 * GET /lookup/afm — company name and address from the EU VAT register.
 *
 * (327) Πρώτα η ΑΑΔΕ, όταν υπάρχουν οι ειδικοί κωδικοί της (Δ.Ο.Υ., νομική
 * μορφή, Κ.Α.Δ., δραστηριότητα, διεύθυνση σε πεδία, αν είναι ενεργή)· αν
 * λείπουν, αν η ΑΑΔΕ δεν απαντά ή αρνείται τους κωδικούς, το VIES όπως πριν.
 * Τα παλιά κλειδιά της απάντησης (valid, name, address, parsed) μένουν ίδια·
 * νέα: `source`, `fields`, `warnings`, `note`.
 *
 * Calls out to VIES, which is public, free, occasionally slow and frequently
 * down. Every failure mode returns a plain message rather than an exception:
 * the agent can always type the details by hand, so a register outage must not
 * block the application.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Http;

use ECRM_RateLimit;
use ECRM_Validate;
use EnergyCRM\Domain\Customer\GreekAddress;
use EnergyCRM\Infrastructure\AadeClient;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class VatLookupController implements Controller
{
    private const ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/rest-api/ms/EL/vat/';

    /** VIES answers "---" when it has the registration but not the detail. */
    private const NO_VALUE = '---';

    public function __construct(private readonly ?AadeClient $aade = null)
    {
    }

    public function routes(): void
    {
        register_rest_route(Router::NAMESPACE, '/lookup/afm', [
            'methods'             => 'GET',
            'callback'            => [$this, 'lookup'],
            'permission_callback' => Guards::crmUser(),
            'args'                => [
                'afm' => ['type' => 'string', 'required' => true],
            ],
        ]);
    }

    public function lookup(WP_REST_Request $request): WP_REST_Response
    {
        // The register is someone else's service; we do not hammer it.
        if (! ECRM_RateLimit::allow('afm', 30, 300)) {
            return ECRM_RateLimit::too_many();
        }

        $afm = ECRM_Validate::digits((string) $request['afm']);

        // Check digit first: a typo costs nothing to catch here and a round
        // trip to Brussels to catch there.
        if (! ECRM_Validate::afm($afm)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'Μη έγκυρο ΑΦΜ.'], 422);
        }

        $note = '';

        if ($this->aade !== null && $this->aade->configured()) {
            $r = $this->aade->lookup($afm);

            if (! empty($r['ok'])) {
                return new WP_REST_Response(self::fromAade($afm, $r), 200);
            }

            // Το ΑΦΜ δεν υπάρχει στο μητρώο: αυτή είναι η απάντηση, όχι βλάβη.
            if (self::notFound((string) ($r['error_code'] ?? ''))) {
                return new WP_REST_Response(['ok' => true, 'valid' => false, 'afm' => $afm, 'source' => 'aade'], 200);
            }

            // Κωδικοί, όριο μήνα, υπηρεσία κάτω: συνεχίζουμε με το VIES και το λέμε.
            $why  = empty($r['transport']) && ! empty($r['error']) ? ' (' . $r['error'] . ')' : '';
            $note = 'Η ΑΑΔΕ δεν απάντησε' . $why
                . ' — από VIES. Δ.Ο.Υ. και Κ.Α.Δ. γράψ\' τα με το χέρι.';
        }

        $response = wp_remote_get(self::ENDPOINT . rawurlencode($afm), [
            'timeout' => 12,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if ($response instanceof WP_Error) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Αποτυχία σύνδεσης στο μητρώο (VIES).'],
                502
            );
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $data   = json_decode((string) wp_remote_retrieve_body($response), true);

        if ($status !== 200 || ! is_array($data)) {
            return new WP_REST_Response(
                ['ok' => false, 'error' => 'Το μητρώο VIES δεν απάντησε (HTTP ' . $status . ').'],
                502
            );
        }

        if (empty($data['isValid'])) {
            return new WP_REST_Response(['ok' => true, 'valid' => false, 'afm' => $afm], 200);
        }

        $name    = self::value((string) ($data['name'] ?? ''));
        $address = self::value((string) ($data['address'] ?? ''));

        $parsed = GreekAddress::parse($address);

        return new WP_REST_Response([
            'ok'       => true,
            'valid'    => true,
            'afm'      => $afm,
            'name'     => $name,
            'address'  => $address,
            'parsed'   => $parsed,
            'source'   => 'vies',
            'fields'   => array_filter(['company_name' => $name] + $parsed, static fn (string $v): bool => $v !== ''),
            'warnings' => [],
            'note'     => $note,
        ], 200);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private static function fromAade(string $afm, array $r): array
    {
        $s = static fn (string $k): string => trim((string) ($r[$k] ?? ''));

        $fields = array_filter([
            'company_name' => $s('name'),
            'company_type' => $s('legal_form'),
            'activity'     => $s('activity'),
            'kad'          => $s('kad'),
            'doy'          => $s('doy'),
            'street'       => $s('street'),
            'street_no'    => $s('street_no'),
            'postal_code'  => $s('postal_code'),
            'city'         => $s('city'),
        ], static fn (string $v): bool => $v !== '');

        $warnings = [];
        if (empty($r['active'])) {
            $warnings[] = 'Απενεργοποιημένο ΑΦΜ στην ΑΑΔΕ. Έλεγξε με τον πελάτη πριν προχωρήσεις.';
        }
        if ($s('stop_date') !== '') {
            $d          = explode('-', $s('stop_date'));
            $warnings[] = 'Διακοπή εργασιών ' . (count($d) === 3 ? $d[2] . '/' . $d[1] . '/' . $d[0] : $s('stop_date'))
                . '. Ο πάροχος θα απορρίψει αίτηση επαγγελματικού τιμολογίου.';
        } elseif (empty($r['business'])) {
            $warnings[] = 'Δεν είναι επιτηδευματίας. Για επαγγελματικό πρόγραμμα χρειάζεται ΑΦΜ επιχείρησης.';
        }

        $street = trim($s('street') . ' ' . $s('street_no'));

        return [
            'ok'       => true,
            'valid'    => true,
            'afm'      => $afm,
            'name'     => $s('name'),
            'address'  => trim($street . ', ' . $s('postal_code') . ' ' . $s('city'), ' ,'),
            'parsed'   => [
                'street'      => $s('street'),
                'street_no'   => $s('street_no'),
                'postal_code' => $s('postal_code'),
                'city'        => $s('city'),
            ],
            'source'   => 'aade',
            'fields'   => $fields,
            'warnings' => $warnings,
            'note'     => '',
        ];
    }

    /** Σφάλμα της ΑΑΔΕ που σημαίνει «δεν υπάρχει τέτοιο ΑΦΜ», όχι «δεν μπόρεσα». */
    private static function notFound(string $code): bool
    {
        $c = strtoupper($code);

        return str_contains($c, 'AFM')
            && preg_match('/NOT|INVALID|WRONG/', $c) === 1
            && preg_match('/TOKEN|USER|PASS|AUTH|LIMIT|MAX|CALLED_BY|DATE/', $c) !== 1;
    }

    private static function value(string $field): string
    {
        $field = trim($field);

        return $field === self::NO_VALUE ? '' : $field;
    }
}
