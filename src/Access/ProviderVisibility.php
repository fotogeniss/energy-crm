<?php

/**
 * Ποιους παρόχους βλέπει κάποιος, και από ποιους μπορεί να δώσει σε άλλον.
 *
 * Το αντίστοιχο του `ScopeResolver` για τους παρόχους: εκείνο απαντά «ποιους
 * ανθρώπους βλέπεις», αυτό «ποιους παρόχους». Και τα δύο ρωτιούνται από τους
 * controllers, κανένα από την οθόνη -- ο browser απλώς ζωγραφίζει ό,τι του
 * δόθηκε.
 *
 * ## Πώς βγαίνει η απάντηση
 *
 * Η γραμμή του χρήστη στο δίκτυο υπάρχει ήδη έτοιμη (`ecrm_path`, "/1/7/23/").
 * Για κάθε άνθρωπο πάνω της που ΔΕΝ είναι admin διαβάζεται η γραμμένη του λίστα,
 * και η απάντηση είναι η τομή τους (`ProviderAccess::along()`). Οι admin
 * παραλείπονται: δεν έχουν λίστα, δεν περιορίζουν κανέναν. Ο ίδιος ο χρήστης,
 * αν είναι admin, βλέπει τα πάντα χωρίς να κοιταχτεί τίποτα άλλο.
 *
 * Μερικά `get_user_meta` ανά κλήση, όσο το βάθος του δικτύου -- το WordPress τα
 * έχει ήδη στη μνήμη του αιτήματος από το πρώτο διάβασμα κάθε χρήστη.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Access;

use EnergyCRM\Persistence\NetworkRepository;
use EnergyCRM\Persistence\ProviderRepository;
use EnergyCRM\Providers\Domain\ProviderAccess;
use EnergyCRM\Providers\Persistence\ProviderGrantRepository;

final class ProviderVisibility
{
    public function __construct(
        private readonly NetworkRepository $network,
        private readonly ProviderGrantRepository $grants,
        private readonly ProviderRepository $providers,
    ) {
    }

    /** Τι βλέπει ο δράστης ενός αιτήματος. */
    public function forScope(UserScope $scope): ProviderAccess
    {
        return $scope->isAdministrator()
            ? ProviderAccess::unrestricted()
            : $this->forUser($scope->actorId());
    }

    /** Τι βλέπει ένας οποιοσδήποτε χρήστης -- π.χ. ένα μέλος στην καρτέλα του. */
    public function forUser(int $userId): ProviderAccess
    {
        if ($userId <= 0) {
            return ProviderAccess::only([]);
        }

        if ($this->isAdministrator($userId)) {
            return ProviderAccess::unrestricted();
        }

        $lineage = NetworkPath::ids($this->network->pathFor($userId));

        // Χαλασμένο path (δεν θα έπρεπε να συμβεί -- το pathFor() το
        // ξαναχτίζει): τουλάχιστον η δική του λίστα, ποτέ «χωρίς περιορισμό».
        if (! in_array($userId, $lineage, true)) {
            $lineage[] = $userId;
        }

        $lists = [];

        foreach ($lineage as $id) {
            if ($id !== $userId && $this->isAdministrator($id)) {
                continue;
            }

            $lists[] = $this->grants->grantsOf($id);
        }

        return ProviderAccess::along($lists);
    }

    /**
     * Από ποιους παρόχους μπορεί ο δράστης να δώσει σε άλλον: όσους βλέπει ο
     * ίδιος, από τους ΕΝΕΡΓΟΥΣ. Για τον admin, όλοι οι ενεργοί -- εδώ η
     * «χωρίς περιορισμό» έννοια γίνεται λίστα, γιατί η οθόνη χρειάζεται κάτι να
     * ζωγραφίσει.
     *
     * @return list<int>
     */
    public function editableBy(UserScope $scope): array
    {
        return $this->forScope($scope)->filter($this->providers->activeIds());
    }

    private function isAdministrator(int $userId): bool
    {
        return user_can($userId, 'manage_options');
    }
}
