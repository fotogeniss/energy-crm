<?php

/**
 * (428) Ποια πεδία τυπώνει το έντυπο του παρόχου μιας αίτησης.
 *
 * Απόφαση ιδιοκτήτη 19/11 (K3): μετά την υπογραφή του πελάτη κλειδώνουν όλα τα
 * τυπωμένα πεδία, εκτός διαχειριστή. Το έντυπο ξαναφτιάχνεται σε κάθε
 * αποθήκευση με την υπογραφή πάνω του (DocumentQueue, «PDF αίτησης»), οπότε
 * ό,τι άλλαζε μετά την υπογραφή εμφανιζόταν σαν να το υπέγραψε ο πελάτης.
 *
 * Η λίστα βγαίνει από τα ίδια template.json που χρησιμοποιεί η εκτύπωση
 * (`ECRM_FormFill::template_keys()`, `ProviderFormFields`), όχι από δεύτερη
 * λίστα που θα ξέφευγε.
 *
 * @package EnergyCRM
 */

declare(strict_types=1);

namespace EnergyCRM\Infrastructure;

use ECRM_FormFill;
use EnergyCRM\Domain\Forms\ProviderFormFields;
use EnergyCRM\Persistence\ContractDetails;
use EnergyCRM\Persistence\ContractQueries;

final class PrintedFields
{
    /**
     * Τα γράφει το γραφείο μετά την υπογραφή, από τον πάροχο: ο αριθμός
     * αίτησης του παρόχου και η λήξη. Δεν είναι κάτι που υπέγραψε ο πελάτης.
     */
    public const OFFICE_FIELDS = ['provider_ref', 'end_date'];

    public function __construct(
        private readonly ContractDetails $details,
        private readonly ContractQueries $queries,
        private readonly string $formsDir,
    ) {
    }

    /**
     * @param array<string, mixed> $row Η αίτηση όπως τη διαβάζει η εκτύπωση (`forDocument()`).
     *
     * @return array{columns: list<string>, extras: list<string>}
     */
    public function of(array $row): array
    {
        $columns = [];
        $extras  = [];

        foreach (ECRM_FormFill::template_keys($row) as $key) {
            foreach (ProviderFormFields::mainFormInputsForTemplate($key, $this->formsDir) as $input) {
                $columns[$input] = true;
            }

            foreach (array_keys(ProviderFormFields::forTemplate($key, $this->formsDir)) as $input) {
                $extras[(string) $input] = true;
            }
        }

        foreach (self::OFFICE_FIELDS as $office) {
            unset($columns[$office], $extras[$office]);
        }

        return ['columns' => array_keys($columns), 'extras' => array_keys($extras)];
    }

    /** @return array{columns: list<string>, extras: list<string>} */
    public function ofContract(int $contractId): array
    {
        $row = $this->details->forDocument($contractId);

        return $row === null ? ['columns' => [], 'extras' => []] : $this->of($row);
    }

    /**
     * Τα στοιχεία-στήλες που τυπώνονται σε ΟΠΟΙΑΔΗΠΟΤΕ υπογεγραμμένη αίτηση
     * του πελάτη: ο πελάτης είναι ένας, και η διεύθυνσή του που αλλάζει από
     * μια άλλη αίτηση αλλάζει και το υπογεγραμμένο χαρτί αυτής.
     *
     * @return list<string>
     */
    public function onSignedContractsOf(int $customerId): array
    {
        $out = [];

        foreach ($this->queries->signedContractIdsOf($customerId) as $id) {
            foreach ($this->ofContract($id)['columns'] as $column) {
                $out[$column] = true;
            }
        }

        return array_keys($out);
    }
}
