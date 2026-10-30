<?php

/**
 * (327) Αίτημα και ανάγνωση της υπηρεσίας μητρώου της ΑΑΔΕ (RgWsPublic2).
 *
 * Οι απαντήσεις εδώ είναι γραμμένες με τα ονόματα στοιχείων της ΑΑΔΕ
 * (έκδοση 4) και με ΔΙΑΦΟΡΕΤΙΚΑ προθέματα namespace από το αίτημα: η ανάγνωση
 * δεν πρέπει να εξαρτάται από αυτά.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Customer;

use EnergyCRM\Domain\Customer\AadeRegistry;
use PHPUnit\Framework\TestCase;

final class AadeRegistryTest extends TestCase
{
    public function testTheRequestCarriesTheSpecialCodesAndTheAfmEscaped(): void
    {
        $xml = AadeRegistry::envelope('user<1>', 'p&ss', '801234567', '', '2026-10-30');

        self::assertStringContainsString('<ns1:Username>user&lt;1&gt;</ns1:Username>', $xml);
        self::assertStringContainsString('<ns1:Password>p&amp;ss</ns1:Password>', $xml);
        self::assertStringContainsString('<ns3:afm_called_by/>', $xml);
        self::assertStringContainsString('<ns3:afm_called_for>801234567</ns3:afm_called_for>', $xml);
        self::assertStringContainsString('<ns3:as_on_date>2026-10-30</ns3:as_on_date>', $xml);
        self::assertStringContainsString('http://www.w3.org/2003/05/soap-envelope', $xml, 'SOAP 1.2');

        $by = AadeRegistry::envelope('u', 'p', '801234567', '094014201', '2026-10-30');
        self::assertStringContainsString('<ns3:afm_called_by>094014201</ns3:afm_called_by>', $by);

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml), 'έγκυρο XML');
    }

    public function testAnActiveCompanyFillsTheFormFields(): void
    {
        $r = AadeRegistry::parse($this->response([
            'deactivation_flag' => '1', 'deactivation_flag_descr' => 'ΕΝΕΡΓΟΣ ΑΦΜ',
            'firm_flag_descr' => 'ΕΠΙΤΗΔΕΥΜΑΤΙΑΣ', 'stop_date' => '',
        ]));

        self::assertTrue($r['ok']);
        self::assertSame('801234567', $r['afm']);
        self::assertSame('ΔΟΚΙΜΑΣΤΙΚΗ ΕΝΕΡΓΕΙΑΚΗ ΜΟΝΟΠΡΟΣΩΠΗ ΙΚΕ', $r['name']);
        self::assertSame('ΙΚΕ', $r['legal_form']);
        self::assertSame('Α΄ ΑΘΗΝΩΝ', $r['doy']);
        self::assertSame('ΛΕΩΦΟΡΟΣ ΚΗΦΙΣΙΑΣ', $r['street']);
        self::assertSame('124', $r['street_no']);
        self::assertSame('11526', $r['postal_code']);
        self::assertSame('ΑΘΗΝΑ', $r['city']);
        self::assertTrue($r['active']);
        self::assertTrue($r['business']);
        self::assertSame('', $r['stop_date']);
        // Η κύρια δραστηριότητα, όχι η πρώτη της λίστας (που είναι δευτερεύουσα).
        self::assertSame('47.11.10.00', $r['kad']);
        self::assertSame('ΛΙΑΝΙΚΟ ΕΜΠΟΡΙΟ ΣΕ ΜΗ ΕΞΕΙΔΙΚΕΥΜΕΝΑ ΚΑΤΑΣΤΗΜΑΤΑ', $r['activity']);
    }

    public function testAClosedBusinessIsMarkedSo(): void
    {
        $closed = AadeRegistry::parse($this->response([
            'deactivation_flag' => '2', 'deactivation_flag_descr' => 'ΑΠΕΝΕΡΓΟΠΟΙΗΜΕΝΟΣ ΑΦΜ',
            'firm_flag_descr' => 'ΠΡΩΗΝ ΕΠΙΤΗΔΕΥΜΑΤΙΑΣ', 'stop_date' => '2025-03-31',
        ]));
        self::assertFalse($closed['active']);
        self::assertFalse($closed['business']);
        self::assertSame('2025-03-31', $closed['stop_date']);

        $person = AadeRegistry::parse($this->response([
            'deactivation_flag' => '1', 'deactivation_flag_descr' => 'ΕΝΕΡΓΟΣ ΑΦΜ',
            'firm_flag_descr' => 'ΜΗ ΕΠΙΤΗΔΕΥΜΑΤΙΑΣ', 'stop_date' => '',
        ]));
        self::assertTrue($person['active']);
        self::assertFalse($person['business']);
    }

    public function testErrorsComeBackAsMessagesNeverAsExceptions(): void
    {
        $err = AadeRegistry::parse('<?xml version="1.0"?><S:Envelope xmlns:S="http://www.w3.org/2003/05/soap-envelope">'
            . '<S:Body><r:rgWsPublic2AfmMethodResponse xmlns:r="http://rgwspublic2/RgWsPublic2Service"><r:result>'
            . '<x:rg_ws_public2_result_rtType xmlns:x="http://rgwspublic2/RgWsPublic2"><x:error_rec>'
            . '<x:error_code>RG_WS_PUBLIC_TOKEN_USERNAME_NOT_AUTHENTICATED</x:error_code>'
            . '<x:error_descr>Ο χρήστης δεν έχει πιστοποιηθεί</x:error_descr></x:error_rec>'
            . '</x:rg_ws_public2_result_rtType></r:result></r:rgWsPublic2AfmMethodResponse></S:Body></S:Envelope>');
        self::assertFalse($err['ok']);
        self::assertSame('RG_WS_PUBLIC_TOKEN_USERNAME_NOT_AUTHENTICATED', $err['error_code']);
        self::assertSame('Ο χρήστης δεν έχει πιστοποιηθεί', $err['error']);

        $fault = AadeRegistry::parse('<S:Envelope xmlns:S="http://www.w3.org/2003/05/soap-envelope"><S:Body><S:Fault>'
            . '<S:Code><S:Value>S:Sender</S:Value></S:Code>'
            . '<S:Reason><S:Text xml:lang="en">Bad request</S:Text></S:Reason>'
            . '</S:Fault></S:Body></S:Envelope>');
        self::assertFalse($fault['ok']);
        self::assertStringContainsString('Bad request', $fault['error']);

        self::assertFalse(AadeRegistry::parse('')['ok']);
        self::assertFalse(AadeRegistry::parse('<html>Service Unavailable')['ok']);
    }

    public function testTheKadIsDottedInPairs(): void
    {
        self::assertSame('47.11.10.00', AadeRegistry::kad('47111000'));
        self::assertSame('35.14', AadeRegistry::kad('3514'));
        self::assertSame('', AadeRegistry::kad(''));
    }

    /** @param array<string, string> $flags */
    private function response(array $flags): string
    {
        $basic = [
            'afm' => '801234567', 'doy' => '1101', 'doy_descr' => 'Α΄ ΑΘΗΝΩΝ', 'i_ni_flag_descr' => 'ΜΗ ΦΠ',
            'onomasia' => 'ΔΟΚΙΜΑΣΤΙΚΗ ΕΝΕΡΓΕΙΑΚΗ ΜΟΝΟΠΡΟΣΩΠΗ ΙΚΕ', 'commer_title' => 'ΔΟΚΙΜΗ',
            'legal_status_descr' => 'ΙΚΕ', 'postal_address' => 'ΛΕΩΦΟΡΟΣ ΚΗΦΙΣΙΑΣ', 'postal_address_no' => '124',
            'postal_zip_code' => '11526', 'postal_area_description' => 'ΑΘΗΝΑ', 'regist_date' => '2015-01-02',
            'normal_vat_system_flag' => 'Y',
        ] + $flags;

        $b = '';
        foreach ($basic as $k => $v) {
            $b .= $v === ''
                ? '<x:' . $k . ' xsi:nil="true"/>'
                : '<x:' . $k . '>' . htmlspecialchars($v) . '</x:' . $k . '>';
        }

        $acts = '<x:item><x:firm_act_code>47191000</x:firm_act_code><x:firm_act_descr>ΑΛΛΟ</x:firm_act_descr>'
            . '<x:firm_act_kind>2</x:firm_act_kind><x:firm_act_kind_descr>ΔΕΥΤΕΡΕΥΟΥΣΑ</x:firm_act_kind_descr></x:item>'
            . '<x:item><x:firm_act_code>47111000</x:firm_act_code>'
            . '<x:firm_act_descr>ΛΙΑΝΙΚΟ ΕΜΠΟΡΙΟ ΣΕ ΜΗ ΕΞΕΙΔΙΚΕΥΜΕΝΑ ΚΑΤΑΣΤΗΜΑΤΑ</x:firm_act_descr>'
            . '<x:firm_act_kind>1</x:firm_act_kind><x:firm_act_kind_descr>ΚΥΡΙΑ</x:firm_act_kind_descr></x:item>';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<S:Envelope xmlns:S="http://www.w3.org/2003/05/soap-envelope"'
            . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><S:Body>'
            . '<r:rgWsPublic2AfmMethodResponse xmlns:r="http://rgwspublic2/RgWsPublic2Service"><r:result>'
            . '<x:rg_ws_public2_result_rtType xmlns:x="http://rgwspublic2/RgWsPublic2">'
            . '<x:call_seq_id>1</x:call_seq_id><x:error_rec><x:error_code xsi:nil="true"/>'
            . '<x:error_descr xsi:nil="true"/></x:error_rec>'
            . '<x:basic_rec>' . $b . '</x:basic_rec><x:firm_act_tab>' . $acts . '</x:firm_act_tab>'
            . '</x:rg_ws_public2_result_rtType></r:result></r:rgWsPublic2AfmMethodResponse></S:Body></S:Envelope>';
    }
}
