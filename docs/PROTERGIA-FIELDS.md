# Protergia — μεταβλητές εντύπων και αντιστοιχία

Τα κλειδιά που χρησιμοποιούνται στα `assets/forms/protergia_*.json`, με το πού
βρίσκει το CRM την τιμή τους.

Η αντιστοιχία ορίζεται σε ένα μόνο σημείο: `ECRM_FormFill::values()`
(`includes/class-ecrm-formfill.php`). Κλειδί που δεν υπάρχει εκεί τυπώνεται
πάντα κενό, χωρίς σφάλμα.

**Πηγή** σημαίνει:

- `customers.<στήλη>` — στήλη του πελάτη
- `contracts.<στήλη>` — στήλη της σύμβασης
- `extra_json.<κλειδί>` — πεδίο της φόρμας που μπαίνει στον «σάκο» της σύμβασης
- *υπολογισμένο* — παράγεται από άλλα, δεν αποθηκεύεται

---

## Στοιχεία πελάτη

| Κλειδί | Τι τυπώνει | Πηγή |
|---|---|---|
| `onomateponymo` | Ονοματεπώνυμο· για εταιρεία η επωνυμία | `first_name` + `last_name`, ή `company_name` |
| `afm` | ΑΦΜ | `customers.afm` |
| `doy` | ΔΟΥ | `customers.doy` |
| `adt` | ΑΔΤ ή αρ. διαβατηρίου | `customers.adt` |
| `tilefono` | Τηλέφωνο οικίας | `customers.phone` |
| `kinito` | Κινητό | `customers.mobile` |
| `email` | E-mail | `customers.email` |
| `epaggelma` | Επάγγελμα / αντικείμενο | `extra_json.activity` |

## Διεύθυνση κατοικίας / έδρας

| Κλειδί | Τι τυπώνει | Πηγή |
|---|---|---|
| `odos` | Οδός **και** αριθμός | `street` + `street_no` |
| `poli` | Πόλη | `customers.city` |
| `tk` | Τ.Κ. | `customers.postal_code` |

## Διεύθυνση παροχής — εκεί που είναι ο μετρητής

Πέφτει πίσω στη διεύθυνση κατοικίας όταν είναι τσεκαρισμένο το «ίδια με τη
διεύθυνση του πελάτη».

| Κλειδί | Τι τυπώνει | Πηγή |
|---|---|---|
| `odos_arithmos_paroxis` | Οδός και αριθμός, **χωρίς** ΤΚ/πόλη | `contracts.supply_street` + `supply_street_no` |
| `poli_paroxis` | Πόλη | `contracts.supply_city` |
| `tk_paroxis` | Τ.Κ. | `contracts.supply_postal_code` |

> Η Protergia έχει τρία χωριστά κουτιά (ΔΙΕΥΘΥΝΣΗ ΠΑΡΟΧΗΣ | Τ.Κ. | ΠΟΛΗ), γι'
> αυτό χρησιμοποιείται το `odos_arithmos_paroxis`. Το `dieuthynsi_paroxis`
> περιλαμβάνει και ΤΚ και πόλη και είναι για έντυπα με ένα ενιαίο κουτί — αν
> μπει εδώ, η πόλη τυπώνεται δύο φορές.

## Διεύθυνση αποστολής λογαριασμού

Ίδια λογική· πέφτει πίσω στη διεύθυνση κατοικίας όταν είναι τσεκαρισμένο.

| Κλειδί | Τι τυπώνει | Πηγή |
|---|---|---|
| `odos_arithmos_apostolis` | Οδός και αριθμός | `contracts.billing_street` + `billing_street_no` |
| `poli_apostolis` | Πόλη | `contracts.billing_city` |
| `tk_apostolis` | Τ.Κ. | `contracts.billing_postal_code` |

## Υπεύθυνος επικοινωνίας

| Κλειδί | Τι τυπώνει | Πηγή |
|---|---|---|
| `contact_onomateponymo` | Ονοματεπώνυμο | `extra_json.contact_first_name` + `contact_last_name` |
| `contact_tilefono` | Τηλέφωνο | `extra_json.contact_phone` |
| `contact_kinito` | Κινητό | `extra_json.contact_mobile` |
| `contact_email` | E-mail | `extra_json.contact_email` |

## Επιχείρηση — μόνο στο `protergia_he_biz`

| Κλειδί | Τι τυπώνει | Πηγή |
|---|---|---|
| `nomimos_ekprosopos` | Νόμιμος εκπρόσωπος | `extra_json.rep_first_name` + `rep_last_name` |
| `kad` | Κ.Α.Δ. | `extra_json.kad` |

## Παροχή

