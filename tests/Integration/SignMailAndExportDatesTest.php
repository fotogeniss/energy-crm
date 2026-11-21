<?php

/**
 * (441) Έλεγχος λογικής 21/11: το email «Υπογράφηκε» και η ημερομηνία
 * τερματισμού στην εξαγωγή Excel.
 *
 * - Το email «Υπογράφηκε» πήγαινε και σε απενεργοποιημένο πωλητή (ίδιο κενό
 *   με το 438).
 * - Η «ΗΜΕΡΟΜΗΝΙΑ ΤΕΡΜΑΤΙΣΜΟΥ» ήταν η τελευταία αλλαγή της αίτησης, όχι η
 *   ακύρωση: μια διόρθωση μετά την ακύρωση την άλλαζε (ίδιο με το 416).
 *
 * @kanonas K6
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use ECRM_Export;
use ECRM_Tracking;
use EnergyCRM\Access\Roles;
use EnergyCRM\Persistence\Tables;
use EnergyCRM\Persistence\TeamRepository;

final class SignMailAndExportDatesTest extends IntegrationTestCase
{
    /** Θέση της στήλης «ΗΜΕΡΟΜΗΝΙΑ ΤΕΡΜΑΤΙΣΜΟΥ» στο φύλλο. */
    private const TERMINATION = 16;

    /** @var list<string> */
    private array $recipients = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->recipients = [];
        add_filter('pre_wp_mail', [$this, 'capture'], 10, 2);
    }

    protected function tearDown(): void
    {
        remove_filter('pre_wp_mail', [$this, 'capture'], 10);
        wp_set_current_user(0);

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $atts
     */
    public function capture(mixed $short, array $atts): bool
    {
        foreach ((array) ($atts['to'] ?? []) as $address) {
            $this->recipients[] = (string) $address;
        }

        return true;
    }

    public function testTheSignedEmailSkipsADisabledSeller(): void
    {
        $seller = $this->makeCrmUser(Roles::SELLER);
        $row    = ['partner_user_id' => $seller, 'code' => 'ECRM-SGN-1'];

        ECRM_Tracking::notify_seller_signed($row, true, 'Οριστικοποίηση');
        self::assertCount(1, $this->recipients, 'Χωρίς αυτό το test δεν λέει τίποτα.');

        $this->recipients = [];
        (new TeamRepository())->setDisabled($seller, true);

        ECRM_Tracking::notify_seller_signed($row, true, 'Οριστικοποίηση');
        self::assertSame([], $this->recipients);
    }

    public function testTheTerminationDateIsTheDayOfTheCancellation(): void
    {
        global $wpdb;

        $seller = $this->makeCrmUser(Roles::SELLER);
        wp_set_current_user($seller);

        $wpdb->insert(Tables::name(Tables::CONTRACTS), [
            'partner_user_id' => $seller,
            'status'          => 'cancelled',
            'supply_number'   => '12345678901',
            'energy_type'     => 'power',
            'code'            => 'ECRM-EXP-' . wp_generate_password(6, false),
        ]);
        $id = (int) $wpdb->insert_id;

        $wpdb->insert(Tables::name(Tables::EVENTS), [
            'contract_id' => $id,
            'user_id'     => 0,
            'type'        => 'status_change',
            'from_status' => 'active',
            'to_status'   => 'cancelled',
            'created_at'  => '2026-10-05 12:00:00',
        ]);

        // Μια διόρθωση σήμερα: το updated_at γίνεται σημερινό.
        $wpdb->query($wpdb->prepare(
            'UPDATE %i SET updated_at = NOW() WHERE id = %d',
            Tables::name(Tables::CONTRACTS),
            $id
        ));

        $data = ECRM_Export::contracts_dataset('', '', [$id], [$seller]);

        self::assertCount(1, $data['rows']);
        self::assertSame('ΗΜΕΡΟΜΗΝΙΑ ΤΕΡΜΑΤΙΣΜΟΥ', $data['headers'][self::TERMINATION]);
        self::assertSame('05/10/2026', $data['rows'][0][self::TERMINATION]);
    }
}
