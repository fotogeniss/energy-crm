#!/usr/bin/env python3
"""
One-off rename of every form fill key to a self-describing name.

The old names were written by whoever first mapped each form, and they read
like abbreviations rather than descriptions: `tk` for the customer's postcode
next to `tk_paroxis` and `tk_apostolis`, `synergatis` for the *company* while
`politis` held the actual salesperson, `arithmos_paroxis` for a street number
sitting beside `ar_paroxis` for a supply number. Anyone editing a map had to
open ECRM_FormFill to find out what a key meant.

The scheme, applied consistently:

    <τι είναι>_<ποιανού / πού>

    tk_katoikias   tk_paroxis   tk_apostolis
    onomateponymo_pelati   onomateponymo_epikoinonias   onomateponymo_ekprosopou

Checkbox groups carry the question in front of the answer, so the options of
one group sort together and read as a sentence:

    energopoiisi_nea_syndesi, energopoiisi_epanasyndesi, …
    metrisi_imerisia, metrisi_imerisia_nyxterini, …

Run once, from the plugin root:

    python tools/rename-fill-keys.py

It rewrites ECRM_FormFill, every assets/forms/*.json, ProviderFormFields,
form-map-build.py and the docs, then verifies that no key was left behind. The
file is kept in the repository as the record of what became what.
"""

from __future__ import annotations

import json
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

# Old name => new name. Keys already clear enough are absent and stay as they
# are: kad, gemi, iban, hkasp, nomiki_morfi, eidiki_katigoria, anotato_orio,
# poso_eggiisis, isxis_paroxis, ipistamenos_promitheftis, afm_etaireias,
# ypovoli_ilektronika, ypovoli_taxydromika.
RENAMES: dict[str, str] = {
    # --- Στοιχεία πελάτη ---------------------------------------------------
    "onomateponymo": "onomateponymo_pelati",
    "eponymo": "eponymo_pelati",
    "onoma": "onoma_pelati",
    "patronymo": "patronymo_pelati",
    "eponymia": "eponymia_etaireias",
    "afm": "afm_pelati",
    "doy": "doy_pelati",
    "adt": "adt_pelati",
    "birth_date": "imerominia_gennisis",
    "tilefono": "tilefono_pelati",
    "kinito": "kinito_pelati",
    "email": "email_pelati",
    "epaggelma": "epaggelma_pelati",

    # --- Διεύθυνση κατοικίας / έδρας ---------------------------------------
    # `odos` printed street *and* number together, which is why forms with a
    # separate ΑΡΙΘΜΟΣ box printed the number twice. The name now says so.
    "odos": "odos_arithmos_katoikias",
    "arithmos": "arithmos_odou_katoikias",
    "dieuthynsi": "dieuthynsi_katoikias",
    "poli": "poli_katoikias",
    "tk": "tk_katoikias",
    "nomos": "nomos_katoikias",

    # --- Διεύθυνση παροχής --------------------------------------------------
    # `ar_paroxis` (the supply number) and `arithmos_paroxis` (a street number)
    # differed by three letters and meant completely different things.
    "ar_paroxis": "arithmos_paroxis",
    "arithmos_paroxis": "arithmos_odou_paroxis",
    "ar_metriti": "arithmos_metriti",
    "teleftaia_endeixi_imeras": "teleftaia_endeixi_metriti",

    # --- Διεύθυνση αποστολής λογαριασμού ------------------------------------
    "arithmos_apostolis": "arithmos_odou_apostolis",

    # --- Υπεύθυνος επικοινωνίας ---------------------------------------------
    "contact_onomateponymo": "onomateponymo_epikoinonias",
    "contact_adt": "adt_epikoinonias",
    "contact_afm": "afm_epikoinonias",
    "contact_tilefono": "tilefono_epikoinonias",
    "contact_kinito": "kinito_epikoinonias",
    "contact_email": "email_epikoinonias",

    # --- Επιχείρηση ----------------------------------------------------------
    "nomimos_ekprosopos": "onomateponymo_ekprosopou",
    "antikeimeno": "antikeimeno_epixeirisis",
    "ar_koinoxristou": "arithmos_koinoxristou",

    # --- Αίτηση / σύμβαση ----------------------------------------------------
    # `synergatis` held the company name and `politis` the salesperson, which
    # is close to the opposite of how they read.
    "synergatis": "eponymia_etaireias_mas",
    "politis": "onomateponymo_politi",
    "kod_synergati": "kodikos_synergati",
    "ar_aitisis": "arithmos_aitisis",
    "imerominia": "imerominia_aitisis",
    "end_date": "imerominia_liksis",
    "topos": "topos_aitisis",
    "topos_imerominia": "topos_imerominia_aitisis",
    "timologio": "kodikos_timologiou",
    "programma": "onoma_programmatos",
    "diarkeia": "diarkeia_symvasis",

    # --- Checkbox: ιδιότητα --------------------------------------------------
    "own_idioktitis": "idiotita_idioktitis",
    "own_misthotis": "idiotita_misthotis",

    # --- Checkbox: μετρητής --------------------------------------------------
    "metr_esoterikos": "thesi_metriti_esoterikos",
    "metr_exoterikos": "thesi_metriti_exoterikos",
    "metr_imerisia": "metrisi_imerisia",
    "metr_imer_nyxt": "metrisi_imerisia_nyxterini",
    "metr_tilemetroumeni": "metrisi_tilemetroumeni",

    # --- Checkbox: τύπος πελάτη / κατηγορία παροχής --------------------------
    "cat_idiotis": "typos_pelati_idiotis",
    "cat_atomiki": "typos_pelati_atomiki",
    "cat_etaireia": "typos_pelati_etaireia",
    "cat_oikiaki": "katigoria_paroxis_oikiaki",
    "cat_epaggelmatiki": "katigoria_paroxis_epaggelmatiki",

    # --- Checkbox: ενεργοποίηση ---------------------------------------------
    "act_change": "energopoiisi_allagi_paroxou",
    "act_succession": "energopoiisi_diadoxi",
    "act_reconnection": "energopoiisi_epanasyndesi",
    "act_renewal": "energopoiisi_ananeosi",
    "act_new": "energopoiisi_nea_syndesi",
    "act_program_change": "energopoiisi_allagi_programmatos",
    "act_any": "energopoiisi_apaiteitai",

    # --- Checkbox: διάρκεια / πληρωμή ---------------------------------------
    "dur_aoristou": "diarkeia_aoristou",
    "dur_6": "diarkeia_6_mines",
    "dur_12": "diarkeia_12_mines",
    "dur_18": "diarkeia_18_mines",
    "dur_24": "diarkeia_24_mines",
    "dur_36": "diarkeia_36_mines",
    "pagia_entoli": "pliromi_pagia_entoli",
}