| Κλειδί | Τι τυπώνει | Πηγή |
|---|---|---|
| `ar_paroxis` | Αριθμός παροχής | `contracts.supply_number` |
| `hkasp` | Η.Κ.Α.Σ.Π. | `contracts.supply_number` *(ίδια τιμή με το `ar_paroxis`)* |
| `ar_metriti` | Αριθμός μετρητή | `contracts.meter_number` |
| `isxis_paroxis` | Ισχύς παροχής (kVA) | `extra_json.agreed_power` |
| `teleftaia_endeixi_imeras` | Τελευταία ένδειξη μετρητή | `extra_json.day_indication` |
| `ipistamenos_promitheftis` | Υφιστάμενος προμηθευτής | `extra_json.previous_provider` |
| `poso_eggiisis` | Ποσό εγγύησης (€) | `extra_json.guarantee` |

## Αίτηση

| Κλειδί | Τι τυπώνει | Πηγή |
|---|---|---|
| `ar_aitisis` | Αριθμός αίτησης (APP-0001) | `contracts.code` |
| `imerominia` | Ημερομηνία, `ημ/μμ/εεεε` | `contracts.created_at` |
| `topos_imerominia` | «Πόλη, ημερομηνία» σε μία γραμμή | *υπολογισμένο* |
| `synergatis` | Επωνυμία εταιρείας | Ρυθμίσεις → Επωνυμία |
| `politis` | Ον/μο πωλητή | Ο χρήστης που κατέχει τη σύμβαση |
| `kod_synergati` | Κωδικός συνεργάτη | `extra_json.kod_synergati` |

---

## Checkbox

Τυπώνουν `X` μέσα στο οβάλ. Κάθε επιλογή είναι δικό της κλειδί, και μόνο μία
παίρνει τιμή — δεν υπάρχει κλειδί «ποια επιλογή».

| Κλειδί | Μπαίνει X όταν |
|---|---|
| `cat_oikiaki` | ο πελάτης είναι ιδιώτης |
| `cat_epaggelmatiki` | ο πελάτης είναι εταιρεία ή ατομική |
| `act_new` | τύπος ενεργοποίησης = **νέα σύνδεση** |
| `act_reconnection` | τύπος ενεργοποίησης = **επανασύνδεση** |
| `metr_imerisia` | μέτρηση = ημερήσια |
| `metr_imer_nyxt` | μέτρηση = ημερήσια & νυχτερινή |
| `metr_tilemetroumeni` | μέτρηση = τηλεμετρούμενη |
| `own_idioktitis` | ιδιότητα = ιδιοκτήτης |
| `pagia_entoli` | τρόπος πληρωμής = πάγια εντολή |
| `ypovoli_ilektronika` | **πάντα** — σταθερά, το CRM υποβάλλει ηλεκτρονικά |

> Η μέτρηση, όταν δεν έχει δηλωθεί στη φόρμα, συμπεραίνεται από τον κωδικό
> τιμολογίου (Γ1 απλή, Γ1Ν με νυχτερινό), ώστε οι παλιές συμβάσεις να μη
> βγαίνουν κενές.

---

## Κλειδιά που υπάρχουν αλλά **δεν** χρησιμοποιεί η Protergia

Διαθέσιμα αν χρειαστούν σε επόμενη έκδοση του εντύπου:

`eponymo`, `onoma`, `patronymo`, `eponymia`, `afm_etaireias`, `birth_date`,
`dieuthynsi`, `arithmos`, `nomos`, `dieuthynsi_paroxis`, `odos_paroxis`,
`arithmos_paroxis`, `nomos_paroxis`, `dieuthynsi_apostolis`, `odos_apostolis`,
`arithmos_apostolis`, `nomos_apostolis`, `timologio`, `programma`, `diarkeia`,
`end_date`, `topos`, `gemi`, `nomiki_morfi`, `antikeimeno`, `eidiki_katigoria`,
`iban`, `anotato_orio`, `ar_koinoxristou`, `contact_adt`, `contact_afm`,
`own_misthotis`, `metr_esoterikos`, `metr_exoterikos`, `cat_idiotis`,
`cat_atomiki`, `cat_etaireia`, `act_change`, `act_succession`, `act_renewal`,
`act_program_change`, `act_any`, `dur_aoristou`, `dur_6`, `dur_12`, `dur_18`,
`dur_24`, `dur_36`, `ypovoli_taxydromika`

---

## Έλεγχος

```
python tools/form-map-audit.py --sources tools/source-forms --only protergia_he
```

Αναφέρει δύο πράγματα: σημάνσεις του παρόχου που κανένα πεδίο δεν καλύπτει, και
πεδία που τυπώνουν πάνω σε κείμενο του εντύπου. Και τα τρία έντυπα Protergia
πρέπει να βγάζουν μηδέν και στα δύο.
