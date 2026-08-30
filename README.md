# Energy CRM

CRM για ενεργειακούς συνεργάτες: αιτήσεις και συμβάσεις παρόχων, με AI εξαγωγή στοιχείων από έγγραφα.

WordPress plugin. Ιδιόκτητο λογισμικό — βλ. [Άδεια](#άδεια) παρακάτω.

## Απαιτήσεις

- PHP 8.2+
- WordPress 6.5+
- MySQL (μέσω WordPress)

## Δομή

```
energy-crm.php     Bootstrap: constants, autoloader, Plugin::boot()
src/                Νέος κώδικας — PSR-4, namespace EnergyCRM\
  Access/           Capabilities, ρόλοι, UserScope/ScopeResolver (multi-tenancy)
  Admin/            Admin-side wiring
  Domain/           Καθαροί κανόνες, μηδέν WordPress (Contract, Customer, Partner, Commission, Quote, Forms, Analytics, Audit)
  Http/             REST controllers (namespace ecrm/v1)
  Infrastructure/   Claude API, PDF rendering, SMS, αρχεία, cron
  Persistence/       Repositories — το μόνο σημείο με άμεσο $wpdb
  Providers/        Νέα χαρακτηριστικά, οργανωμένα κάθετα (Domain/Http/Persistence μαζί ανά feature)
includes/, admin/, public/
                    Legacy ECRM_* κλάσεις (global namespace) — αδειάζουν σταδιακά προς src/
tests/
  Unit/             Χωρίς WordPress, χωρίς βάση
  Integration/      Πραγματικό WordPress + MySQL
docs/               Αρχιτεκτονική, changelog, οδηγίες δοκιμών
```

Πλήρης περιγραφή αρχών και οργάνωσης στο [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Εγκατάσταση για ανάπτυξη

```
composer install
```

## Δοκιμές

Δύο σουίτες:

| | `composer test` | `composer test:integration` |
|---|---|---|
| Τι φορτώνει | τίποτα | πραγματικό WordPress + MySQL |
| Τι ελέγχει | κανόνες domain | scope, SQL, κρυπτογράφηση, διαγραφή |

```
composer check      # lint + static analysis + unit tests (τρέχει στο pre-commit hook)
composer check:all   # και τα παραπάνω, και integration tests
```

Στήσιμο της βάσης δοκιμών (μιας χρήσης, διαγράφεται σε κάθε τρέξιμο): [`docs/TESTING.md`](docs/TESTING.md).

## Ασφάλεια & GDPR

Το σύστημα διαχειρίζεται προσωπικά δεδομένα πελατών (ΑΦΜ, ΑΔΤ, διευθύνσεις, σαρωμένα έγγραφα, υπογραφές). Το authorization είναι δομικό: δεδομένα συμβάσεων φορτώνονται μόνο μέσα από repositories που δέχονται υποχρεωτικά τον χρήστη (`UserScope`) — δεν υπάρχει query χωρίς scope.

## Άδεια

Proprietary. Ιδιοκτησία του συγγραφέα· καμία άδεια χρήσης, αντιγραφής ή διανομής δεν παρέχεται χωρίς ρητή έγγραφη συναίνεση.
