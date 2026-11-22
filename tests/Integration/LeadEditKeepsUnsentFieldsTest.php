<?php

/**
 * (445) Η επεξεργασία υποψηφίου γράφει μόνο ό,τι στάλθηκε.
 *
 * Η φόρμα επεξεργασίας δεν έχει «Λόγο απώλειας». Ως τώρα κάθε πεδίο που
 * έλειπε από το αίτημα γινόταν κενό, οπότε μια διόρθωση τηλεφώνου σε χαμένο
 * υποψήφιο έσβηνε τον λόγο.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Integration;

use EnergyCRM\Access\Roles;
use WP_REST_Request;
use WP_REST_Response;

final class LeadEditKeepsUnsentFieldsTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user($this->makeCrmUser(Roles::SELLER));
    }

    protected function tearDown(): void
    {
        wp_set_current_user(0);

        parent::tearDown();
    }

    public function testAnEditWithoutTheLostReasonKeepsIt(): void
    {
        $created = $this->post('/ecrm/v1/leads', [
            'name'        => 'Μαρία Δοκιμή',
            'phone'       => '6912345678',
            'interest'    => 'Ρεύμα',
            'stage'       => 'lost',
            'lost_reason' => 'Ακριβό',
        ]);
        $id = (int) $created->get_data()['id'];
        self::assertGreaterThan(0, $id);

        $response = $this->post('/ecrm/v1/leads/' . $id, ['name' => 'Μαρία Δοκιμή', 'phone' => '6987654321']);
        self::assertSame(200, $response->get_status());

        $row = $this->storedRow('leads', $id);
        self::assertSame('Ακριβό', (string) $row['lost_reason']);
        self::assertSame('lost', (string) $row['stage']);
        self::assertSame('Ρεύμα', (string) $row['interest']);
    }

    public function testAnEditCannotBlankTheName(): void
    {
        $id = (int) $this->post('/ecrm/v1/leads', ['name' => 'Νίκος'])->get_data()['id'];

        $response = $this->post('/ecrm/v1/leads/' . $id, ['name' => '']);

        self::assertSame(400, $response->get_status());
    }

    /** @param array<string, mixed> $params */
    private function post(string $path, array $params): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', $path);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body((string) wp_json_encode($params));

        return rest_do_request($request);
    }
}
