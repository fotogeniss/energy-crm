<?php

/**
 * Η ουρά «μη καλέσεις» προς το Gateway (449): δεν χάνει γεγονότα.
 *
 * Τα WordPress functions αντικαθίστανται μέσα στο namespace της ουράς
 * (`OutboxFakeWp.php`), με μνήμη αντί για βάση. Έτσι ελέγχεται η ίδια η κλάση,
 * όχι ένα αντίγραφό της.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Tests\Unit\GatewayIntake;

use EnergyCRM\GatewayIntake\Domain\GatewayEvent;
use EnergyCRM\GatewayIntake\Infrastructure\GatewayEventOutbox;
use EnergyCRM\GatewayIntake\Infrastructure\GatewayIntakeConfig;
use EnergyCRM\GatewayIntake\Infrastructure\OutboxFakeWp as Wp;
use EnergyCRM\Infrastructure\FieldCipher;
use EnergyCRM\Infrastructure\SecretStore;
use PHPUnit\Framework\TestCase;

final class GatewayEventOutboxTest extends TestCase
{
    private GatewayEventOutbox $outbox;

    protected function setUp(): void
    {
        // Οι ψεύτικες συναρτήσεις WordPress, στο namespace της ουράς (δες OutboxFakeWp.php).
        require_once __DIR__ . '/OutboxFakeWp.php';
        if (! defined('HOUR_IN_SECONDS')) {
            define('HOUR_IN_SECONDS', 3600);
        }

        if (! FieldCipher::isAvailable()) {
            self::markTestSkipped('Χωρίς sodium.');
        }
        Wp::reset();
        putenv('ECRM_GATEWAY_INTAKE_SECRET=' . str_repeat('s', 40));
        putenv('ECRM_GATEWAY_URL=https://gateway.example.gr');
        $this->outbox = new GatewayEventOutbox(
            new GatewayIntakeConfig(new SecretStore()),
            new FieldCipher(str_repeat('k', 32))
        );
    }

    protected function tearDown(): void
    {
        putenv('ECRM_GATEWAY_INTAKE_SECRET');
        putenv('ECRM_GATEWAY_URL');
    }

    public function testTheQueueIsStoredEncrypted(): void
    {
        self::assertTrue($this->outbox->enqueue(GatewayEvent::OPT_OUT, '6982774563'));
        self::assertStringNotContainsString('6982774563', Wp::$options[GatewayEventOutbox::OPTION]);
        self::assertSame(1, $this->outbox->pending());
    }

    public function testFailuresNeverDropOnly400And422Do(): void
    {
        $this->outbox->enqueue(GatewayEvent::OPT_OUT, '6982774563');
        $this->outbox->enqueue(GatewayEvent::ERASED, '6982774564');

        foreach ([0, 401, 503] as $code) {
            Wp::$responses = [$code, $code];
            Wp::$now      += 100000;
            $this->outbox->flush();
            self::assertSame(2, $this->outbox->pending(), (string) $code);
        }

        Wp::$responses = [422, 204];
        Wp::$now      += 100000;
        $this->outbox->flush();
        self::assertSame(0, $this->outbox->pending());
        self::assertCount(8, Wp::$posts);
    }

    public function testWhatArrivesWhileSendingIsKept(): void
    {
        $this->outbox->enqueue(GatewayEvent::OPT_OUT, '6982774563');

        // Όσο η πρώτη αποστολή είναι στον αέρα, άλλος πωλητής πατά «μη καλέσεις».
        Wp::$duringPost = fn () => $this->outbox->enqueue(GatewayEvent::OPT_OUT, '6900000001');
        Wp::$responses  = [204];
        $this->outbox->flush();

        self::assertSame(1, $this->outbox->pending());
        self::assertSame(1, count(Wp::$posts));
        self::assertGreaterThan(0, Wp::$locks, 'κάθε αλλαγή κάτω από GET_LOCK');
    }

    public function testAnUnreadableQueueIsKeptNotOverwritten(): void
    {
        // Κρυπτογραφημένο με άλλο κλειδί: το decrypt επιστρέφει ''.
        Wp::$options[GatewayEventOutbox::OPTION] = (new FieldCipher(str_repeat('x', 32)))->encrypt('{"a":1}');
        self::assertTrue(FieldCipher::isEncrypted(Wp::$options[GatewayEventOutbox::OPTION]));
        $bad = Wp::$options[GatewayEventOutbox::OPTION];

        $this->outbox->enqueue(GatewayEvent::OPT_OUT, '6982774563');

        self::assertSame([$bad], Wp::$options[GatewayEventOutbox::OPTION_UNREADABLE]);
        self::assertSame(1, $this->outbox->pending());
    }

    public function testWithoutSettingsItWaits(): void
    {
        putenv('ECRM_GATEWAY_URL');
        $this->outbox->enqueue(GatewayEvent::OPT_OUT, '6982774563');
        $this->outbox->flush();

        self::assertSame(1, $this->outbox->pending());
        self::assertSame([], Wp::$posts);
    }
}
