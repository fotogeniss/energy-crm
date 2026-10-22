#!/usr/bin/env python3
"""
(321) Χάρτες για τα 4 φύλλα φυσικού αερίου Protergia του Οκτωβρίου 2026.

    python tools/protergia-gas-2026-maps.py <φάκελος με τα PDF της Protergia> [--write]

Τα PDF είναι καθαρά (όχι σκαναρισμένα): οι θέσεις διαβάζονται από το ΙΔΙΟ
το PDF, όχι με το μάτι. Κάθε τιμή μπαίνει 1,5 mm μετά το τέλος της
ετικέτας της (ο ίδιος κανόνας με τους χάρτες που ήδη τυπώνουν σωστά) και
σταματά πριν από την επόμενη ετικέτα της γραμμής (`maxw`). Κάθε Χ μπαίνει
στο κέντρο του κουτιού που μετρήθηκε από τα διανυσματικά σχήματα.

Δύο διατάξεις: οικιακή (Flex = Standard) και επαγγελματική (Flex =
Standard). Μόνη διαφορά Flex/Standard: η υπογραφή είναι στη σελ.3 αντί 2.

Χρειάζεται pdfplumber. Με --write γράφει assets/forms/protergia/<key>.json
και τις σελίδες <key>-N.jpg (150 dpi, ίδια ανάλυση με τα υπόλοιπα).
"""

from __future__ import annotations

import json
import subprocess
import sys
from pathlib import Path

import pdfplumber

MM = 25.4 / 72
GAP = 1.5            # mm από το τέλος της ετικέτας ως την τιμή
TOP_TO_Y = -0.5      # y τιμής = top ετικέτας − 0,5 (βασική γραμμή = y + 2,5)
RIGHT = 200.0        # δεξί περιθώριο κειμένου
X_W, X_H = 0.2416, 0.257   # πλάτος/ύψος του «X» της DejaVu ανά pt, σε mm

SOURCES = {
    'protergia_fa_oik_standard':  ('aitisi_oikiako_autonomo_koinoxristo_value-gas-standard.pdf', 'home', 2),
    'protergia_fa_oik_flex':      ('aitisi-oikiako-autonomo-koinoxristo-value-gas-flex.pdf', 'home', 3),
    'protergia_fa_epag_standard': ('aitisi_epaggelmatiko_value-gas-standard.pdf', 'biz', 2),
    'protergia_fa_epag_flex':     ('aitisi_business_value-gas-flex.pdf', 'biz', 3),
}

