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
    (r"^ονοματεπωνυμο|^ονομνυμο|^ονμο$", {"contact": "onomateponymo_epikoinonias",
                                          "rep": "onomateponymo_ekprosopou",
                                          "default": "onomateponymo_pelati"}),
    (r"^επωνυμια", {"default": "eponymia_etaireias"}),
    (r"^επωνυμο", {"default": "eponymo_pelati"}),
    (r"^ονομα$", {"default": "onoma_pelati"}),
    (r"πατρωνυμο|ονομαπατρος", {"default": "patronymo_pelati"}),
    (r"^αδτ|αρδιαβ|δελτιοταυτοτητας|εγγραφουταυτοπροσωπιας|αρταυτοτητας", {"contact": "adt_epikoinonias", "default": "adt_pelati"}),
    (r"^αφμ", {"contact": "afm_epikoinonias", "company": "afm_etaireias", "default": "afm_pelati"}),
    (r"^δου", {"default": "doy_pelati"}),
    (r"ημερομηνιαγεννησης|^γεννησης", {"default": "imerominia_gennisis"}),
    (r"^επαγγελμα|δραστηριοτητα", {"default": "epaggelma_pelati"}),
    (r"^κινητο", {"contact": "kinito_epikoinonias", "default": "kinito_pelati"}),
    (r"^τηλεφωνο|τηλοικιας|^τηλ$|σταθερο|τηλεπικοινωνιας", {"contact": "tilefono_epikoinonias", "default": "tilefono_pelati"}),
    (r"^email|^emai|ηλεκτρονικοταχυδρομειο", {"contact": "email_epikoinonias", "default": "email_pelati"}),
    (r"^διευθυνσηπαροχης", {"default": "odos_paroxis"}),
    (r"^διευθυνση|^οδος", {"supply": "odos_paroxis", "billing": None, "default": "odos_arithmos_katoikias"}),
    (r"^τκπαροχης", {"default": "tk_paroxis"}),
    (r"^τκ|ταχκωδικ", {"supply": "tk_paroxis", "billing": None, "default": "tk_katoikias"}),
    (r"^πολη|^περιοχη|^δημος", {"supply": "poli_paroxis", "billing": None, "default": "poli_katoikias"}),
    (r"^νομος", {"default": "nomos_katoikias"}),
    (r"αριθμοςπαροχης|αρπαροχης", {"default": "arithmos_paroxis"}),
    (r"ηκασπ|γεωπληροφοριακοστιγμα", {"default": "hkasp"}),
    (r"τελευταιαενδειξη|ενδειξημετρητη", {"default": "teleftaia_endeixi_metriti"}),
    (r"αριθμοςμετρητη|αρμετρητη", {"default": "arithmos_metriti"}),
    (r"^τιμολογιο", {"default": "kodikos_timologiou"}),
    (r"^προγραμμα", {"default": "onoma_programmatos"}),
    (r"^διαρκεια", {"default": "diarkeia_symvasis"}),
    (r"αριθμοςαιτησης|αραιτησης|κωδικοςαιτησης|κωδαιτησης", {"default": "arithmos_aitisis"}),
    (r"^ημερομηνια", {"default": "imerominia_aitisis"}),
    (r"^τοπος", {"default": "topos_aitisis"}),
    (r"ονμοσυνεργατη|ονοματεπωνυμοσυνεργατη", {"default": "eponymia_etaireias_mas"}),
    (r"ονμοπωλητη|^πωλητη", {"default": "onomateponymo_politi"}),
    (r"κωδσυνεργατη|κωδικοςσυνεργατη", {"default": "kodikos_synergati"}),
    (r"υφισταμενοςπρομηθευτης|προηγουμενοςπρομηθευτης|τρεχωνπρομηθευτης|προηγπρομηθευτης",
     {"default": "ipistamenos_promitheftis"}),
    (r"εγγυηση", {"default": "poso_eggiisis"}),
    (r"^ισχυς|συμφωνημενηισχυς|^σ1", {"default": "isxis_paroxis"}),
    (r"^καδ", {"default": "kad"}),
    (r"^αργεμη|^γεμη", {"default": "gemi"}),
    (r"νομικημορφη", {"default": "nomiki_morfi"}),
    (r"αντικειμενοεπιχειρησης|^χρηση$", {"default": "antikeimeno_epixeirisis"}),
    (r"ειδικηκατηγορια", {"default": "eidiki_katigoria"}),
    (r"^iban|τραπεζικουλογαριασμου", {"default": "iban"}),
    (r"ανωτατοοριολογαριασμου", {"default": "anotato_orio"}),
    (r"αριθμοςκοινοχρηστου", {"default": "arithmos_koinoxristou"}),
    (r"αριθμοςκινητου", {"contact": "kinito_epikoinonias", "default": "kinito_pelati"}),
]

