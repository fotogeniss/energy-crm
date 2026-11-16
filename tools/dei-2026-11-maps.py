#!/usr/bin/env python3
"""
(405) Χάρτες και σελίδες για τα νέα έντυπα ΔΕΗ που έστειλε ο ιδιοκτήτης (16/11/2026).

    python tools/dei-2026-11-maps.py <φάκελος με τα PDF> [--write]

Φτιάχνει:

- dei_fa_aitisi: η αίτηση φυσικού αερίου (σελ. 1 του «MY HOME GAS CONTROL.pdf»,
  ίδια στο «MY BULIDING GAS CONTROL.pdf»).
- dei_eo_myhome_gascontrol / dei_eo_mybuilding_gascontrol: οι Ειδικοί Όροι
  με τον τιμοκατάλογο (σελ. 2 του καθενός).
- dei_eo_g21: οι Ειδικοί Όροι του Γ21 Επαγγελματικό (σελ. 3-4 του «Γ21.pdf»).

Η αίτηση αερίου είναι καθαρό PDF: κάθε τιμή μπαίνει 1,5 mm μετά το τέλος της
ετικέτας της (ίδιος κανόνας με τους υπόλοιπους χάρτες) και το script
αποτυγχάνει αν δεν βρει την ετικέτα στη θέση αναφοράς (±1 mm). Οι γραμμές
χωρίς κείμενο στο PDF (σχεδιασμένα γράμματα) και τα κουτάκια μετρήθηκαν
σε σάρωση 300 dpi και γράφονται ως σταθερές.

Υπογραφές με τον κανόνα των Ειδικών Όρων ρεύματος (380): η υπογραφή 5,4 mm
κάτω από την κορυφή της ετικέτας «ΠΕΛΑΤΗΣ (ΥΠΟΓΡΑΦΗ)», το όνομα 12 mm πιο κάτω.

Σελίδες: 150 dpi, A4 (1241×1754), όπως οι υπόλοιπες της ΔΕΗ.
"""

from __future__ import annotations

import json
import subprocess
import sys
import tempfile
from pathlib import Path

import pdfplumber

MM = 25.4 / 72
GAP = 1.5
TOP_TO_Y = -0.5
X_W, X_H = 0.2416, 0.257

GAS_APP = 'MY HOME GAS CONTROL.pdf'
GAS_EO = {
    'dei_eo_myhome_gascontrol': 'MY HOME GAS CONTROL.pdf',
    'dei_eo_mybuilding_gascontrol': 'MY BULIDING GAS CONTROL.pdf',
}
G21 = 'Γ21.pdf'

# (κλειδί, ετικέτα (πρώτη λέξη ή ολόκληρη), top αναφοράς mm, x αρχής ετικέτας, δεξί όριο, when)
TEXT = [
    ('onoma_timologiou', 'ΤΙΜΟΛΟΓΙΟ:', 29.2, 106.1, 199.0, None),
    ('eidiki_katigoria', 'ΚΑΤΗΓΟΡΙΑ ΠΕΛΑΤΗ:', 40.2, 115.7, 199.0, None),
    ('onomateponymo_pelati', 'Ονοµατεπώνυµο:', 60.2, 11.3, 100.5, 'not_company'),
    ('afm_pelati', 'Α.Φ.Μ.', 60.3, 105.9, 148.0, 'not_company'),
    ('doy_pelati', '∆.Ο.Υ.', 60.3, 149.2, 200.5, 'not_company'),
    ('adt_pelati', 'Α.∆.Τ./∆ιαβατ.', 65.8, 11.3, 62.0, 'not_company'),
    ('poli_katoikias', 'Kάτοικο', 65.6, 62.9, 100.8, 'not_company'),
    ('odos_arithmos_katoikias', 'οδός/αριθµός', 65.5, 102.0, 200.5, 'not_company'),
    ('eponymia_etaireias', 'Επωνυμία:', 78.7, 11.3, 83.5, 'company'),
    ('dieuthynsi_katoikias', 'εδρεύει', 78.4, 96.2, 200.3, 'company'),
    ('afm_pelati', 'Α.Φ.Μ.', 84.5, 15.2, 52.3, 'company'),
    ('doy_pelati', 'ΔΟΥ', 84.5, 53.2, 115.2, 'company'),
    ('onomateponymo_ekprosopou', 'κ.κ.', 90.3, 45.6, 136.0, 'company'),
    # Στοιχεία Παροχής
    ('hkasp', 'ΗΚΑΣΠ', 137.4, 14.1, 96.0, None),
    ('teleftaia_endeixi_metriti', '(m³):', 144.7, 38.8, 96.0, None),
    ('fa_typos_metriti', 'ΜΕΤΡΗΤΗ', 151.0, 24.6, 96.0, None),
    ('eggiisi_olografos', '(ολογράφως)', 165.1, 38.6, 199.0, None),
    ('odos_paroxis', 'Ο∆ΟΣ', 137.4, 99.3, 199.0, None),
    ('arithmos_odou_paroxis', 'ΑΡΙΘΜΟΣ', 144.7, 99.4, 140.5, None),
    ('tk_paroxis', 'ΤΚ', 144.1, 143.0, 199.0, None),
    ('poli_paroxis', 'ΠΕΡΙΟΧΗ/ΠΟΛΗ', 151.0, 99.3, 199.0, None),
]