# (κλειδί, ετικέτα, top ετικέτας σε mm, όριο δεξιά). Το top είναι ΑΝΑΦΟΡΑ:
# το script ψάχνει την ετικέτα στο PDF και αποτυγχάνει αν δεν τη βρει ±1 mm.
TEXT = {
    'home': [
        ('onomateponymo_pelati', 'Ονοματεπώνυμο', 49.7, 150.2),
        ('adt_pelati', 'ΑΔΤ (ή αρ. Διαβ.)', 49.7, RIGHT),
        ('odos_arithmos_katoikias', 'Διεύθυνση', 54.2, 150.2),
        ('tk_katoikias', 'Τ.Κ.', 54.2, 175.8),
        ('poli_katoikias', 'Πόλη', 54.2, RIGHT),
        ('afm_pelati', 'Α.Φ.Μ.', 58.6, 57.2),
        ('doy_pelati', 'Δ.Ο.Υ.', 58.6, 112.0),
        ('epaggelma_pelati', 'Επάγγελμα', 58.6, RIGHT),
        ('tilefono_pelati', 'Τηλ. Οικίας', 63.1, 57.2),
        ('kinito_pelati', 'Κινητό', 63.1, 150.2),
        ('email_pelati', 'E-mail', 63.1, RIGHT),
        ('onomateponymo_epikoinonias', 'Ονοματεπώνυμο', 76.6, RIGHT),
        ('tilefono_epikoinonias', 'Τηλέφωνο', 81.1, 57.2),
        ('kinito_epikoinonias', 'Κινητό', 81.1, 111.2),
        ('email_epikoinonias', 'E-mail', 81.1, RIGHT),
        ('odos_arithmos_apostolis', 'Διεύθυνση', 90.5, 70.4),
        ('tk_apostolis', 'Τ.Κ.', 90.5, 111.2),
        ('poli_apostolis', 'Πόλη', 90.5, RIGHT),
        ('odos_arithmos_paroxis', 'Διεύθυνση ακινήτου', 124.6, 70.3),
        ('tk_paroxis', 'Τ.Κ.', 124.6, 111.1),
        ('poli_paroxis', 'Πόλη', 124.6, RIGHT),
        ('hkasp', 'ΗΚΑΣΠ', 133.3, 111.1),
        ('fa_typos_metriti', 'Τύπος μετρητή', 133.3, RIGHT),
        ('ipistamenos_promitheftis', 'Υφιστάμενος προμηθευτής', 137.6, 111.1),
        ('teleftaia_endeixi_metriti', 'Τελευταία ένδειξη μετρητή (m3)', 137.6, RIGHT),
        ('fa_arithmos_paroxis_revmatos', 'Αριθμός παροχής', 150.2, RIGHT),
        ('fa_diarkeia', 'Διάρκεια ισχύος τιμολογίου*', 170.2, 70.3),
        ('fa_diarkeia', 'Διάρκεια σύμβασης*', 170.2, RIGHT),
        ('poso_eggiisis', 'Ποσό εγγύησης (€)', 174.2, 70.3),
        ('fa_paratiriseis', 'Παρατηρήσεις', 178.2, RIGHT),
    ],
    'biz': [
        ('onomateponymo_pelati', 'Ονοματεπώνυμο / επωνυμία επιχείρησης', 54.1, RIGHT),
        ('epaggelma_pelati', 'Επάγγελμα', 58.6, 100.5),
        ('kad', 'Κ.Α.Δ.', 58.6, 150.1),
        ('gemi', 'Αρ. Γ.Ε.Μ.Η.', 58.6, RIGHT),
        ('odos_arithmos_katoikias', 'Διεύθυνση', 63.0, 100.5),
        ('tk_katoikias', 'Τ.Κ.', 63.0, 150.1),
        ('poli_katoikias', 'Πόλη', 63.0, RIGHT),
        ('afm_pelati', 'Α.Φ.Μ.', 67.5, 100.5),
        ('doy_pelati', 'Δ.Ο.Υ.', 67.5, RIGHT),
        ('tilefono_pelati', 'Τηλέφωνο', 72.0, 100.5),
        ('email_pelati', 'E-mail', 72.0, RIGHT),
        ('onomateponymo_ekprosopou', 'Ονοματεπώνυμο', 85.7, 100.6),
        ('adt_ekprosopou', 'Α.Δ.Τ. / Αρ. διαβατηρίου', 85.7, RIGHT),
        ('onomateponymo_epikoinonias', 'Ονοματεπώνυμο', 95.0, 100.5),
        ('tilefono_epikoinonias', 'Τηλέφωνο', 99.5, 100.5),
        ('kinito_epikoinonias', 'Κινητό', 99.5, 150.9),
        ('email_epikoinonias', 'E-mail', 99.5, RIGHT),
        ('odos_arithmos_apostolis', 'Διεύθυνση', 108.8, 101.1),
        ('tk_apostolis', 'Τ.Κ.', 108.8, 151.0),
        ('poli_apostolis', 'Πόλη', 108.8, RIGHT),
        ('odos_arithmos_paroxis', 'Διεύθυνση ακινήτου', 144.6, 100.7),
        ('tk_paroxis', 'Τ.Κ.', 144.6, 150.4),
        ('poli_paroxis', 'Πόλη', 144.6, RIGHT),
        ('hkasp', 'ΗΚΑΣΠ', 153.4, 100.7),
        ('fa_typos_metriti', 'Τύπος μετρητή', 153.4, RIGHT),
        ('ipistamenos_promitheftis', 'Υφιστάμενος προμηθευτής', 157.8, 100.7),
        ('teleftaia_endeixi_metriti', 'Τελευταία ένδειξη μετρητή (m3)', 157.8, RIGHT),
        ('fa_arithmos_paroxis_revmatos', 'Αριθμός παροχής', 171.3, RIGHT),
        ('fa_diarkeia', 'Διάρκεια ισχύος τιμολογίου*', 194.0, 70.3),
        ('fa_diarkeia', 'Διάρκεια σύμβασης*', 194.0, RIGHT),
        ('poso_eggiisis', 'Ποσό εγγύησης (€)', 198.2, 70.3),
        ('fa_paratiriseis', 'Παρατηρήσεις', 202.4, RIGHT),
    ],
}