# Checkbox labels: the engine stamps an X when the matching key is non-empty.
CHECKS = [
    (r"^οικιακη", "katigoria_paroxis_oikiaki"),
    (r"^επαγγελματικη|^εμπορικη", "katigoria_paroxis_epaggelmatiki"),
    (r"^ιδιωτης|^φυσικοπροσωπο", "typos_pelati_idiotis"),
    (r"ατομικηεπιχειρηση", "typos_pelati_atomiki"),
    (r"^εταιρεια$|^νομικοπροσωπο", "typos_pelati_etaireia"),
    (r"αλλαγηπρομηθευτη", "energopoiisi_allagi_paroxou"),
    (r"^διαδοχη", "energopoiisi_diadoxi"),
    (r"επανασυνδεση|επαναηλεκτροδοτηση", "energopoiisi_epanasyndesi"),
    (r"^ανανεωση", "energopoiisi_ananeosi"),
    (r"νεαπαροχη|νεασυνδεση|ενεργοποιησησυνδεσης|αρχικηενεργοποιηση", "energopoiisi_nea_syndesi"),
    (r"^ημερησια&νυχτερινη|ημερησιακαινυχτερινη", "metrisi_imerisia_nyxterini"),
    (r"^ημερησια$", "metrisi_imerisia"),
    (r"^αοριστου", "diarkeia_aoristou"),
    (r"^ιδιοκτητης", "idiotita_idioktitis"),
    (r"^μισθωτης|^ενοικιαστης", "idiotita_misthotis"),
    (r"^εσωτερικος", "thesi_metriti_esoterikos"),
    (r"^εξωτερικος", "thesi_metriti_exoterikos"),
    (r"παγιαεντολη", "pliromi_pagia_entoli"),
    (r"^μηοικιακος|^μηοικιακη", "katigoria_paroxis_epaggelmatiki"),
    (r"^οικιακος", "katigoria_paroxis_oikiaki"),
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


def tick_boxes(page) -> list[tuple[float, float, float, float]]:
    """
    The small ovals and squares a tick goes into, in mm.

    A highlight on a checkbox covers the *label* — "Ημερήσια" — while the box
    itself sits a couple of centimetres to the right. Stamping the X at the
    highlight prints it across the word instead of inside the box.
    """
    found = []
    for drawing in page.get_drawings():
        r = drawing["rect"]
        w, h = (r.x1 - r.x0) * MM, (r.y1 - r.y0) * MM
        if 2 <= w <= 14 and 1.5 <= h <= 7 and w >= h:
            found.append((r.x0 * MM, r.y0 * MM, r.x1 * MM, r.y1 * MM))
    return found


def box_after(boxes, label_end: float, y: float) -> tuple[float, float] | None:
    """Nearest tick box to the right of a label on the same line."""
    same_line = [b for b in boxes
                 if abs((b[1] + b[3]) / 2 - y) < 4 and b[0] >= label_end - 1]
    if not same_line:
        return None

    box = min(same_line, key=lambda b: b[0])
    return ((box[0] + box[2]) / 2, box[1])


def clean_label(text: str) -> str:
    """The provider's own wording, tidied but not reworded."""
    text = re.sub(r"[.…_]{2,}", " ", text)
    text = re.sub(r"\s+", " ", text).strip(" :·")
    return text[:60]


def build(pdf: Path) -> dict:
    fields: dict[str, dict] = {}
    labels: dict[str, str] = {}
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
            boxes = tick_boxes(page)

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
                    # Keep the provider's wording even when the coordinate is
                    # already mapped: the label is what the agent reads.
                    labels.setdefault(key, clean_label(label))

                    if key in fields:
                        continue

                    entry = {"page": index}
                    if is_check:
                        target = box_after(boxes, ends, y0)
                        if target is not None:
                            cx, top = target
                            entry |= {"x": round(cx - 1.0, 1), "y": round(top - 0.7, 1),
                                      "check": True}
                        else:
                            entry |= {"x": round(x0 + 1.0, 1), "y": round(y0, 1), "check": True}
                    elif inline:
                        entry |= {"x": round(ends + GAP_AFTER_LABEL, 1), "y": round(y0, 1)}
                    else:
                        entry |= {"x": round(x0 + 1.0, 1), "y": round(y0 + BOX_DROP, 1)}

                    fields[key] = entry

    return {"page_w": round(page_w), "page_h": round(page_h),
            "fields": fields, "labels": labels, "_unresolved": unresolved}


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

    # The provider's own wording for each field, so the CRM form can ask for
    # "Κ.Α.Δ." on an NRG application and "ΚΑΔ Επιχείρησης" on a Protergia one.
    out["labels"] = {**proposed.get("labels", {}), **existing.get("labels", {})}

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

        # Labels change even when no coordinate does, so compare the whole file
        # rather than trusting the counters.
        if args.write and merged != existing:
            target.write_text(json.dumps(merged, ensure_ascii=False, indent=1),
                              encoding="utf-8")
            print(f"    γράφτηκε {target.name} "
                  f"({len(merged['fields'])} πεδία, {len(merged.get('labels', {}))} ετικέτες)")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
