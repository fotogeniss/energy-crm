#!/usr/bin/env python3
"""
Audit the provider form maps against the highlighted source PDFs.

The provider sends an application form with the fields we are expected to fill
marked in yellow. Those marks are real PDF Highlight annotations, so their
positions can be read exactly instead of estimated from a screenshot.

This compares each highlight against assets/forms/{key}.json and reports:

  * highlights with no field placed inside them  — data we never print
  * fields whose anchor falls outside every highlight — usually fine (the
    provider does not mark auto-filled boxes such as the application number),
    but worth eyeballing

With --draft it also prints ready-to-paste JSON entries for the uncovered
highlights, named after the nearest label text. The names still need a human
pass: only you know that "Ον/μο πωλητή" maps to `politis`.

Usage:
    pip install pymupdf
    python tools/form-map-audit.py --sources ~/provider-forms
    python tools/form-map-audit.py --sources ~/provider-forms --only protergia_he --draft

The source PDFs are not committed: they are provider documents, they are large,
and they are reissued periodically. Keep them wherever you like and point
--sources at that directory.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

try:
    import fitz  # PyMuPDF
except ImportError:
    sys.exit("Χρειάζεται το PyMuPDF:  pip install pymupdf")

MM = 25.4 / 72  # PDF points to millimetres

# Anchors may sit slightly outside their highlight: text is drawn at y + 3mm
# (ECRM_FormFill::BASELINE), and box fields are written below their label.
X_SLACK = 2.0
Y_SLACK = 8.0


def highlights(pdf: Path) -> list[tuple[int, float, float, float, float]]:
    """Every highlight in the document, as (page, x0, y0, x1, y1) in mm."""
    found = []
    with fitz.open(pdf) as doc:
        for index, page in enumerate(doc, start=1):
            for annot in page.annots() or []:
                if annot.type[1] != "Highlight":
                    continue
                r = annot.rect
                found.append((index, r.x0 * MM, r.y0 * MM, r.x1 * MM, r.y1 * MM))
    return found


def label_near(pdf: Path, page_no: int, x0: float, y0: float) -> str:
    """Best-guess label for a highlight: text to its left, else above it."""
    with fitz.open(pdf) as doc:
        words = doc[page_no - 1].get_text("words")

    left = [w for w in words if w[2] * MM <= x0 + 1 and abs(w[1] * MM - y0) < 2]
    if left:
        left.sort(key=lambda w: w[2])
        return " ".join(w[4] for w in left[-4:])

    above = [w for w in words if 0 < y0 - w[3] * MM < 5 and x0 - 3 <= w[0] * MM <= x0 + 40]
    above.sort(key=lambda w: (-w[3], w[0]))
    return " ".join(w[4] for w in above[:4])


def covered(field: dict, page_no: int, box: tuple[float, float, float, float]) -> bool:
    x0, y0, x1, y1 = box
    return (
        int(field.get("page", 1)) == page_no
        and x0 - X_SLACK <= float(field["x"]) <= x1 + X_SLACK
        and y0 - Y_SLACK <= float(field["y"]) <= y1 + Y_SLACK
    )


def audit(key: str, pdf: Path, forms_dir: Path, draft: bool) -> int:
    fields = json.loads((forms_dir / f"{key}.json").read_text(encoding="utf-8"))["fields"]
    marks = highlights(pdf)

    uncovered = [
        m for m in marks
        if not any(covered(f, m[0], m[1:]) for f in fields.values())
    ]
    stray = [
        name for name, f in fields.items()
        if not any(covered(f, m[0], m[1:]) for m in marks)
    ]

    print(f"\n=== {key}")
    print(f"    κίτρινα: {len(marks)}   χαρτογραφημένα: {len(fields)}   "
          f"ακάλυπτα: {len(uncovered)}   εκτός σήμανσης: {len(stray)}")

    if stray:
        print(f"    εκτός σήμανσης: {', '.join(sorted(stray))}")

    for page_no, x0, y0, x1, y1 in sorted(uncovered):
        label = label_near(pdf, page_no, x0, y0)
        print(f"    σελ {page_no}  x={x0:6.1f}  y={y0:6.1f}  πλάτος={x1 - x0:5.1f}   « {label[:50]} »")

    if draft and uncovered:
        print("\n    -- πρόχειρες εγγραφές (χρειάζονται ονόματα) --")
        for i, (page_no, x0, y0, x1, y1) in enumerate(sorted(uncovered), start=1):
            print(f'    "ΟΝΟΜΑ_{i}": {{ "page": {page_no}, "x": {x0 + 0.5:.1f}, "y": {y0:.1f} }},')

    return len(uncovered)


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--sources", required=True, type=Path,
                        help="Φάκελος με τα σημειωμένα PDF των παρόχων")
    parser.add_argument("--forms", type=Path,
                        default=Path(__file__).resolve().parent.parent / "assets" / "forms")
    parser.add_argument("--only", help="Έλεγχος ενός μόνο προτύπου")
    parser.add_argument("--draft", action="store_true",
                        help="Τύπωσε πρόχειρες εγγραφές JSON για τα ακάλυπτα")
    args = parser.parse_args()

    # A source PDF is matched to a template by putting the template key in its
    # filename — renaming the provider's file once is simpler than maintaining
    # a mapping of their naming habits.
    keys = sorted(p.stem for p in args.forms.glob("*.json"))
    total = 0

    for key in keys:
        if args.only and key != args.only:
            continue

        matches = [p for p in args.sources.glob("*.pdf") if key in p.stem]

        if not matches:
            print(f"\n=== {key}\n    (δεν βρέθηκε PDF με «{key}» στο όνομα)")
            continue

        total += audit(key, matches[0], args.forms, args.draft)

    print(f"\nΣΥΝΟΛΟ ακάλυπτων σημάνσεων: {total}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
