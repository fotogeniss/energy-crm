<?php

/**
 * Ένας υποψήφιος από το Call Gateway που δεν στέκει. Το μήνυμα γυρίζει στο
 * Gateway (422), που τον σημειώνει «απορρίφθηκε» και δεν τον ξαναστέλνει.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\GatewayIntake\Domain;

use InvalidArgumentException;

final class InvalidGatewayLead extends InvalidArgumentException
{
}
