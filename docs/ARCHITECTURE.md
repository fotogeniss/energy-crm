# Energy CRM — Αρχιτεκτονική

Ζωντανό έγγραφο. Περιγράφει πού πάμε και γιατί, όχι μόνο πού είμαστε.

## Αρχές

1. **Το authorization είναι δομικό, όχι κατά περίπτωση.** Δεδομένα συμβάσεων
   φορτώνονται *μόνο* μέσα από repositories που δέχονται υποχρεωτικά τον
   χρήστη. Δεν υπάρχει τρόπος να γράψεις query χωρίς scope, άρα δεν υπάρχει
   τρόπος να το ξεχάσεις.
2. **Ένα αρχείο, μία ευθύνη.** Καμία κλάση δεν ξεπερνά τις ~200 γραμμές. Αν
   μεγαλώνει, κάνει παραπάνω από ένα πράγμα.
3. **Ο domain κώδικας δεν ξέρει από WordPress.** Κανόνες (μεταβάσεις status,
   υπολογισμός προμηθειών, ιεραρχία δικτύου) ζουν σε καθαρές κλάσεις που
   τρέχουν σε unit test χωρίς βάση.
4. **Η βάση αλλάζει μόνο με migrations.** Ποτέ ξανά «κάνε SHOW COLUMNS και δες
   αν λείπει».
5. **Τίποτα δεν σπάει στην πορεία.** Strangler pattern: το νέο layer μπαίνει
   πίσω από τα υπάρχοντα entry points, οι callers μεταφέρονται ένας-ένας.

## Δομή

```
energy-crm.php          Μόνο bootstrap: constants, autoloader, Plugin::boot()
src/
  Autoloader.php        PSR-4, χωρίς εξάρτηση από Composer
  Plugin.php            Composition root — WordPress lifecycle wiring
  Installer.php         activate / deactivate / maybe_upgrade
  Legacy/Loader.php     Το strangler seam (λίστα ECRM_* που δεν μετανάστευσαν)
tests/
  Unit/                 Χωρίς WordPress, χωρίς βάση
docs/
includes|admin|public/  Legacy ECRM_* — αδειάζουν σταδιακά
```

### Target namespaces

| Namespace | Ευθύνη |
|---|---|
| `EnergyCRM\Domain` | Καθαροί κανόνες. Μηδέν WordPress, μηδέν SQL. |
| `EnergyCRM\Persistence` | Repositories. Το μόνο σημείο με `$wpdb`. |
| `EnergyCRM\Http` | REST controllers — λεπτοί, ένα resource ο καθένας. |
| `EnergyCRM\Access` | Capabilities, ρόλοι, scope του δικτύου. |
| `EnergyCRM\Infrastructure` | Claude API, PDF, SMS, files, cron. |
| `EnergyCRM\Admin` | wp-admin σελίδες. |

## Roadmap

| # | Βήμα | Κατάσταση |
|---|---|---|
| 0 | Git safety net | ✅ |
| 1 | Composer + PSR-4 + PHPUnit/PHPStan/PHPCS, λεπτό bootstrap | ✅ |
| 2 | `Access` + `ContractRepository` με υποχρεωτικό scope· κλείσιμο του IDOR στο `save_contract` | ⬜ |
| 3 | Ιεραρχία δικτύου σε materialized path — τέλος στο N+1 του `visible_user_ids()` | ⬜ |
| 4 | Migration runner + foreign keys· κατάργηση `ensure_columns()` | ⬜ |
| 5 | Ρητή μηχανή καταστάσεων συμβολαίου (επιτρεπόμενες μεταβάσεις) | ⬜ |
| 6 | Σπάσιμο του `class-ecrm-rest.php` σε controllers ανά resource, με `args` schema | ⬜ |
| 7 | Διαφοροποίηση ρόλων (Συνεργάτης / Πωλητής / Καταχωρητής) σε πραγματικά capabilities | ⬜ |
| 8 | Secrets εκτός `wp_options`· retention policy για `extracted_json` | ⬜ |
| 9 | Frontend: build step, σπάσιμο του `ecrm-app.js`, τέλος στο χειροκίνητο `innerHTML` | ⬜ |

## Development

```bash
composer install      # dev tooling μόνο — το plugin τρέχει και χωρίς vendor/
composer check        # phpcs + phpstan + phpunit
```

Τα unit tests δεν φορτώνουν WordPress. Αν μια κλάση δεν τεστάρεται χωρίς
WordPress, είναι σήμα ότι ανακατεύει domain λογική με infrastructure.