def check_map() -> None:
    """
    A new name may equal an old one only if that old one is itself renamed and
    is longer, so the ordered pass rewrites it first.

    `ar_paroxis` becomes `arithmos_paroxis`, which is what the *street number*
    used to be called. Getting that order wrong turns two distinct fields into
    one and prints a supply number where a house number belongs.
    """
    duplicates = [v for v in set(RENAMES.values()) if list(RENAMES.values()).count(v) > 1]
    if duplicates:
        sys.exit(f"Δύο κλειδιά καταλήγουν στο ίδιο όνομα: {sorted(duplicates)}")

    for old, new in RENAMES.items():
        if new in RENAMES and len(new) <= len(old):
            sys.exit(f"Σύγκρουση σειράς: {old} -> {new}, ενώ το {new} μετονομάζεται επίσης")


def rename_tokens(text: str, pattern: str) -> tuple[str, int]:
    """
    Rewrite keys only where `pattern` says they are keys.

    Blanket search-and-replace is not safe here: the same word is a fill key,
    a database column and a form input name at the same time. `'email' =>` is
    a fill key; `$c['email']` is the customers table; `['email']` inside INPUTS
    is the name of a field on screen. Only the first may move.

    `pattern` must contain a single {key} placeholder.
    """
    count = 0
    for old in sorted(RENAMES, key=len, reverse=True):
        text, n = re.subn(pattern.format(key=re.escape(old)),
                          lambda m, o=old: m.group(0).replace(o, RENAMES[o], 1),
                          text)
        count += n
    return text, count


def slice_const(text: str, name: str) -> tuple[int, int]:
    """Byte range of a PHP `private const NAME = [ … ];` block."""
    start = text.index(f"const {name} = [")
    end = text.index("\n    ];", start)
    return start, end


def main() -> int:
    check_map()

    # 1. The maps themselves — a plain key rename, applied simultaneously.
    for form in sorted((ROOT / "assets" / "forms").glob("*.json")):
        data = json.loads(form.read_text(encoding="utf-8"))
        data["fields"] = {RENAMES.get(k, k): v for k, v in data["fields"].items()}
        if isinstance(data.get("labels"), dict):
            data["labels"] = {RENAMES.get(k, k): v for k, v in data["labels"].items()}
        form.write_text(json.dumps(data, ensure_ascii=False, indent=1) + "\n",
                        encoding="utf-8")
        print(f"  {form.name}: {len(data['fields'])} πεδία")

    # 2. ECRM_FormFill::values() — the array keys only. The right-hand side
    #    reads database columns that happen to share the same words.
    path = ROOT / "includes/class-ecrm-formfill.php"
    text = path.read_text(encoding="utf-8")
    head, body = text[:text.index("public static function values")], text[text.index("public static function values"):]
    body, n = rename_tokens(body, r"'{key}'(\s*)=>")
    path.write_text(head + body, encoding="utf-8")
    print(f"  class-ecrm-formfill.php: {n} κλειδιά")

    # 3. ProviderFormFields — FROM_COLUMNS is a list of fill keys, INPUTS is
    #    keyed by fill key. LABELS and DROPDOWNS are keyed by *input* name and
    #    must not move; INPUTS' values are input names too.
    path = ROOT / "src/Domain/Forms/ProviderFormFields.php"
    text = path.read_text(encoding="utf-8")
    total = 0
    for const, pattern in (("FROM_COLUMNS", r"'{key}'"), ("INPUTS", r"'{key}'(\s*)=>")):
        start, end = slice_const(text, const)
        block, n = rename_tokens(text[start:end], pattern)
        text = text[:start] + block + text[end:]
        total += n
    path.write_text(text, encoding="utf-8")
    print(f"  ProviderFormFields.php: {total} κλειδιά")

    # 4. The map builder holds fill keys as whole double-quoted strings; its
    #    regexes are Greek and cannot collide.
    path = ROOT / "tools/form-map-build.py"
    text, n = rename_tokens(path.read_text(encoding="utf-8"), r'"{key}"')
    path.write_text(text, encoding="utf-8")
    print(f"  form-map-build.py: {n} κλειδιά")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