# (κλειδί, x0, x1, top, bottom) — κουτιά μετρημένα από τα σχήματα του PDF.
# «Μεγάλα» οβάλ (~7,5×3,5 mm) παίρνουν Χ 10pt· τα μικρά των όρων
# (~3,7×1,7 mm) Χ 6,5pt, που χωράει ολόκληρο μέσα τους.
BOXES = {
    'home': [
        ('idiotita_idioktitis', 45.0, 52.5, 66.8, 70.3),
        ('idiotita_misthotis', 94.3, 101.8, 66.8, 70.3),
        ('pliromi_pagia_entoli', 138.5, 146.0, 95.3, 98.8),
        ('energopoiisi_nea_syndesi', 52.3, 59.8, 141.1, 144.6),
        ('energopoiisi_epanasyndesi', 152.1, 159.6, 141.1, 144.6),
        ('fa_aftonomo', 30.9, 38.4, 162.5, 165.9),
        ('fa_koinoxristo', 81.4, 88.9, 162.5, 165.9),
        ('fa_eggiisi_1os_logariasmos', 128.5, 136.0, 173.4, 176.8),
        # όροι (σελ.1, κάτω μισό)
        ('synainesi_omilou_nai', 89.8, 93.5, 222.7, 224.4),     # Ε΄ ΝΑΙ
        ('synainesi_omilou_oxi', 97.9, 101.5, 222.7, 224.4),    # Ε΄ ΟΧΙ
        ('synainesi_erevnas_nai', 174.8, 178.5, 226.7, 228.3),  # ΣΤ΄ ΝΑΙ
        ('synainesi_erevnas_oxi', 187.9, 191.5, 226.7, 228.3),  # ΣΤ΄ ΟΧΙ
        ('fa_oros_panta', 198.5, 202.2, 228.6, 230.2),          # Ζ΄ υπαναχώρηση
        ('fa_logariasmos_email', 174.5, 178.1, 234.5, 236.1),   # Ι΄ email
        ('fa_oros_panta', 177.3, 181.0, 240.4, 242.0),          # ΙΑ΄ ιδιοκτήτης/ΗΚΑΣΠ
    ],
    'biz': [
        ('idiotita_idioktitis', 46.4, 53.9, 75.7, 79.2),
        ('idiotita_misthotis', 138.6, 146.1, 75.7, 79.2),
        ('pliromi_pagia_entoli', 139.5, 147.0, 113.5, 116.9),
        ('energopoiisi_nea_syndesi', 53.4, 60.9, 161.4, 164.8),
        ('energopoiisi_epanasyndesi', 141.9, 149.4, 161.4, 164.8),
        ('fa_emporiko', 26.4, 33.9, 184.4, 187.9),
        ('fa_eggiisi_1os_logariasmos', 128.9, 136.4, 197.5, 200.9),
        ('synainesi_omilou_nai', 89.8, 93.5, 247.1, 248.8),     # Ε΄ ΝΑΙ
        ('synainesi_omilou_oxi', 97.9, 101.5, 247.1, 248.8),    # Ε΄ ΟΧΙ
        ('synainesi_erevnas_nai', 174.8, 178.5, 251.7, 253.4),  # ΣΤ΄ ΝΑΙ
        ('synainesi_erevnas_oxi', 187.9, 191.5, 251.7, 253.4),  # ΣΤ΄ ΟΧΙ
        ('fa_logariasmos_email', 174.5, 178.1, 258.5, 260.2),   # Θ΄ email
        ('fa_oros_panta', 176.9, 180.5, 265.4, 267.1),          # Ι΄ ιδιοκτήτης/ΗΚΑΣΠ
        ('fa_oros_panta', 129.2, 132.9, 267.7, 269.4),          # ΙΑ΄ προσωπικά δεδομένα
    ],
}

