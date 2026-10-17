<?php

/**
 * Η αίτηση του site δεν στέκει. Το μήνυμα είναι για άνθρωπο (καταγράφεται στο
 * back-office του site), όχι για τον πελάτη.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\SiteIntake\Domain;

use InvalidArgumentException;

final class InvalidSiteApplication extends InvalidArgumentException
{
}
