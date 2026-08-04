#!/usr/bin/env python3
"""
Build provider form maps from the highlighted source PDFs.

The audit tool tells you what is missing. This one proposes the answer.

For every highlighted line it reads the labels that line actually carries and
places one field after each of them, using the point where the label text ends
rather than where the highlight starts. That distinction is the whole game: a
highlight covering "Διεύθυνση: ....... Τ.Κ.: ..... Πόλη: ....." is three
fields, and anchoring any of them to the left edge of the mark puts the street
name on top of the town.

Two layouts are handled:

  label ending in ":"   value goes inline, just after the colon
  bare label in a box   value goes on the next line down, at the box's left

Output is JSON on stdout, or written in place with --write.

    python tools/form-map-build.py --pdf tools/source-forms/protergia_he.pdf
    python tools/form-map-build.py --all --write

Existing coordinates are kept unless --overwrite is given: a position someone
verified by eye beats one this script inferred.
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

GAP_AFTER_LABEL = 1.8   # mm between the end of a label and its value
BOX_DROP = 6.0          # mm below a bare label, for box layouts

SECTIONS = [
    (r"στοιχειαυπευθυνουεπικοινωνιας|υπευθυνοςεπικοινωνιας", "contact"),
    (r"διευθυνσηαποστολης|αποστοληλογαριασμου", "billing"),
    (r"στοιχειανομιμουεκπροσωπου|νομιμοςεκπροσωπος", "rep"),
    (r"ταυτοτηταπαροχης|στοιχειαπαροχης|ταυτοτηταεγκαταστασης", "supply"),
    (r"στοιχειαεταιρειας|στοιχειαεπιχειρησης", "company"),
    (r"στοιχειαπελατη|στοιχειααιτουντο|στοιχειαεπικοινωνιας|στοιχειαταυτοποιησης", "customer"),
]

RULES: list[tuple[str, dict[str, str]]] = [
    (r"^ονοματεπωνυμο|^ονομνυμο|^ονμο$", {"contact": "contact_onomateponymo",
                                          "rep": "nomimos_ekprosopos",
                                          "default": "onomateponymo"}),
    (r"^επωνυμια", {"default": "eponymia"}),
    (r"^επωνυμο", {"default": "eponymo"}),
    (r"^ονομα$", {"default": "onoma"}),
    (r"πατρωνυμο|ονομαπατρος", {"default": "patronymo"}),
    (r"^αδτ|αρδιαβ|δελτιοταυτοτητας|εγγραφουταυτοπροσωπιας|αρταυτοτητας", {"contact": "contact_adt", "default": "adt"}),
    (r"^αφμ", {"contact": "contact_afm", "company": "afm_etaireias", "default": "afm"}),
    (r"^δου", {"default": "doy"}),
    (r"ημερομηνιαγεννησης|^γεννησης", {"default": "birth_date"}),
    (r"^επαγγελμα|δραστηριοτητα", {"default": "epaggelma"}),
    (r"^κινητο", {"contact": "contact_kinito", "default": "kinito"}),
    (r"^τηλεφωνο|τηλοικιας|^τηλ$|σταθερο|τηλεπικοινωνιας", {"contact": "contact_tilefono", "default": "tilefono"}),
    (r"^email|^emai|ηλεκτρονικοταχυδρομειο", {"contact": "contact_email", "default": "email"}),
    (r"^διευθυνσηπαροχης", {"default": "odos_paroxis"}),
    (r"^διευθυνση|^οδος", {"supply": "odos_paroxis", "billing": None, "default": "odos"}),
    (r"^τκπαροχης", {"default": "tk_paroxis"}),
    (r"^τκ|ταχκωδικ", {"supply": "tk_paroxis", "billing": None, "default": "tk"}),
    (r"^πολη|^περιοχη|^δημος", {"supply": "poli_paroxis", "billing": None, "default": "poli"}),
    (r"^νομος", {"default": "nomos"}),
    (r"αριθμοςπαροχης|αρπαροχης", {"default": "ar_paroxis"}),
    (r"ηκασπ|γεωπληροφοριακοστιγμα", {"default": "hkasp"}),
    (r"τελευταιαενδειξη|ενδειξημετρητη", {"default": "teleftaia_endeixi_imeras"}),
    (r"αριθμοςμετρητη|αρμετρητη", {"default": "ar_metriti"}),
    (r"^τιμολογιο", {"default": "timologio"}),
    (r"^προγραμμα", {"default": "programma"}),
    (r"^διαρκεια", {"default": "diarkeia"}),
    (r"αριθμοςαιτησης|αραιτησης|κωδικοςαιτησης|κωδαιτησης", {"default": "ar_aitisis"}),
    (r"^ημερομηνια", {"default": "imerominia"}),
    (r"^τοπος", {"default": "topos"}),
    (r"ονμοσυνεργατη|ονοματεπωνυμοσυνεργατη", {"default": "synergatis"}),
    (r"ονμοπωλητη|^πωλητη", {"default": "politis"}),
    (r"κωδσυνεργατη|κωδικοςσυνεργατη", {"default": "kod_synergati"}),
    (r"υφισταμενοςπρομηθευτης|προηγουμενοςπρομηθευτης|τρεχωνπρομηθευτης|προηγπρομηθευτης",
     {"default": "ipistamenos_promitheftis"}),
    (r"εγγυηση", {"default": "poso_eggiisis"}),
    (r"^ισχυς|συμφωνημενηισχυς|^σ1", {"default": "isxis_paroxis"}),
    (r"^καδ", {"default": "kad"}),
    (r"^αργεμη|^γεμη", {"default": "gemi"}),
    (r"νομικημορφη", {"default": "nomiki_morfi"}),
    (r"αντικειμενοεπιχειρησης|^χρηση$", {"default": "antikeimeno"}),
    (r"ειδικηκατηγορια", {"default": "eidiki_katigoria"}),
    (r"^iban|τραπεζικουλογαριασμου", {"default": "iban"}),
    (r"ανωτατοοριολογαριασμου", {"default": "anotato_orio"}),
    (r"αριθμοςκοινοχρηστου", {"default": "ar_koinoxristou"}),
    (r"αριθμοςκινητου", {"contact": "contact_kinito", "default": "kinito"}),
]

# Checkbox labels: the engine stamps an X when the matching key is non-empty.
CHECKS = [
    (r"^οικιακη", "cat_oikiaki"),
    (r"^επαγγελματικη|^εμπορικη", "cat_epaggelmatiki"),
    (r"^ιδιωτης|^φυσικοπροσωπο", "cat_idiotis"),
    (r"ατομικηεπιχειρηση", "cat_atomiki"),
    (r"^εταιρεια$|^νομικοπροσωπο", "cat_etaireia"),
    (r"αλλαγηπρομηθευτη", "act_change"),
    (r"^διαδοχη", "act_succession"),
    (r"επανασυνδεση|επαναηλεκτροδοτηση", "act_reconnection"),
    (r"^ανανεωση", "act_renewal"),
    (r"νεαπαροχη|νεασυνδεση|ενεργοποιησησυνδεσης|αρχικηενεργοποιηση", "act_new"),
    (r"^ημερησια&νυχτερινη|ημερησιακαινυχτερινη", "metr_imer_nyxt"),
    (r"^ημερησια$", "metr_imerisia"),
    (r"^αοριστου", "dur_aoristou"),
    (r"^ιδιοκτητης", "own_idioktitis"),
    (r"^μισθωτης|^ενοικιαστης", "own_misthotis"),
    (r"^εσωτερικος", "metr_esoterikos"),
    (r"^εξωτερικος", "metr_exoterikos"),
    (r"παγιαεντολη", "pagia_entoli"),
    (r"^μηοικιακος|^μηοικιακη", "cat_epaggelmatiki"),
    (r"^οικιακος", "cat_oikiaki"),
]


def norm(text: str) -> str:
    stripped = unicodedata.normalize("NFD", text)
    stripped = "".join(c for c in stripped if unicodedata.category(c) != "Mn")
    return re.sub(r"[^α-ωa-z0-9&]", "", stripped.lower())


def is_filler(token: str) -> bool:
    """Dotted leaders and underscores mark where a value goes, not a label."""
    return bool(token) and set(token) <= {".", "_", "…", "·"}


def sections_on(page) -> list[tuple[float, str]]:
    rows: dict[float, list[tuple[float, str]]] = {}
    for w in page.get_text("words"):
        rows.setdefault(round(w[1] * MM, 1), []).append((w[0], w[4]))

    found = []
    for y in sorted(rows):
        text = norm(" ".join(t for _, t in sorted(rows[y])))
        for pattern, name in SECTIONS:
            if re.search(pattern, text):
                found.append((y, name))
                break
    return found


def section_at(y: float, headers: list[tuple[float, str]]) -> str:
    current = "default"
    for hy, name in headers:
        if hy <= y + 1:
            current = name
    return current


def resolve(label: str, section: str) -> tuple[str | None, bool]:
    """Field key for a label, and whether it is a checkbox."""
    key = norm(label).rstrip(":")

    for pattern, name in CHECKS:
        if re.search(pattern, key):
            return name, True

    for pattern, table in RULES:
        if re.search(pattern, key):
            if section in table:
                return table[section], False
            return table.get("default"), False
    return None, False


def labels_on_line(words, y: float, x_from: float, x_to: float) -> list[tuple[str, float]]:
    """
    Labels on a line and the x where each one ends.

    A label runs until the dotted leader that follows it, so several labels on
    one line come back separately — which is what makes "Διεύθυνση: … Τ.Κ.: …
    Πόλη: …" resolve into three fields instead of one.
    """
    row = sorted((w for w in words if abs(w[1] * MM - y) < 1.6
                  and x_from - 1 <= w[0] * MM <= x_to + 1), key=lambda w: w[0])

    out: list[tuple[str, float]] = []
    current: list[str] = []
    end = 0.0

    for w in row:
        token = w[4]
        if is_filler(token):
            if current:
                out.append((" ".join(current), end))
                current = []
            continue
        current.append(token)
        end = w[2] * MM
        if token.endswith(":"):
            out.append((" ".join(current), end))
            current = []

    if current:
        out.append((" ".join(current), end))

    return out


def stitch_above(words, y: float, x_from: float, x_to: float, label: str) -> str:
    """Prepend the text sitting directly above, for labels split over two lines."""
    above = [w for w in words
             if 0 < y - w[3] * MM < 4.5
             and x_from - 2 <= w[0] * MM <= x_to + 2
             and not is_filler(w[4])]
    above.sort(key=lambda w: w[0])

    return (" ".join(w[4] for w in above) + " " + label).strip()


def build(pdf: Path) -> dict:
    fields: dict[str, dict] = {}
    unresolved: list[str] = []

    with fitz.open(pdf) as doc:
        page_w = doc[0].rect.width * MM
        page_h = doc[0].rect.height * MM

        for index, page in enumerate(doc, start=1):
            annots = [a.rect for a in (page.annots() or []) if a.type[1] == "Highlight"]
            if not annots:
                continue

            words = page.get_text("words")
            headers = sections_on(page)

            for r in annots:
                x0, y0, x1 = r.x0 * MM, r.y0 * MM, r.x1 * MM
                section = section_at(y0, headers)
                found = labels_on_line(words, y0, x0, x1)

                # The mark sits on a section header, not on a field.
                if any(re.search(p, norm(" ".join(t for t, _ in found))) for p, _ in SECTIONS):
                    continue

                # A bare label with nothing after it is a box: the value goes
                # underneath. Anything with a colon or a dotted leader is inline.
                inline = len(found) > 1 or any(t.strip().endswith(":") for t, _ in found)

                for label, ends in found:
                    key, is_check = resolve(label, section)

                    # A label wrapped onto two lines ("ΑΡΙΘΜΟΣ" above,
                    # "ΠΑΡΟΧΗΣ" below) reads as nonsense on its own. Retry with
                    # the line above stitched on before giving up.
                    if key is None:
                        key, is_check = resolve(stitch_above(words, y0, x0, x1, label), section)

                    if key is None:
                        if norm(label):
                            unresolved.append(f"σελ{index} y={y0:.1f} « {label[:40]} »")
                        continue
                    if key in fields:
                        continue

                    entry = {"page": index}
                    if is_check:
                        entry |= {"x": round(x0 + 1.0, 1), "y": round(y0, 1), "check": True}
                    elif inline:
                        entry |= {"x": round(ends + GAP_AFTER_LABEL, 1), "y": round(y0, 1)}
                    else:
                        entry |= {"x": round(x0 + 1.0, 1), "y": round(y0 + BOX_DROP, 1)}

                    fields[key] = entry

    return {"page_w": round(page_w), "page_h": round(page_h),
            "fields": fields, "_unresolved": unresolved}


def merge(existing: dict, proposed: dict, overwrite: bool) -> tuple[dict, int, int]:
    merged = dict(existing.get("fields", {}))
    added = changed = 0

    for key, entry in proposed["fields"].items():
        if key not in merged:
            merged[key] = entry
            added += 1
        elif overwrite and (merged[key].get("x") != entry["x"]
                            or merged[key].get("y") != entry["y"]):
            merged[key] |= entry
            changed += 1

    out = dict(existing)
    out["fields"] = merged
    return out, added, changed


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    root = Path(__file__).resolve().parent.parent
    parser.add_argument("--pdf", type=Path)
    parser.add_argument("--all", action="store_true")
    parser.add_argument("--sources", type=Path, default=root / "tools" / "source-forms")
    parser.add_argument("--forms", type=Path, default=root / "assets" / "forms")
    parser.add_argument("--write", action="store_true")
    parser.add_argument("--overwrite", action="store_true",
                        help="Άλλαξε και συντεταγμένες που υπάρχουν ήδη")
    args = parser.parse_args()

    pdfs = sorted(args.sources.glob("*.pdf")) if args.all else [args.pdf]

    for pdf in pdfs:
        if pdf is None or not pdf.exists():
            print(f"δεν βρέθηκε: {pdf}", file=sys.stderr)
            continue

        key = pdf.stem
        target = args.forms / f"{key}.json"
        proposed = build(pdf)

        if not target.exists():
            print(f"\n=== {key}: δεν υπάρχει {target.name}, παραλείπεται")
            continue

        existing = json.loads(target.read_text(encoding="utf-8"))
        merged, added, changed = merge(existing, proposed, args.overwrite)

        print(f"\n=== {key}: +{added} νέα, {changed} διορθώσεις, "
              f"{len(proposed['_unresolved'])} χωρίς αντιστοίχιση")
        for line in proposed["_unresolved"][:12]:
            print(f"    ? {line}")

        if args.write and (added or changed):
            target.write_text(json.dumps(merged, ensure_ascii=False, indent=1),
                              encoding="utf-8")
            print(f"    γράφτηκε {target.name} ({len(merged['fields'])} πεδία)")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
