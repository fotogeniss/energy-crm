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
| `onomateponymo_pelati` | Ονοματεπώνυμο· για εταιρεία η επωνυμία | `first_name` + `last_name`, ή `company_name` |
| `afm_pelati` | ΑΦΜ | `customers.afm` |
| `doy_pelati` | ΔΟΥ | `customers.doy` |
| `adt_pelati` | ΑΔΤ ή αρ. διαβατηρίου | `customers.adt` |
| `tilefono_pelati` | Τηλέφωνο οικίας | `customers.phone` |
| `kinito_pelati` | Κινητό | `customers.mobile` |
| `email_pelati` | E-mail | `customers.email` |
| `epaggelma_pelati` | Επάγγελμα / αντικείμενο | `extra_json.activity` |

## Διεύθυνση κατοικίας / έδρας

| Κλειδί | Τι τυπώνει | Πηγή |
|---|---|---|
| `odos_arithmos_katoikias` | Οδός **και** αριθμός | `street` + `street_no` |
| `poli_katoikias` | Πόλη | `customers.city` |
| `tk_katoikias` | Τ.Κ. | `customers.postal_code` |

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
| `onomateponymo_epikoinonias` | Ονοματεπώνυμο | `extra_json.contact_first_name` + `contact_last_name` |
| `tilefono_epikoinonias` | Τηλέφωνο | `extra_json.contact_phone` |
| `kinito_epikoinonias` | Κινητό | `extra_json.contact_mobile` |
| `email_epikoinonias` | E-mail | `extra_json.contact_email` |

## Επιχείρηση — μόνο στο `protergia_he_biz`

| Κλειδί | Τι τυπώνει | Πηγή |
|---|---|---|
| `onomateponymo_ekprosopou` | Νόμιμος εκπρόσωπος | `extra_json.rep_first_name` + `rep_last_name` |
| `kad` | Κ.Α.Δ. | `extra_json.kad` |

## Παροχή

| Κλειδί | Τι τυπώνει | Πηγή |
|---|---|---|
| `arithmos_paroxis` | Αριθμός παροχής | `contracts.supply_number` |
| `hkasp` | Η.Κ.Α.Σ.Π. | `contracts.supply_number` *(ίδια τιμή με το `arithmos_paroxis`)* |
| `arithmos_metriti` | Αριθμός μετρητή | `contracts.meter_number` |
| `isxis_paroxis` | Ισχύς παροχής (kVA) | `extra_json.agreed_power` |
| `teleftaia_endeixi_metriti` | Τελευταία ένδειξη μετρητή | `extra_json.day_indication` |
| `ipistamenos_promitheftis` | Υφιστάμενος προμηθευτής | `extra_json.previous_provider` |
| `poso_eggiisis` | Ποσό εγγύησης (€) | `extra_json.guarantee` |

## Αίτηση

| Κλειδί | Τι τυπώνει | Πηγή |
|---|---|---|
| `arithmos_aitisis` | Αριθμός αίτησης (APP-0001) | `contracts.code` |
| `imerominia_aitisis` | Ημερομηνία, `ημ/μμ/εεεε` | `contracts.created_at` |
| `topos_imerominia_aitisis` | «Πόλη, ημερομηνία» σε μία γραμμή | *υπολογισμένο* |
| `eponymia_etaireias_mas` | Επωνυμία εταιρείας | Ρυθμίσεις → Επωνυμία |
| `onomateponymo_politi` | Ον/μο πωλητή | Ο χρήστης που κατέχει τη σύμβαση |
| `kodikos_synergati` | Κωδικός συνεργάτη | `extra_json.kod_synergati` |

---

## Checkbox

Τυπώνουν `X` μέσα στο οβάλ. Κάθε επιλογή είναι δικό της κλειδί, και μόνο μία
παίρνει τιμή — δεν υπάρχει κλειδί «ποια επιλογή».

| Κλειδί | Μπαίνει X όταν |
|---|---|
| `katigoria_paroxis_oikiaki` | ο πελάτης είναι ιδιώτης |
| `katigoria_paroxis_epaggelmatiki` | ο πελάτης είναι εταιρεία ή ατομική |
| `energopoiisi_nea_syndesi` | τύπος ενεργοποίησης = **νέα σύνδεση** |
| `energopoiisi_epanasyndesi` | τύπος ενεργοποίησης = **επανασύνδεση** |
| `metrisi_imerisia` | μέτρηση = ημερήσια |
| `metrisi_imerisia_nyxterini` | μέτρηση = ημερήσια & νυχτερινή |
| `metrisi_tilemetroumeni` | μέτρηση = τηλεμετρούμενη |
| `idiotita_idioktitis` | ιδιότητα = ιδιοκτήτης |
| `pliromi_pagia_entoli` | τρόπος πληρωμής = πάγια εντολή |
| `ypovoli_ilektronika` | **πάντα** — σταθερά, το CRM υποβάλλει ηλεκτρονικά |

> Η μέτρηση, όταν δεν έχει δηλωθεί στη φόρμα, συμπεραίνεται από τον κωδικό
> τιμολογίου (Γ1 απλή, Γ1Ν με νυχτερινό), ώστε οι παλιές συμβάσεις να μη
> βγαίνουν κενές.

---

## Κλειδιά που υπάρχουν αλλά **δεν** χρησιμοποιεί η Protergia

Διαθέσιμα αν χρειαστούν σε επόμενη έκδοση του εντύπου:

`eponymo_pelati`, `onoma_pelati`, `patronymo_pelati`, `eponymia_etaireias`, `afm_etaireias`, `imerominia_gennisis`,
`dieuthynsi_katoikias`, `arithmos_odou_katoikias`, `nomos_katoikias`, `dieuthynsi_paroxis`, `odos_paroxis`,
`arithmos_odou_paroxis`, `nomos_paroxis`, `dieuthynsi_apostolis`, `odos_apostolis`,
`arithmos_odou_apostolis`, `nomos_apostolis`, `kodikos_timologiou`, `onoma_programmatos`, `diarkeia_symvasis`,
`imerominia_liksis`, `topos_aitisis`, `gemi`, `nomiki_morfi`, `antikeimeno_epixeirisis`, `eidiki_katigoria`,
`iban`, `anotato_orio`, `arithmos_koinoxristou`, `adt_epikoinonias`, `afm_epikoinonias`,
`idiotita_misthotis`, `thesi_metriti_esoterikos`, `thesi_metriti_exoterikos`, `typos_pelati_idiotis`,
`typos_pelati_atomiki`, `typos_pelati_etaireia`, `energopoiisi_allagi_paroxou`, `energopoiisi_diadoxi`, `energopoiisi_ananeosi`,
`energopoiisi_allagi_programmatos`, `energopoiisi_apaiteitai`, `diarkeia_aoristou`, `diarkeia_6_mines`, `diarkeia_12_mines`, `diarkeia_18_mines`,
`diarkeia_24_mines`, `diarkeia_36_mines`, `ypovoli_taxydromika`

---

## Έλεγχος

```
python tools/form-map-audit.py --sources tools/source-forms --only protergia_he
```

Αναφέρει δύο πράγματα: σημάνσεις του παρόχου που κανένα πεδίο δεν καλύπτει, και
πεδία που τυπώνουν πάνω σε κείμενο του εντύπου. Και τα τρία έντυπα Protergia
πρέπει να βγάζουν μηδέν και στα δύο.