# Γραμμές χωρίς κείμενο στο PDF: (κλειδί, x, y, maxw), σε mm, από σάρωση 300 dpi.
FIXED = [
    # «ΑΡΙΘΜΟΣ ΜΕΤΡΗΤΗ (S/N):» τελειώνει στα 49,5 mm, κορυφή 171,1 (μονάδες pdfplumber).
    ('arithmos_metriti', 51.0, 170.6, 45.0),
    # Στοιχεία Επικοινωνίας, στήλη ΠΕΛΑΤΗ (124,6–200,2 mm).
    ('odos_arithmos_apostolis', 126.0, 193.6, 73.0),
    ('tk_apostolis', 126.0, 200.9, 15.0),
    ('poli_apostolis', 142.0, 200.9, 57.0),
    ('tilefono_pelati', 126.0, 207.4, 73.0),
    ('kinito_pelati', 126.0, 213.6, 73.0),
    ('email_pelati', 126.0, 220.7, 73.0),
]

# Κουτάκια (κλειδί, x0, x1, top, bottom), σάρωση 300 dpi.
BOXES = [
    ('idiotita_idioktitis', 10.9, 14.9, 96.4, 100.2),
    ('idiotita_misthotis', 33.1, 37.1, 96.4, 100.2),
    ('energopoiisi_allagi_paroxou', 125.0, 128.8, 171.1, 174.9),   # ΕΝΕΡΓΗ ΠΑΡΟΧΗ
    ('energopoiisi_nea_syndesi', 186.5, 190.3, 171.1, 174.9),      # ΠΡΩΤΗ ΕΝΕΡΓΟΠΟΙΗΣΗ
]

# Υπογραφή της αίτησης: γκρι πλαίσιο κάτω από «ΠΕΛΑΤΗΣ / ΕΞΟΥΣΙΟΔΟΤΟΥΜΕΝΟΣ
# (ονοματεπώνυμο και υπογραφή)» (260,5–265,9 mm).
APP_SIG = {'page': 1, 'x': 140.0, 'y': 267.5, 'w': 50, 'h': 0, 'fit_h': 13.0}
APP_NAME_Y = 282.5

# Γ21, σελ. 2: «Πελάτης/ Εξουσιοδοτούμενος» 206,6–209,3, «(Υπογραφή)» 219,5–222,2
# (x 151,9–191,9): η υπογραφή στο κενό ανάμεσα, το όνομα κάτω από το «(Υπογραφή)».
G21_SIG = {'page': 2, 'x': 152.0, 'y': 209.8, 'w': 40, 'h': 0, 'fit_h': 9.4}
G21_NAME = {'x': 152.0, 'y': 223.6}


def lines(page):
    rows = {}
    for c in page.chars:
        rows.setdefault(round(c['top'] * MM * 2) / 2, []).append(c)
    out = []
    for top in sorted(rows):
        cs = sorted(rows[top], key=lambda c: c['x0'])
        seg = [cs[0]]
        for a, b in zip(cs, cs[1:]):
            if (b['x0'] - a['x1']) * MM > 2.2:
                out.append(seg)
                seg = []
            seg.append(b)
        out.append(seg)
    return [{'text': ''.join(c['text'] for c in s).strip(), 'x0': s[0]['x0'] * MM,
             'x1': s[-1]['x1'] * MM, 'top': min(c['top'] for c in s) * MM} for s in out]


def words(page):
    return [{'text': w['text'], 'x0': w['x0'] * MM, 'x1': w['x1'] * MM, 'top': w['top'] * MM}
            for w in page.extract_words()]


def find_label(page, label, top, x0):
    """Η ετικέτα ως συνεχόμενες λέξεις που ξεκινούν στο x0 (±1) και top (±1)."""
    ws = sorted((w for w in words(page) if abs(w['top'] - top) <= 1.0), key=lambda w: w['x0'])
    parts = label.split(' ')
    for i, w in enumerate(ws):
        if abs(w['x0'] - x0) > 1.0 or w['text'] != parts[0]:
            continue
        seq = ws[i:i + len(parts)]
        if [s['text'] for s in seq] == parts:
            return seq[-1]['x1'], seq[0]['top']
    raise SystemExit(f'Η ετικέτα «{label}» δεν βρέθηκε στο top {top}, x {x0}')


def check(x0, x1, top, bottom, page=1):
    size = 10.0
    w, h = X_W * size, X_H * size
    x = (x0 + x1) / 2 - w / 2
    base = (top + bottom) / 2 + h / 2
    return {'page': page, 'x': round(x, 1), 'y': round(base - 2.5, 1), 'check': True}


