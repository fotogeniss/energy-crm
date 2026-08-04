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
| 2 | `Access` + `ContractRepository` με υποχρεωτικό scope· κλείσιμο του IDOR στο `save_contract` | ✅ |
| 3 | Ιεραρχία δικτύου σε materialized path — τέλος στο N+1 του `visible_user_ids()` | ✅ |
| 4α | Migration runner· κατάργηση `ensure_columns()` | ✅ |
| 4β | Διαγραφή φυσικών αρχείων όταν σβήνεται σύμβαση | ✅ |
| 4γ | Καθαρισμός ορφανών + foreign keys με `ON DELETE` | ✅ |
| 5 | Ρητή μηχανή καταστάσεων συμβολαίου (επιτρεπόμενες μεταβάσεις) | ✅ |
| 6 | Σπάσιμο του `class-ecrm-rest.php` σε controllers ανά resource, με `args` schema | ⬜ |
| 7 | Διαφοροποίηση ρόλων (Συνεργάτης / Πωλητής / Καταχωρητής) σε πραγματικά capabilities | ✅ |
| 8 | Secrets εκτός `wp_options`· retention policy για `extracted_json` | ✅ |
| 9 | Frontend: build step, σπάσιμο του `ecrm-app.js`, τέλος στο χειροκίνητο `innerHTML` | ⬜ |

## Ορατότητα και ιεραρχία

Τρία ερωτήματα που το CRM συχνά μπερδεύει σε ένα:

- **Ποιος πούλησε** — `contracts.partner_user_id`. Καθορίζει την προμήθεια.
- **Ποιος διαχειρίζεται** — το δέντρο `ecrm_parent`. Καθορίζει την ομάδα.
- **Ποιος βλέπει** — το `UserScope`. Παράγεται από τα δύο παραπάνω συν τα
  capabilities.

Η ιεραρχία αποθηκεύεται ως materialized path στο user meta `ecrm_path`:
`/1/7/23/` σημαίνει ότι ο 23 αναφέρεται στον 7, που αναφέρεται στον 1. Έτσι το
«όλοι κάτω από τον 7» γίνεται `WHERE ecrm_path LIKE '/1/7/%'` — ένα query αντί
για ένα ανά κόμβο. Οι κάθετοι στα άκρα δεν είναι διακοσμητικές: χωρίς αυτές, το
`/1/7` θα ταίριαζε και στο `/1/70/`.

Το `ecrm_parent` παραμένει η πηγή αλήθειας για κάθε ακμή· το `ecrm_path` είναι
παράγωγο και συντηρείται αυτόματα από το `NetworkSync`, που κρέμεται στα meta
hooks — όχι από τους call sites.

**Ο administrator βλέπει όλες τις συμβάσεις της εταιρείας.** Η ιεραρχία αφορά
προμήθειες, όχι δικαίωμα εποπτείας. Ο ιδιοκτήτης δεν χρειάζεται να είναι γονέας
όλων για να δει το σύνολο.

## Αλλαγές στη βάση

Δύο μηχανισμοί, με σαφή διαχωρισμό ευθύνης:

- **`dbDelta`** δημιουργεί τους πίνακες σε καθαρή εγκατάσταση. Το αφήνουμε να
  κάνει μόνο αυτό.
- **Migrations** (`src/Persistence/Schema/`) κάνουν ό,τι το dbDelta δεν μπορεί:
  foreign keys, indexes, μετατροπές δεδομένων, διαγραφές στηλών.

Ο λόγος του διαχωρισμού: το dbDelta είναι «κάνε το schema να μοιάζει έτσι»,
αναλύει CREATE TABLE με regex και **σιωπηλά αγνοεί** ό,τι δεν καταλαβαίνει —
γι' αυτό υπήρχε το `ensure_columns()` με τα χειροκίνητα `SHOW COLUMNS` σε κάθε
request. Ένα migration αντίθετα είναι «κάνε αυτή τη συγκεκριμένη αλλαγή, μία
φορά», και καταγράφεται.

Κανόνες:

- Το `id()` ενός migration **δεν αλλάζει ποτέ** μετά την κυκλοφορία. Είναι το
  μόνο πράγμα που λέει σε ένα live site ότι κάτι έχει ήδη τρέξει.
- Η λίστα στο `MigrationList` είναι **append-only**.
- Κάθε migration ρωτάει τον `SchemaInspector` πριν αλλάξει, ώστε να είναι
  σωστό και σε καθαρή εγκατάσταση και σε αναβάθμιση.
- Migration που αποτυγχάνει **δεν καταγράφεται**: ξαναδοκιμάζει στο επόμενο
  request αντί να αφήσει το schema σε κατάσταση που δεν περιέγραψε κανείς.

## Coding standard

`src/` και `tests/` ακολουθούν **PSR-12**, όχι WordPress-Core. Ο λόγος: οι
κανόνες τεκμηρίωσης του WPCS (`@var`, `@param` παντού) γράφτηκαν για κώδικα
χωρίς type declarations, όπου το docblock ήταν η μόνη πληροφορία τύπου. Με
native types γίνονται διπλοεγγραφή που σαπίζει. Ομοίως τα short arrays και οι
Yoda conditions είναι κατάλοιπα της PHP 5.x.

Κρατάμε από το WPCS ό,τι πιάνει πραγματικά bugs: `WordPress.Security`
(escaping, nonces, sanitization) και `WordPress.DB` (prepared statements).

Ο legacy κώδικας σε `includes/`, `admin/`, `public/` δεν ελέγχεται ακόμη — θα
μπει στο ruleset καθώς μεταναστεύει. Το `.editorconfig` κρατά tabs εκεί και
spaces στο `src/`, ώστε να μη γεμίσουν τα diffs με αλλαγές κενών.

## Development

```bash
composer install                      # dev tooling μόνο — το plugin τρέχει και χωρίς vendor/
git config core.hooksPath .githooks   # μία φορά ανά clone
composer check                        # phpcs + phpstan + phpunit
```

Ο pre-commit hook τρέχει το `composer check` και μπλοκάρει το commit αν
αποτύχει. Παράκαμψη μόνο συνειδητά, με `git commit --no-verify`.

Τα unit tests δεν φορτώνουν WordPress. Αν μια κλάση δεν τεστάρεται χωρίς
WordPress, είναι σήμα ότι ανακατεύει domain λογική με infrastructure.
