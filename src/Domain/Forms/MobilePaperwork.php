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

    /**
     * The third box. Added 2026-08-24 — this used to be forced to '' by
     * connectionTicks() below on the theory that "nothing produces it", which
     * was wrong: ECRM_DB::activation_types() has had a real 'renewal' value
     * all along, visible as its own «Ανανέωση» chip for mobile (unguarded in
     * class-ecrm-shortcodes.php's $act_energy, same as 'new_connection') —
     * an actual Orizon renewal ticked the wrong box (ΝΕΑ ΣΥΝΔΕΣΗ, by falling
     * through the old two-value fallback) and never the right one.
     */
    public const REQUEST_RENEWAL = 'renewal';

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
     * The COMBO attachment sheet, for an application that is NOT itself the
     * Orizon mobile contract -- Στάδιο 4, 05/09/2026: the same combo
     * (Volton ρεύμα + Orizon κινητή) started from the Volton side instead of
     * the Orizon side.
     *
     * Mirrored, not duplicated: ONE application, printing its own template
     * (volton_he) plus this attachment -- never the actual orizon_mobile
     * contract, which is a deliberate, explicit decision (a real Orizon line
     * still needs its own Orizon-origin application; this sheet only records
     * the combo declaration on the Volton paperwork). forApplication() stays
     * untouched because it answers a different question -- "what does an
     * Orizon CONTRACT need" -- and always includes CONTRACT; this one never
     * does.
     *
     * @return list<string>
     */
    public static function comboAttachmentFor(string $offer): array
    {
        return $offer === self::OFFER_COMBO ? [self::COMBO] : [];
    }

    /**
     * The state of **all three** ΕΙΔΟΣ ΣΥΝΔΕΣΗΣ boxes on the contract.
     *
     * ΑΝΑΝΕΩΣΗ stays unticked here on purpose — pre-existing limitation,
     * left alone 2026-08-24 on the owner's explicit instruction rather than
     * extended today. It is a real, separate gap: ECRM_DB::activation_types()
     * has had a genuine 'renewal' value all along, visible as its own
     * unguarded «Ανανέωση» chip for mobile (class-ecrm-shortcodes.php's
     * $act_energy never scoped it away, same as 'new_connection') — but
     * nothing has ever ticked the box that corresponds to it.
     *
     * What DID need fixing today: REQUEST_RENEWAL exists as its own value
     * precisely so a renewal doesn't fall into the REQUEST_NEW_NUMBER bucket
     * by default and tick ΝΕΑ ΣΥΝΔΕΣΗ instead — signing a renewal as a new
     * connection is worse than ticking nothing. See ContractSaveMapping::
     * contractFrom(), which derives $requestType from activation_type.
     *
     * ## Why it answers for all three, not just the one it wants set
     *
     * This used to return only one key, and a docblock claimed the omitted
     * one was safe because "nothing in the CRM produces it" — wrong: the
     * electricity fill map in ECRM_FormFill::values() writes
     * `energopoiisi_ananeosi` from `activation_type` independently, and the
     * two maps merge with `+`, where the left side (this one) wins **only for
     * keys it contains**. A key omitted here is a key that other map still
     * owns, and it printed ΦΟΡΗΤΟΤΗΤΑ and ΑΝΑΝΕΩΣΗ ticked together — a signed
     * application telling the provider two contradictory things. So the
     * answer is always the whole group of three, one 'X' and two '' (or all
     * three '' for a renewal, today), never left for another map to fill.
     *
     * @param string $requestType MobilePaperwork::REQUEST_*
     *
     * @return array<string, string> fill key => 'X' or ''
     */
    public static function connectionTicks(string $requestType): array
    {
        return [
            'energopoiisi_foritotita'  => $requestType === self::REQUEST_PORTABILITY ? 'X' : '',
            'energopoiisi_nea_syndesi' => $requestType === self::REQUEST_NEW_NUMBER ? 'X' : '',
            'energopoiisi_ananeosi'    => '',
        ];
    }

    /**
     * Which ΚΥΡΙΟΣ/ΔΕΥΤΕΡΕΥΩΝ ΧΡΗΣΤΗΣ box to tick in the **mobile** block.
     *
     * Only orizon_combo.json has these fields, so this is a no-op for any
     * other template — the caller doesn't need to know that, it just merges
     * in whatever comes back.
     *
     * Επιστρέφει **και τα δύο** κλειδιά, όχι μόνο αυτό που θέλει τσεκαρισμένο,
     * για τον ίδιο λόγο που το κάνει η connectionTicks(): ένα κλειδί που
     * λείπει από εδώ είναι κλειδί που το κρατά άλλος χάρτης, και οι δύο χάρτες
     * ενώνονται με `+`, όπου η αριστερή πλευρά κερδίζει **μόνο για όσα κλειδιά
     * περιέχει**. Σήμερα κανείς άλλος δεν τα γράφει και η παράλειψη ήταν
     * ακίνδυνη· η ασυμμετρία όμως είναι ακριβώς το σχήμα που κόστισε εκεί.
     *
     * @return array<string, string> fill key => 'X' ή ''
     */
    public static function comboUserTicks(string $role): array
    {
        return [
            'xristis_kyrios'     => $role === self::COMBO_USER_MAIN ? 'X' : '',
            'xristis_defterevon' => $role === self::COMBO_USER_SECONDARY ? 'X' : '',
        ];
    }

    /**
     * Τα ίδια κουτιά στο μπλοκ **ενέργειας** — ανεστραμμένα.
     *
     * Το έντυπο έχει ΔΥΟ ζεύγη «ΚΥΡΙΟΣ / ΔΕΥΤΕΡΕΥΩΝ ΧΡΗΣΤΗΣ ΠΡΟΣΦΟΡΑΣ», ένα σε
     * κάθε μπλοκ πελάτη, και το άρθρο 4 τα ορίζει ως τους δύο ρόλους της ΙΔΙΑΣ
     * προσφοράς: ο ένας υπογράφων είναι ο κύριος χρήστης, ο άλλος δηλώνει ότι
     * συγκατατίθεται να οριστεί εκείνος. Δεν μπορούν να είναι και οι δύο το
     * ίδιο πράγμα.
     *
     * Γι' αυτό η φόρμα κρατά **ένα** πεδίο ρόλου (ρητή απόφαση του ιδιοκτήτη,
     * 04/09/2026) και το δεύτερο ζεύγος παράγεται αντίστροφα εδώ. Ώς τότε τα
     * ίδια δύο κλειδιά ήταν χαρτογραφημένα και στις δύο σελίδες του
     * orizon_combo.json, οπότε το έντυπο έβγαινε με τον ΙΔΙΟ ρόλο τσεκαρισμένο
     * και στα δύο μπλοκ — δηλαδή δύο κύριους χρήστες, που δεν υπάρχει.
     *
     * @return array<string, string> fill key => 'X' ή ''
     */
    public static function energyUserTicks(string $role): array
    {
        return [
            'xristis_kyrios_energeias'     => $role === self::COMBO_USER_SECONDARY ? 'X' : '',
            'xristis_defterevon_energeias' => $role === self::COMBO_USER_MAIN ? 'X' : '',
        ];
    }
}
