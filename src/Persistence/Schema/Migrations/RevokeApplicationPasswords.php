<?php

/**
 * (409) Σβήνει κάθε κωδικό εφαρμογής του WordPress, σε όλους τους χρήστες.
 *
 * Στο live (17/11) ο «test» είχε 16 κωδικούς εφαρμογής που δεν έφτιαξε ο
 * ιδιοκτήτης, και μ' αυτούς γράφτηκαν 4.768 άρθρα spam. Εκεί σβήστηκαν με
 * το χέρι· η 0064 το κάνει σε κάθε site που τρέχει το CRM (και στο νέο host
 * μετά τη μεταφορά), γιατί από το 409 κανείς δεν τους χρησιμοποιεί
 * (`Hardening::appPasswordsAvailable()`). Ό,τι έμενε, ήταν μόνο κίνδυνος.
 *
 * Τρέχει μία φορά. Τοπικά σβήνει και όσους έφτιαξε το
 * tools/load-test-appwd.php· ξαναφτιάχνονται όταν χρειαστούν.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Persistence\Schema\Migrations;

use EnergyCRM\Persistence\Schema\Migration;
use EnergyCRM\Persistence\Schema\SchemaInspector;

final class RevokeApplicationPasswords implements Migration
{
    /** Το user meta του WordPress (WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS). */
    public const META_KEY = '_application_passwords';

    public function id(): string
    {
        return '0064_revoke_application_passwords';
    }

    public function description(): string
    {
        return 'Σβήνει όλους τους κωδικούς εφαρμογής του WordPress (409)';
    }

    public function apply(SchemaInspector $schema): void
    {
        delete_metadata('user', 0, self::META_KEY, '', true);
    }
}
