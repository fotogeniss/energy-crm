<?php

/**
 * Which printed forms an Orizon mobile application actually needs.
 *
 * Every other provider in this CRM is one application, one template. Mobile is
 * not: the contract is always printed, and on top of it the customer's choices
 * add their own sheets. A combined offer has its own form, a portability
 * request has another, and getting the set wrong means an application arrives
 * at the provider incomplete and comes back rejected.
 *
 * That decision is a rule about the sale, not about rendering, so it lives
 * here — framework-free and testable — rather than inside the code that draws
 * PDFs.
 *
 * ## The rules, in the provider's terms
 *
 * - The **contract** is always produced.
 * - Its pages 9-10 are a portability section — the title says the document is
 *   a contract, a summary contract *and* a portability request — and we
 *   **never write on them**. The porting request the provider acts on is the
 *   standalone form, and filling both would submit the same request twice,
 *   from two sheets that could disagree.
 * - **Φορητότητα** therefore adds the standalone portability form, handed over
 *   together with the contract.
 * - **Συνδυαστική** and **COMBO** are two routes to the same discounted price
 *   (13/16/19/23 instead of 15/18/23/26), so exactly one of them can apply.
 *   Συνδυαστική combines a mobile line with another mobile line; COMBO
 *   combines mobile with electricity. Same money, different qualifying fact,
 *   different sheet.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Domain\Forms;

final class MobilePaperwork
{
    /** Always printed. Contains the portability section as pages 9-10. */
    public const CONTRACT = 'orizon_mobile';

    /** The porting desk's own copy, when the number is being carried over. */
    public const PORTABILITY = 'orizon_portability';

    /** Mobile combined with another mobile line. */
    public const FAMILY = 'orizon_family';

    /** Mobile combined with electricity. */
    public const COMBO = 'orizon_combo';

    /** ΤΥΠΟΣ ΑΙΤΗΣΗΣ, as the form's own tick boxes read. */
    public const REQUEST_PORTABILITY = 'portability';
    public const REQUEST_NEW_NUMBER  = 'new_number';

    /** The discount route, at most one of them. */
    public const OFFER_FAMILY = 'family';
    public const OFFER_COMBO  = 'combo';
    public const OFFER_NONE   = '';

    /** COMBO only: which of the two people on the line is being described. */
    public const COMBO_USER_MAIN      = 'main';
    public const COMBO_USER_SECONDARY = 'secondary';

    private function __construct()
    {
    }

    /**
     * The full set of templates to render, in the order they are handed over.
     *
     * @param string $requestType MobilePaperwork::REQUEST_*
     * @param string $offer       MobilePaperwork::OFFER_*
     *
     * @return list<string>
     */
    public static function forApplication(string $requestType, string $offer): array
    {
        $forms = [self::CONTRACT];

        if ($requestType === self::REQUEST_PORTABILITY) {
            $forms[] = self::PORTABILITY;
        }

        // Not a match on OFFER_FAMILY alone: an unrecognised value must not
        // silently print nothing, and must not print both.
        if ($offer === self::OFFER_FAMILY) {
            $forms[] = self::FAMILY;
        } elseif ($offer === self::OFFER_COMBO) {
            $forms[] = self::COMBO;
        }

        return $forms;
    }

    /**
     * Whether the two mutually exclusive discounts were both asked for.
     *
     * The form offers one or the other, so this is a guard against a payload
     * that says otherwise — not a state the screen can produce.
     */
    public static function isOfferValid(string $offer): bool
    {
        return in_array($offer, [self::OFFER_NONE, self::OFFER_FAMILY, self::OFFER_COMBO], true);
    }

    /**
     * Which box to tick under ΕΙΔΟΣ ΣΥΝΔΕΣΗΣ on the contract.
     *
     * The screen offers two choices where the paper has three: a new number is
     * a new connection. ΑΝΑΝΕΩΣΗ stays on the paper because the provider's form
     * has it, but nothing in the CRM produces it.
     *
     * @return array<string, string> fill key => 'X'
     */
    public static function connectionTicks(string $requestType): array
    {
        return match ($requestType) {
            self::REQUEST_PORTABILITY => ['energopoiisi_foritotita' => 'X'],
            self::REQUEST_NEW_NUMBER  => ['energopoiisi_nea_syndesi' => 'X'],
            default                   => [],
        };
    }

    /**
     * Which ΚΥΡΙΟΣ/ΔΕΥΤΕΡΕΥΩΝ ΧΡΗΣΤΗΣ box to tick on the COMBO form.
     *
     * Only orizon_combo.json has these two fields, so this is a no-op for any
     * other template — the caller doesn't need to know that, it just merges
     * in whatever comes back.
     *
     * @return array<string, string> fill key => 'X'
     */
    public static function comboUserTicks(string $role): array
    {
        return match ($role) {
            self::COMBO_USER_MAIN      => ['xristis_kyrios' => 'X'],
            self::COMBO_USER_SECONDARY => ['xristis_defterevon' => 'X'],
            default                    => [],
        };
    }
}
