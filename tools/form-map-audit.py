#!/usr/bin/env python3
"""
Audit — and draft — the provider form maps from the highlighted source PDFs.

The provider marks the fields we are expected to fill in yellow. Those marks
are real PDF Highlight annotations, so their positions can be read exactly
rather than estimated from a screenshot.

Two modes:

  (default)   Report highlights that no field is placed inside — data we
              silently never print — and fields whose anchor sits outside every
              highlight.

  --suggest   Additionally guess a field name for each highlight, from the
              label text inside or beside it and the section it falls under,
              and print pasteable JSON.

On the guesses: treat them as a starting point, not an answer. A missing field
leaves an obvious blank on the form; a *wrong* field prints the customer's name
in the contact-person box and looks perfectly fine until the provider rejects
the application. Confirm every suggestion against the calibration sheet
(wp-admin → Energy CRM → Έντυπα παρόχων) before trusting it.

Usage:
    pip install pymupdf
    python tools/form-map-audit.py --sources tools/source-forms
    python tools/form-map-audit.py --sources tools/source-forms --only protergia_he --suggest

Source PDFs are not committed: they are provider documents, they are large, and
they are reissued periodically. Name each file so it contains the template key
(protergia_he.pdf, orizon_activation.pdf, …).
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import unicodedata
from pathlib import Path

try:
    import fitz  # PyMuPDF
except ImportError:
    sys.exit("Χρειάζεται το PyMuPDF:  pip install pymupdf")

MM = 25.4 / 72

# Text is drawn at y + 3mm (ECRM_FormFill::BASELINE) and box fields are written
# below their label, so an anchor may legitimately sit outside its highlight.
X_SLACK = 2.0
Y_SLACK = 8.0

# Section headers, most specific first: "υπευθύνου επικοινωνίας" must be tested
# before "επικοινωνίας", or it is swallowed by it.
SECTIONS = [
    (r"στοιχειαυπευθυνουεπικοινωνιας|υπευθυνοςεπικοινωνιας", "contact"),
    (r"διευθυνσηαποστολης|αποστοληλογαριασμου", "billing"),
    (r"στοιχειανομιμουεκπροσωπου|νομιμοςεκπροσωπος", "rep"),
    (r"ταυτοτηταπαροχης|στοιχειαπαροχης|ταυτοτηταεγκαταστασης|εγκαταστασης", "supply"),
    (r"στοιχειαεταιρειας|στοιχειαεπιχειρησης", "company"),
    (r"στοιχειαπελατη|στοιχειααιτουντο", "customer"),
    (r"στοιχειαεπικοινωνιας", "customer"),
    (r"στοιχειαταυτοποιησης", "customer"),
]

# (label pattern, {section: field key}); "default" applies where the section
# does not change the meaning. Order matters: the first match wins, so a label
# carrying two names ("Επάγγελμα: … Τηλ. οικίας: …") resolves by its head.
RULES: list[tuple[str, dict[str, str]]] = [
    (r"^ονοματεπωνυμο", {"contact": "contact_onomateponymo", "rep": "nomimos_ekprosopos",
                         "default": "onomateponymo"}),
    (r"^επωνυμια", {"default": "eponymia"}),
    (r"^επωνυμο", {"default": "eponymo"}),
    (r"^ονομα$", {"default": "onoma"}),
    (r"πατρωνυμο|ονομαπατρος", {"default": "patronymo"}),
    (r"^αδτ|αρδιαβ|ταυτοτητας", {"contact": "contact_adt", "default": "adt"}),
    (r"^αφμ", {"contact": "contact_afm", "company": "afm_etaireias", "default": "afm"}),
    (r"^δου", {"default": "doy"}),
    (r"ημερομηνιαγεννησης", {"default": "birth_date"}),
    (r"^επαγγελμα|δραστηριοτητα", {"default": "epaggelma"}),
    (r"^κινητο", {"contact": "contact_kinito", "default": "kinito"}),
    (r"^τηλεφωνο|τηλοικιας|σταθερο", {"contact": "contact_tilefono", "default": "tilefono"}),
    (r"^email|ηλεκτρονικοταχυδρομειο", {"contact": "contact_email", "default": "email"}),
    (r"^διευθυνση|^οδος", {"supply": "odos_paroxis", "billing": "dieuthynsi", "default": "odos"}),
    (r"^αριθμος$|^αρ$", {"default": "arithmos"}),
    (r"^τκ|ταχκωδικ", {"supply": "tk_paroxis", "default": "tk"}),
    (r"^πολη|^περιοχη|^δημος", {"supply": "poli_paroxis", "default": "poli"}),
    (r"^νομος", {"default": "nomos"}),
    (r"αριθμοςπαροχης|αρπαροχης", {"default": "ar_paroxis"}),
    (r"ηκασπ|γεωπληροφοριακοστιγμα", {"default": "hkasp"}),
    (r"τελευταιαενδειξη|ενδειξημετρητη", {"default": "teleftaia_endeixi_imeras"}),
    (r"αριθμοςμετρητη|αρμετρητη", {"default": "ar_metriti"}),
    (r"τιμολογιο", {"default": "timologio"}),
    (r"προγραμμα", {"default": "programma"}),
    (r"διαρκεια", {"default": "diarkeia"}),
    (r"αριθμοςαιτησης|αραιτησης", {"default": "ar_aitisis"}),
    (r"^ημερομηνια$", {"default": "imerominia"}),
    (r"^τοπος", {"default": "topos"}),
    (r"ονμοσυνεργατη|ονοματεπωνυμοσυνεργατη", {"default": "synergatis"}),
    (r"ονμοπωλητη|πωλητη", {"default": "politis"}),
    (r"κωδσυνεργατη|κωδικοςσυνεργατη", {"default": "kod_synergati"}),
    (r"υφισταμενοςπρομηθευτης|προηγουμενοςπρομηθευτης|τρεχωνπρομηθευτης",
     {"default": "ipistamenos_promitheftis"}),
    (r"εγγυηση", {"default": "poso_eggiisis"}),
    (r"ισχυς", {"default": "isxis_paroxis"}),
    (r"^οικιακη", {"default": "cat_oikiaki"}),
    (r"^επαγγελματικη", {"default": "cat_epaggelmatiki"}),
    (r"^ιδιωτης", {"default": "cat_idiotis"}),
    (r"ατομικηεπιχειρηση", {"default": "cat_atomiki"}),
    (r"^εταιρεια$", {"default": "cat_etaireia"}),
    (r"αλλαγηπρομηθευτη", {"default": "act_change"}),
    (r"^διαδοχη", {"default": "act_succession"}),
    (r"επανασυνδεση", {"default": "act_reconnection"}),
    (r"^ανανεωση", {"default": "act_renewal"}),
    (r"νεαπαροχη|νεασυνδεση|ενεργοποιησησυνδεσης", {"default": "act_new"}),
]


def norm(text: str) -> str:
    stripped = unicodedata.normalize("NFD", text)
    stripped = "".join(c for c in stripped if unicodedata.category(c) != "Mn")
    return re.sub(r"[^α-ωa-z0-9]", "", stripped.lower())


def sections_on(page) -> list[tuple[float, str]]:
    lines: dict[float, list[tuple[float, str]]] = {}
    for w in page.get_text("words"):
        lines.setdefault(round(w[1] * MM, 1), []).append((w[0], w[4]))

    found = []
    for y in sorted(lines):
        text = norm(" ".join(t for _, t in sorted(lines[y])))
        for pattern, name in SECTIONS:
            if re.search(pattern, text):
                found.append((y, name))
                break
    return found


def label_for(words, box) -> str:
    x0, y0, x1, y1 = box

    # The highlight usually covers the label itself; read that first.
    inside = [w for w in words
              if x0 - 1 <= (w[0] + w[2]) / 2 * MM <= x1 + 1
              and y0 - 1 <= (w[1] + w[3]) / 2 * MM <= y1 + 1]
    if inside:
        inside.sort(key=lambda w: w[0])
        text = " ".join(w[4] for w in inside[:6])
        if norm(text):
            return text

    left = [w for w in words if w[2] * MM <= x0 + 1 and abs(w[1] * MM - y0) < 2.5]
    if left:
        left.sort(key=lambda w: w[2])
        return " ".join(w[4] for w in left[-5:])

    above = [w for w in words if 0 < y0 - w[3] * MM < 5 and x0 - 3 <= w[0] * MM <= x0 + 45]
    above.sort(key=lambda w: (-w[3], w[0]))
    return " ".join(w[4] for w in above[:5])


def guess_key(label: str, section: str) -> str | None:
    full = norm(label)
    # A dotted line often carries several labels; resolve by the leading one.
    head = norm(re.split(r"[.:]{2,}|\s{2,}", label.strip())[0])

    for pattern, table in RULES:
        if re.search(pattern, head) or re.search(pattern, full):
            return table.get(section) or table.get("default")
    return None


def marks(pdf: Path) -> list[dict]:
    out = []
    with fitz.open(pdf) as doc:
        for index, page in enumerate(doc, start=1):
            annots = [a.rect for a in (page.annots() or []) if a.type[1] == "Highlight"]
            if not annots:
                continue

            words = page.get_text("words")
            headers = sections_on(page)

            for r in annots:
                box = (r.x0 * MM, r.y0 * MM, r.x1 * MM, r.y1 * MM)
                label = label_for(words, box)

                # A highlighted section header is not a field.
                if any(re.search(p, norm(label)) for p, _ in SECTIONS):
                    continue

                section = "default"
                for hy, name in headers:
                    if hy <= box[1] + 1:
                        section = name

                out.append({
                    "page": index, "x": round(box[0], 1), "y": round(box[1], 1),
                    "w": round(box[2] - box[0], 1), "label": label.strip(),
                    "section": section, "key": guess_key(label, section),
                })
    return out


def covered(field: dict, page: int, x: float, y: float, x1: float, y1: float) -> bool:
    return (int(field.get("page", 1)) == page
            and x - X_SLACK <= float(field["x"]) <= x1 + X_SLACK
            and y - Y_SLACK <= float(field["y"]) <= y1 + Y_SLACK)


def audit(key: str, pdf: Path, forms: Path, suggest: bool) -> int:
    fields = json.loads((forms / f"{key}.json").read_text(encoding="utf-8"))["fields"]
    found = marks(pdf)

    missing = [m for m in found
               if not any(covered(f, m["page"], m["x"], m["y"],
                                  m["x"] + m["w"], m["y"] + 3) for f in fields.values())]
    named = [m for m in missing if m["key"]]

    print(f"\n=== {key}")
    print(f"    σημάνσεις: {len(found)}   χαρτογραφημένα: {len(fields)}   "
          f"ακάλυπτες: {len(missing)}   με πρόταση ονόματος: {len(named)}")

    for m in sorted(missing, key=lambda m: (m["page"], m["y"], m["x"])):
        print(f"    σελ {m['page']}  x={m['x']:6.1f} y={m['y']:6.1f}  "
              f"[{m['section']:8}] {(m['key'] or '???'):26} « {m['label'][:40]} »")

    if suggest and named:
        print("\n    -- για επικόλληση στο JSON, μετά από έλεγχο --")
        for m in sorted(named, key=lambda m: (m["page"], m["y"])):
            print(f'    "{m["key"]}": {{ "page": {m["page"]}, '
                  f'"x": {m["x"] + 0.5:.1f}, "y": {m["y"]:.1f} }},')

    return len(missing)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--sources", required=True, type=Path)
    parser.add_argument("--forms", type=Path,
                        default=Path(__file__).resolve().parent.parent / "assets" / "forms")
    parser.add_argument("--only")
    parser.add_argument("--suggest", action="store_true")
    args = parser.parse_args()

    total = 0
    for path in sorted(args.forms.glob("*.json")):
        key = path.stem
        if args.only and key != args.only:
            continue

        matches = [p for p in args.sources.glob("*.pdf") if key in p.stem]
        if not matches:
            print(f"\n=== {key}\n    (δεν βρέθηκε PDF με «{key}» στο όνομα)")
            continue

        total += audit(key, matches[0], args.forms, args.suggest)

    print(f"\nΣΥΝΟΛΟ ακάλυπτων σημάνσεων: {total}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