# Υπογραφή πελάτη: η γραμμή πάνω από το «Υπογραφή / Σφραγίδα Πελάτη».
SIG = {'home': (8.0, 58.4, 315.1, 316.6), 'biz': (9.1, 59.5, 316.9, 318.4)}


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


def find(ls, label, top):
    hits = [l for l in ls if l['text'] == label and abs(l['top'] - top) <= 1.0]
    if len(hits) != 1:
        raise SystemExit(f'Η ετικέτα «{label}» στο {top} βρέθηκε {len(hits)} φορές')
    return hits[0]


def check(x0, x1, top, bottom, page=1):
    small = (bottom - top) < 2.5
    size = 6.5 if small else 10.0
    w, h = X_W * size, X_H * size
    x = (x0 + x1) / 2 - w / 2
    base = bottom - 0.1 if small else (top + bottom) / 2 + h / 2
    pos = {'page': page, 'x': round(x, 1), 'y': round(base - 2.5, 1), 'check': True}
    if small:
        pos['size'] = size
    return pos


def build(pdf_path: Path, layout: str, sig_page: int) -> dict:
    pdf = pdfplumber.open(str(pdf_path))
    ls = lines(pdf.pages[0])
    fields: dict = {}
    labels: dict = {}
    for key, label, top, limit in TEXT[layout]:
        l = find(ls, label, top)
        x = l['x1'] + GAP
        pos = {'page': 1, 'x': round(x, 1), 'y': round(l['top'] + TOP_TO_Y, 1), 'maxw': round(limit - 1.0 - x, 1)}
        if key == 'fa_paratiriseis':
            pos['size'] = 8.0
        fields.setdefault(key, []).append(pos)
        labels.setdefault(key, label)
    for key, x0, x1, top, bottom in BOXES[layout]:
        fields.setdefault(key, []).append(check(x0, x1, top, bottom))
    # μία θέση → αντικείμενο, πολλές → λίστα (όπως οι υπόλοιποι χάρτες)
    fields = {k: (v[0] if len(v) == 1 else v) for k, v in fields.items()}

    lx0, lx1, line_y, label_top = SIG[layout]
    # (321) Εταιρεία: «ΟΝΟΜΑΤΕΠΩΝΥΜΟ - ΝΟΜΙΜΟΣ ΕΚΠΡΟΣΩΠΟΣ» κάτω από την υπογραφή.
    fields['nomimos_ekprosopos_ypografi'] = {
        'page': sig_page, 'x': round(lx0, 1), 'y': round(label_top + 2.6, 1), 'size': 7.0, 'maxw': 100.0,
    }
    page_h = pdf.pages[0].height * MM
    return {
        'page_w': round(pdf.pages[0].width * MM, 1),
        'page_h': round(page_h, 1),
        'fields': fields,
        'sigs': [{'page': sig_page, 'x': round(lx0 + 2.0, 1), 'y': round(line_y - 14.3, 1), 'w': 46, 'h': 0, 'fit_h': 14.0}],
        'labels': labels,
    }


def main() -> None:
    src = Path(sys.argv[1])
    write = '--write' in sys.argv
    out = Path(__file__).resolve().parent.parent / 'assets' / 'forms' / 'protergia'
    for key, (name, layout, sig_page) in SOURCES.items():
        m = build(src / name, layout, sig_page)
        if not write:
            print(key, json.dumps(m, ensure_ascii=False)[:300], '…')
            continue
        (out / f'{key}.json').write_text(json.dumps(m, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')
        subprocess.run(['pdftoppm', '-r', '150', '-jpeg', '-jpegopt', 'quality=85', str(src / name), str(out / key)], check=True)
        # pdftoppm γράφει key-1.jpg / key-01.jpg ανάλογα με το πλήθος σελίδων
        for p in sorted(out.glob(f'{key}-0*.jpg')):
            p.rename(out / f'{key}-{int(p.stem.rsplit("-", 1)[1])}.jpg')
        print('ok', key)


if __name__ == '__main__':
    main()