def gas_app(pdf_path: Path) -> dict:
    with pdfplumber.open(str(pdf_path)) as pdf:
        page = pdf.pages[0]
        fields: dict = {}
        for key, label, top, x0, right, when in TEXT:
            x1, ltop = find_label(page, label, top, x0)
            x = x1 + GAP
            pos = {'page': 1, 'x': round(x, 1), 'y': round(ltop + TOP_TO_Y, 1), 'maxw': round(right - x, 1)}
            if when:
                pos['when'] = when
            fields.setdefault(key, []).append(pos)
        for key, x, y, maxw in FIXED:
            fields.setdefault(key, []).append({'page': 1, 'x': x, 'y': y, 'maxw': maxw})
        for key, x0, x1, top, bottom in BOXES:
            fields.setdefault(key, []).append(check(x0, x1, top, bottom))
        fields.setdefault('onomateponymo_pelati', []).append(
            {'page': 1, 'x': APP_SIG['x'], 'y': APP_NAME_Y, 'maxw': 58, 'size': 8, 'when': 'not_company'})
        fields.setdefault('onomateponymo_ekprosopou', []).append(
            {'page': 1, 'x': APP_SIG['x'], 'y': APP_NAME_Y, 'maxw': 58, 'size': 8, 'when': 'company'})
        fields = {k: (v[0] if len(v) == 1 else v) for k, v in fields.items()}
        # A4: 210,1×297,1 στο PDF από στρογγύλευση· οι υπόλοιποι χάρτες γράφουν 210×297.
        return {'page_w': 210.0, 'page_h': 297.0, 'fields': fields, 'sigs': [APP_SIG]}


def gas_eo(pdf_path: Path) -> dict:
    with pdfplumber.open(str(pdf_path)) as pdf:
        page = pdf.pages[1]
        hits = [w for w in words(page) if w['text'] == 'ΠΕΛΑΤΗΣ']
        if len(hits) != 1:
            raise SystemExit(f'{pdf_path.name}: «ΠΕΛΑΤΗΣ (ΥΠΟΓΡΑΦΗ)» {len(hits)} φορές')
        # pdfplumber: top 0,8 mm πάνω από το μελάνι· ο κανόνας (380) μετρά από το μελάνι.
        ink_top = hits[0]['top'] + 0.8
        x = round(hits[0]['x0'] - 4.1, 1)
        sig_y = round(ink_top + 5.4, 1)
        name_y = round(ink_top + 17.4, 1)
        return {
            'page_w': round(page.width * MM, 1), 'page_h': round(page.height * MM, 1),
            'fields': {
                'onomateponymo_pelati': {'page': 1, 'x': x, 'y': name_y, 'maxw': 44, 'size': 7.5,
                                         'when': 'not_company'},
                'onomateponymo_ekprosopou': {'page': 1, 'x': x, 'y': name_y, 'maxw': 44, 'size': 7.5,
                                             'when': 'company'},
            },
            'sigs': [{'page': 1, 'x': x, 'y': sig_y, 'w': 44, 'h': 0, 'fit_h': 11.0}],
        }


def g21_eo() -> dict:
    return {
        'page_w': 210.0, 'page_h': 297.0,
        'fields': {
            'onomateponymo_pelati': {'page': 2, **G21_NAME, 'maxw': 44, 'size': 7.5, 'when': 'not_company'},
            'onomateponymo_ekprosopou': {'page': 2, **G21_NAME, 'maxw': 44, 'size': 7.5, 'when': 'company'},
        },
        'sigs': [G21_SIG],
    }


def render(pdf_path: Path, first: int, last: int, out_dir: Path, key: str) -> None:
    from PIL import Image
    with tempfile.TemporaryDirectory() as tmp:
        subprocess.run(['pdftoppm', '-q', '-r', '150', '-f', str(first), '-l', str(last), '-png',
                        str(pdf_path), str(Path(tmp) / 'p')], check=True)
        pngs = sorted(Path(tmp).glob('p-*.png'), key=lambda p: int(p.stem.split('-')[-1]))
        for n, png in enumerate(pngs, 1):
            im = Image.open(png).convert('RGB')
            if im.size != (1241, 1754):
                im = im.resize((1241, 1754), Image.LANCZOS)
            im.save(out_dir / f'{key}-{n}.jpg', 'JPEG', quality=80, optimize=True)


def main() -> None:
    if len(sys.argv) < 2:
        raise SystemExit(__doc__)
    src = Path(sys.argv[1])
    write = '--write' in sys.argv
    out = Path(__file__).resolve().parent.parent / 'assets' / 'forms' / 'dei'
    maps = {
        'dei_fa_aitisi': (gas_app(src / GAS_APP), src / GAS_APP, 1, 1),
        'dei_eo_g21': (g21_eo(), src / G21, 3, 4),
    }
    for key, name in GAS_EO.items():
        maps[key] = (gas_eo(src / name), src / name, 2, 2)
    for key, (m, pdf, first, last) in maps.items():
        print(key, json.dumps(m, ensure_ascii=False)[:160], '…')
        if write:
            (out / f'{key}.json').write_text(json.dumps(m, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')
            render(pdf, first, last, out, key)


if __name__ == '__main__':
    main()
