<?php

/**
 * (346) Ιστορικό πελάτη: πώς διαβάζεται κάθε γραμμή.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\Domain\Customer;

use EnergyCRM\Domain\Customer\CustomerHistory;
use PHPUnit\Framework\TestCase;

final class CustomerHistoryTest extends TestCase
{
    private const CONTRACTS = [
        ['id' => 7, 'code' => '1843', 'energy_type' => 'gas', 'provider_name' => 'Protergia'],
    ];

    public function testEverythingIsOneListNewestFirstWithTheOriginAtTheBottom(): void
    {
        $result = CustomerHistory::compose([
            'contracts' => self::CONTRACTS,
            'events'    => [
                ['contract_id' => 7, 'user_id' => 3, 'type' => 'created', 'created_at' => '2026-10-08 10:00:00'],
                [
                    'contract_id' => 7, 'user_id' => 0, 'type' => 'status_change', 'from_status' => 'registration',
                    'to_status' => 'active', 'message' => 'Εισαγωγή Excel', 'created_at' => '2026-10-12 09:00:00',
                ],
            ],
            'leads'     => [
                ['contract_id' => 7, 'partner_user_id' => 3, 'source' => 'qr', 'created_at' => '2026-10-08 10:00:00'],
            ],
            'files'     => [['contract_id' => 7, 'doc_kind' => 'provider_bill', 'created_at' => '2026-10-08 10:00:00']],
        ], ['provider_bill' => 'Λογαριασμός παρόχου'], ['qr' => 'Από το QR μου']);

        $titles = array_column($result['items'], 'title');

        self::assertSame([
            'Καταχώρηση → Ενεργός',
            'Ανέβηκε έγγραφο: Λογαριασμός παρόχου',
            'Νέα σύμβαση',
            'Ήρθε ως επαφή: Από το QR μου',
        ], $titles);
        self::assertSame('ok', $result['items'][0]['tone']);
        self::assertSame('#1843 · Αέριο Protergia', $result['items'][0]['contract_ref']);
        self::assertSame('#1843', $result['items'][0]['contract_code']);
        self::assertSame('Έγινε πελάτης με τη #1843', $result['items'][3]['detail']);
        self::assertSame(4, $result['counts']['all']);
        self::assertSame(2, $result['counts']['contract']);
        self::assertArrayNotHasKey('weight', $result['items'][0]);
    }

    public function testTechnicalEntriesAndUnknownTypesStayOut(): void
    {
        $result = CustomerHistory::compose([
            'contracts' => self::CONTRACTS,
            'events'    => [
                ['contract_id' => 7, 'type' => 'doc_kind_ai', 'created_at' => '2026-10-08 10:00:00'],
                ['contract_id' => 7, 'type' => 'sla_escalation', 'created_at' => '2026-10-08 10:00:00'],
                ['contract_id' => 7, 'type' => 'something_new', 'created_at' => '2026-10-08 10:00:00'],
            ],
            'tasks'     => [[
                'contract_id' => 7, 'assigned_to' => 9, 'title' => 'SLA', 'note' => 'sla_escalation',
                'status' => 'open', 'created_at' => '2026-10-08 10:00:00',
            ]],
        ]);

        self::assertSame([], $result['items']);
        self::assertSame(0, $result['counts']['all']);
    }

    public function testASignatureLinkNeverShowsThePhoneNumber(): void
    {
        $result = CustomerHistory::compose([
            'contracts' => self::CONTRACTS,
            'events'    => [
                [
                    'contract_id' => 7, 'user_id' => 3, 'type' => 'sign_sent_sms',
                    'created_at'  => '2026-10-08 10:01:00',
                    'message' => 'Σύνδεσμος υπογραφής στάλθηκε με viber προς 6912345678',
                ],
                [
                    'contract_id' => 7, 'user_id' => 3, 'type' => 'sign_failed_sms',
                    'created_at'  => '2026-10-08 10:00:00',
                    'message' => 'Ο σύνδεσμος υπογραφής ΔΕΝ στάλθηκε με SMS προς 6912345678 — Ο αριθμός δεν απάντησε',
                ],
            ],
        ]);

        self::assertSame('Στάλθηκε σύνδεσμος υπογραφής με Viber', $result['items'][0]['title']);
        self::assertSame('Ο σύνδεσμος υπογραφής δεν στάλθηκε με SMS', $result['items'][1]['title']);
        self::assertSame('Ο αριθμός δεν απάντησε', $result['items'][1]['detail']);
        self::assertSame('bad', $result['items'][1]['tone']);
        self::assertStringNotContainsString('6912345678', json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function testOneSaveIsOneLineAndCarriesFieldNamesNotValues(): void
    {
        $result = CustomerHistory::compose([
            'edits' => [
                [
                    'partner_user_id' => 4, 'field' => 'mobile', 'old_value' => '6900000000',
                    'new_value' => '6911111111', 'created_at' => '2026-09-22 12:00:00',
                ],
                [
                    'partner_user_id' => 4, 'field' => 'email', 'old_value' => 'a@b.gr',
                    'new_value' => 'c@d.gr', 'created_at' => '2026-09-22 12:00:00',
                ],
            ],
        ]);

        self::assertCount(1, $result['items']);
        self::assertSame('Άλλαξαν στοιχεία: Κινητό, Email', $result['items'][0]['title']);
        self::assertSame(4, $result['items'][0]['actor_id']);

        $json = json_encode($result, JSON_UNESCAPED_UNICODE) ?: '';
        self::assertStringNotContainsString('6911111111', $json);
        self::assertStringNotContainsString('c@d.gr', $json);
    }

    public function testADoneTaskIsTwoMomentsAndAnOpenOneSaysSo(): void
    {
        $result = CustomerHistory::compose([
            'tasks' => [
                [
                    'contract_id' => 0, 'assigned_to' => 5, 'created_by' => 5, 'title' => 'Επιβεβαίωση',
                    'status' => 'done', 'created_at' => '2026-10-01 09:00:00', 'done_at' => '2026-10-10 11:00:00',
                ],
                [
                    'contract_id' => 0, 'assigned_to' => 5, 'created_by' => 0, 'title' => 'Κλήση ανανέωσης',
                    'status' => 'open', 'due_at' => '2026-09-30 10:00:00', 'created_at' => '2026-09-01 09:00:00',
                ],
            ],
        ]);

        self::assertSame(
            ['Ολοκληρώθηκε: «Επιβεβαίωση»', 'Εργασία: «Επιβεβαίωση»', 'Εργασία: «Κλήση ανανέωσης»'],
            array_column($result['items'], 'title')
        );
        self::assertSame('προθεσμία 30/09/2026 · Ανοιχτή', $result['items'][2]['detail']);
        self::assertSame(5, $result['items'][2]['actor_id'], 'Χωρίς δημιουργό: αυτός στον οποίο ανατέθηκε.');
        self::assertSame(3, $result['counts']['task']);
    }

    public function testAContractTheUserCannotSeeIsNeverLinked(): void
    {
        $result = CustomerHistory::compose([
            'contracts' => self::CONTRACTS,
            'files'     => [['contract_id' => 99, 'doc_kind' => 'x', 'created_at' => '2026-10-08 10:00:00']],
        ]);

        self::assertSame(0, $result['items'][0]['contract_id']);
        self::assertSame('', $result['items'][0]['contract_ref']);
        self::assertSame('Ανέβηκε έγγραφο: Άλλο', $result['items'][0]['title']);
    }

    public function testTheListIsCappedAndSaysSo(): void
    {
        $notes = [];

        for ($i = 0; $i < CustomerHistory::MAX_ITEMS + 5; $i++) {
            $notes[] = [
                'partner_user_id' => 1,
                'body'            => 'σ' . $i,
                'created_at'      => sprintf('2026-01-01 00:%02d:%02d', intdiv($i, 60), $i % 60),
            ];
        }

        $result = CustomerHistory::compose(['notes' => $notes]);

        self::assertCount(CustomerHistory::MAX_ITEMS, $result['items']);
        self::assertTrue($result['truncated']);
        self::assertSame(CustomerHistory::MAX_ITEMS + 5, $result['counts']['all']);
    }
}
