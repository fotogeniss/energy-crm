<?php

/**
 * Οι ρυθμίσεις της σύνδεσης με το Call Gateway (AI τηλεφωνικό κέντρο).
 *
 * Όλα από το wp-config (ή μεταβλητή περιβάλλοντος), όχι από οθόνη: είναι
 * σύνδεση server-με-server που στήνεται μία φορά.
 *
 *   define('ECRM_GATEWAY_INTAKE_SECRET', '…');     // ίδιο με ECRM_CRM_SECRET του Gateway
 *   define('ECRM_GATEWAY_ALLOWED_IPS', '203.0.113.10');
 *   define('ECRM_GATEWAY_INTAKE_OWNER', 12);         // ποιος χρήστης παίρνει τους υποψηφίους
 *   define('ECRM_GATEWAY_URL', 'https://gateway.example.gr'); // πού στέλνουμε «μη καλέσεις» και διαγραφές
 *
 * Το μυστικό μπορεί να ζει και στο SecretStore (κρυπτογραφημένο στη βάση),
 * όπως όλα τα μυστικά του CRM. Αν λείπει οτιδήποτε, η πόρτα είναι κλειστή.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\GatewayIntake\Infrastructure;

use EnergyCRM\GatewayIntake\Domain\GatewayAccess;
use EnergyCRM\Infrastructure\IntakeOwner;
use EnergyCRM\Infrastructure\SecretStore;
use EnergyCRM\SiteIntake\Domain\SiteStatusUpdate;

final class GatewayIntakeConfig
{
    private const SECRET = 'gateway_intake_secret';

    private const SITE_SECRET = 'site_intake_secret';

    private const IPS = 'ECRM_GATEWAY_ALLOWED_IPS';

    private const OWNER = 'ECRM_GATEWAY_INTAKE_OWNER';

    private const URL = 'ECRM_GATEWAY_URL';

    public function __construct(private readonly SecretStore $secrets)
    {
    }

    public function secret(): string
    {
        return $this->secrets->get(self::SECRET);
    }

    /** @return list<string> */
    public function allowedIps(): array
    {
        return GatewayAccess::parseList(self::setting(self::IPS));
    }

    /**
     * Πού στέλνουμε τα γεγονότα του CRM (`/crm/events`), ή '' αν δεν είναι ρυθμισμένο ή δεν στέκει.
     * Ίδιος κανόνας με το site: https, ή http μόνο σε τοπικό περιβάλλον.
     */
    public function eventsUrl(): string
    {
        $base = rtrim(self::setting(self::URL), '/');

        return $base !== '' && SiteStatusUpdate::validUrl($base) ? $base . '/crm/events' : '';
    }

    public function ownerId(): int
    {
        return (int) self::setting(self::OWNER);
    }

    /** Γιατί η πόρτα είναι κλειστή, ή '' αν είναι ανοιχτή. */
    public function closedBecause(): string
    {
        return GatewayAccess::closedBecause(
            $this->secret(),
            $this->secrets->get(self::SITE_SECRET),
            $this->allowedIps()
        );
    }

    public function ownerProblem(): string
    {
        return IntakeOwner::problem($this->ownerId());
    }

    private static function setting(string $name): string
    {
        if (defined($name)) {
            return (string) constant($name);
        }

        $env = getenv($name);

        return is_string($env) ? $env : '';
    }
}
