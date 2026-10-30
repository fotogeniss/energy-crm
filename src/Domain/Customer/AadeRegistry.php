<?php

/**
 * (327) Η υπηρεσία της ΑΑΔΕ «Αναζήτηση Βασικών Στοιχείων Μητρώου
 * Επιχειρήσεων» (RgWsPublic2, SOAP 1.2): το αίτημα και η ανάγνωση της
 * απάντησης. Καθαρή κλάση, χωρίς δίκτυο και χωρίς WordPress -- το δίκτυο
 * είναι του `Infrastructure\AadeClient`.
 *
 * Γιατί όχι μόνο το VIES (που ήδη ρωτάμε): το VIES ξέρει μόνο επιχειρήσεις
 * με ενδοκοινοτικό ΑΦΜ και δίνει επωνυμία και διεύθυνση σε ένα κομμάτι,
 * συχνά «---». Η ΑΑΔΕ δίνει Δ.Ο.Υ., νομική μορφή, Κ.Α.Δ., διεύθυνση σε
 * πεδία, και αν η επιχείρηση είναι ενεργή -- ό,τι γράφει ο πωλητής στα
 * επαγγελματικά έντυπα. Mockup: docs/UI-AFM-AADE.html.
 *
 * Τα ονόματα των στοιχείων (basic_rec, firm_act_tab …) είναι της ΑΑΔΕ,
 * έκδοση 4. Η ανάγνωση γίνεται με local-name(), ώστε να μη δένεται με τα
 * προθέματα των namespaces της απάντησης.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Customer;

use DOMDocument;
use DOMElement;
use DOMXPath;

final class AadeRegistry
{
    public const ENDPOINT = 'https://www1.gsis.gr/wsaade/RgWsPublic2/RgWsPublic2';

    private function __construct()
    {
    }

    /**
     * Το σώμα του αιτήματος.
     *
     * @param string $calledBy ΑΦΜ για λογαριασμό του οποίου γίνεται η
     *                         αναζήτηση (εξουσιοδότηση)· κενό = ο ίδιος
     */
    public static function envelope(
        string $username,
        string $password,
        string $afm,
        string $calledBy,
        string $date
    ): string {
        $x = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<env:Envelope xmlns:env="http://www.w3.org/2003/05/soap-envelope"'
            . ' xmlns:ns1="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd"'
            . ' xmlns:ns2="http://rgwspublic2/RgWsPublic2Service"'
            . ' xmlns:ns3="http://rgwspublic2/RgWsPublic2">'
            . '<env:Header><ns1:Security><ns1:UsernameToken>'
            . '<ns1:Username>' . $x($username) . '</ns1:Username>'
            . '<ns1:Password>' . $x($password) . '</ns1:Password>'
            . '</ns1:UsernameToken></ns1:Security></env:Header>'
            . '<env:Body><ns2:rgWsPublic2AfmMethod><ns2:INPUT_REC>'
            . ($calledBy === ''
                ? '<ns3:afm_called_by/>'
                : '<ns3:afm_called_by>' . $x($calledBy) . '</ns3:afm_called_by>')
            . '<ns3:afm_called_for>' . $x($afm) . '</ns3:afm_called_for>'
            . '<ns3:as_on_date>' . $x($date) . '</ns3:as_on_date>'
            . '</ns2:INPUT_REC></ns2:rgWsPublic2AfmMethod></env:Body></env:Envelope>';
    }

    /**
     * Η απάντηση, σε πεδία της φόρμας.
     *
     * @return array{
     *   ok: bool, error: string, error_code: string,
     *   afm: string, name: string, title: string, legal_form: string,
     *   doy: string, street: string, street_no: string, postal_code: string, city: string,
     *   kad: string, activity: string,
     *   active: bool, business: bool, stop_date: string
     * }
     */
    public static function parse(string $xml): array
    {
        $out = [
            'ok' => false, 'error' => '', 'error_code' => '',
            'afm' => '', 'name' => '', 'title' => '', 'legal_form' => '',
            'doy' => '', 'street' => '', 'street_no' => '', 'postal_code' => '', 'city' => '',
            'kad' => '', 'activity' => '',
            'active' => false, 'business' => false, 'stop_date' => '',
        ];

        $doc  = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok   = $xml !== '' && $doc->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $ok) {
            $out['error'] = 'Η ΑΑΔΕ δεν έστειλε έγκυρη απάντηση.';

            return $out;
        }

        $xp = new DOMXPath($doc);

        // SOAP Fault: συνήθως λάθος κωδικοί ή αίτημα που δεν διαβάστηκε.
        $fault = self::text($xp, '//*[local-name()="Fault"]//*[local-name()="Text"]');
        if ($fault !== '') {
            $out['error'] = 'Η ΑΑΔΕ απέρριψε το αίτημα: ' . $fault;

            return $out;
        }

        $code = self::text($xp, '//*[local-name()="error_rec"]/*[local-name()="error_code"]');
        if ($code !== '') {
            $out['error_code'] = $code;
            $out['error']      = self::text($xp, '//*[local-name()="error_rec"]/*[local-name()="error_descr"]')
                ?: 'Σφάλμα ΑΑΔΕ ' . $code . '.';

            return $out;
        }

        $b = static fn (string $tag): string
            => self::text($xp, '//*[local-name()="basic_rec"]/*[local-name()="' . $tag . '"]');

        $out['afm'] = $b('afm');
        if ($out['afm'] === '') {
            $out['error'] = 'Η ΑΑΔΕ δεν επέστρεψε στοιχεία για αυτό το ΑΦΜ.';

            return $out;
        }

        $out['ok']          = true;
        $out['name']        = $b('onomasia');
        $out['title']       = $b('commer_title');
        $out['legal_form']  = $b('legal_status_descr');
        $out['doy']         = $b('doy_descr');
        $out['street']      = $b('postal_address');
        $out['street_no']   = $b('postal_address_no');
        $out['postal_code'] = $b('postal_zip_code');
        $out['city']        = $b('postal_area_description');
        $out['stop_date']   = substr($b('stop_date'), 0, 10);

        // «ΕΝΕΡΓΟΣ ΑΦΜ» / «ΑΠΕΝΕΡΓΟΠΟΙΗΜΕΝΟΣ ΑΦΜ» (flag 1 / 2).
        $deact          = mb_strtoupper($b('deactivation_flag_descr'), 'UTF-8');
        $out['active']  = $deact !== '' ? ! str_contains($deact, 'ΑΠΕΝΕΡΓ') : $b('deactivation_flag') === '1';
        // «ΕΠΙΤΗΔΕΥΜΑΤΙΑΣ» / «ΜΗ ΕΠΙΤΗΔΕΥΜΑΤΙΑΣ» / «ΠΡΩΗΝ ΕΠΙΤΗΔΕΥΜΑΤΙΑΣ».
        $firm            = mb_strtoupper($b('firm_flag_descr'), 'UTF-8');
        $out['business'] = $firm !== '' && ! str_contains($firm, 'ΜΗ ') && ! str_contains($firm, 'ΠΡΩΗΝ');

        // Η κύρια δραστηριότητα (firm_act_kind 1), αλλιώς η πρώτη.
        $first = null;
        foreach ($xp->query('//*[local-name()="firm_act_tab"]/*[local-name()="item"]') ?: [] as $item) {
            if (! $item instanceof DOMElement) {
                continue;
            }
            $kind = self::childText($item, 'firm_act_kind');
            $act  = [self::childText($item, 'firm_act_code'), self::childText($item, 'firm_act_descr')];
            $first ??= $act;
            if ($kind === '1') {
                $first = $act;
                break;
            }
        }
        if ($first !== null) {
            $out['kad']      = self::kad($first[0]);
            $out['activity'] = $first[1];
        }

        return $out;
    }

    /** Κ.Α.Δ. με τελείες ανά δύο ψηφία (47111000 → 47.11.10.00), όπως στα έντυπα. */
    public static function kad(string $code): string
    {
        $digits = (string) preg_replace('/\D/', '', $code);

        return $digits === '' ? '' : implode('.', str_split($digits, 2));
    }

    private static function text(DOMXPath $xp, string $path): string
    {
        $n = $xp->query($path);

        return $n !== false && $n->length > 0 ? trim((string) $n->item(0)?->textContent) : '';
    }

    private static function childText(DOMElement $el, string $name): string
    {
        foreach ($el->childNodes as $c) {
            if ($c instanceof DOMElement && $c->localName === $name) {
                return trim($c->textContent);
            }
        }

        return '';
    }
}
