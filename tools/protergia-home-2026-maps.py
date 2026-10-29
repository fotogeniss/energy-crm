#!/usr/bin/env python3
"""
(325) Χάρτες για τα οικιακά φύλλα ρεύματος Protergia (αποστολή 29/10/2026).

    python tools/protergia-home-2026-maps.py <φάκελος με τα PDF της Protergia> [--write [--maps-only]]

## Από πού έρχονται οι θέσεις

Όλες οι οικιακές αιτήσεις (Value Sure 4.0 / Balance / Standard 2.0 /
Special, Student Plan, Dynamic One, Picasso 2.0) είναι το ΙΔΙΟ έντυπο της
Protergia με άλλον πίνακα τιμών -- το ίδιο με το Value Lite 2.0
(`protergia_oik_lite2`, ελεγμένο σε εκτύπωση από το (306)/(319)):

- σελ. 1 και η σελίδα «Αποδοχή όρων»: κάθε ετικέτα του lite2 κάθεται στο
  ίδιο σημείο σε όλα τα νέα PDF (μετρημένο: η τιμή του lite2 βρίσκεται
  1,3–2,0 mm μετά την ετικέτα της, όπως ο κανόνας με τον οποίο φτιάχτηκε).
  Το script μεταφέρει τις θέσεις όσο μετακινήθηκαν οι γύρω ετικέτες σε
  σχέση με το Value Balance 12M και σταματά αν κάποια ξεπεράσει 0,3 mm·
  κάθε Χ ελέγχεται ότι πέφτει μέσα σε κουτί του PDF.
- σελ. 2: ημερομηνία, «Ηλεκτρονικά» και εγγύηση ακολουθούν τη γραμμή τους
  (ο πίνακας έχει άλλο ύψος ανά πρόγραμμα).
- Dynamic One και Picasso έχουν μία σελίδα παραπάνω πριν από τους όρους.

Θέσεις κειμένου χωρίς `maxw` παίρνουν όριο ως την επόμενη ετικέτα ή
κάθετη γραμμή (ο κανόνας του (323b)).

## Υπογραφές

- Οι δύο της σελίδας όρων: όπως στο lite2.
- Κάθε «ΥΠΟΓΡΑΦΗ … ΠΕΛΑΤΗ» με γραμμή από πάνω σε άλλη σελίδα της αίτησης
  (Picasso: δύο, Dynamic One: μία) και στους ΓΟΣ (Picasso: δύο).

## ΓΟΣ

Τυπώνονται μετά την αίτηση (`"tail"`), μία φορά στον δίσκο ανά πρόγραμμα.

Χρειάζεται pdfplumber, Pillow και pdftoppm (poppler), και το
`protergia-biz-2026-maps.py` δίπλα του (κοινές συναρτήσεις).
"""

from __future__ import annotations

import importlib.util
import json
import math
import statistics
import sys
from pathlib import Path

import pdfplumber

HERE = Path(__file__).resolve().parent
_spec = importlib.util.spec_from_file_location('protergia_biz_maps', HERE / 'protergia-biz-2026-maps.py')
biz = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(biz)

MM = biz.MM
OUT = biz.OUT
REF_KEY = 'protergia_oik_lite2'
# Το lite2 δεν έχει PDF στα χέρια μας· οι ετικέτες του είναι στις ίδιες θέσεις
# με του Value Balance 12M (βλ. πάνω), που κάνει τον «χάρακα».
REF_WORDS_PDF = 'aitisi_oikiako_value-balance-12m.pdf'
# Η κορυφή της ετικέτας κάθε γραμμής της σελ. 2 στο lite2 (= Value Standard 2.0).
REF_P2_TOP = {'imerominia_aitisis': 15.0, 'ypovoli_ilektronika': 22.1, 'poso_eggiisis': 102.5}

PICASSO_GOS = ('gos_oikiako_picasso-2-0.pdf', 'protergia_gos_oik_picasso')
SHEETS: dict[str, tuple[str, str, str | None]] = {
    'protergia_oik_sure4_12m':    ('aitisi_home_value-sure-12m-4_0.pdf', 'gos_home_value-sure-12m-4_0pdf.pdf', None),
    'protergia_oik_balance_12m':  ('aitisi_oikiako_value-balance-12m.pdf', 'gos_oikiako-value-balance-12m.pdf', None),
    'protergia_oik_standard2':    ('aitisi_oikiako_value-standard-2_0.pdf', 'gos_oikiako_value-standard-2_0.pdf', None),
    'protergia_oik_special':      ('aitisi_oikiako_value_special.pdf', 'gos_oikiako_value_special.pdf', None),
    'protergia_oik_student_plan': ('eo_1250-2_290_v1_082026_aitisi_student_plan.pdf',
                                   'eo_1250-2_246_v1_082026_gos_oikiako_student_plan.pdf', None),
    'protergia_oik_dynamic_one':  ('aitisi_oikiako_dynamic_one.pdf', 'gos_oikiako_dynamic_one.pdf', None),
    'protergia_oik_picasso_s1':   ('aitisi_oikiako_picasso_small_39-90.pdf', *PICASSO_GOS),
}

X_W, X_H = 0.2416, 0.257


def boxes(pg) -> list[tuple[float, float, float, float]]:
    return [(o['x0'] * MM, o['x1'] * MM, o['top'] * MM, o['bottom'] * MM)
            for o in pg.curves + pg.rects
            if 2 < (o['x1'] - o['x0']) * MM < 12 and 1.2 < (o['bottom'] - o['top']) * MM < 6]


def in_box(pos: dict, bx: list) -> bool:
    size = float(pos.get('size', 10))
    cx = pos['x'] + X_W * size / 2
    cy = pos['y'] + 2.5 - X_H * size / 2
    return any(b[0] - 0.5 <= cx <= b[1] + 0.5 and b[2] - 1.5 <= cy <= b[3] + 1.5 for b in bx)


def limit(pg, pos: dict) -> float:
    """maxw: ως την επόμενη ετικέτα ή κάθετη γραμμή της ίδιας σειράς, αλλιώς ως τα 200 mm."""
    base = pos['y'] + 2.5
    nxt = [w['x0'] * MM for w in pg.extract_words()
           if w['x0'] * MM > pos['x'] + 2 and w['top'] * MM - 1.0 < base - 1.2 < w['bottom'] * MM + 1.0
           and not set(w['text']) <= set('.…') and not w['text'].startswith('6202')]
    vx = [e['x0'] * MM for e in pg.edges
          if e['orientation'] == 'v' and e['x0'] * MM > pos['x'] + 2 and e['top'] * MM < base < e['bottom'] * MM]
    return math.floor((min(nxt + vx + [201.0]) - pos['x'] - 1.0) * 10) / 10


def local_shift(ref: list[dict], tgt: list[dict], x: float, y: float) -> tuple[float, float]:
    """Πόσο μετακινήθηκαν οι 4 κοντινότερες ετικέτες γύρω από (x, y).

    Όχι μόνο οι μοναδικές της σελίδας (όπως το `shift` του biz): εδώ η
    γραμμή του πίνακα «ΑΡΙΘΜΟΣ ΠΑΡΟΧΗΣ» έχει την ίδια λέξη και σε άλλες
    γραμμές, και οι μοναδικές πιο κοντά είναι στη μπάρα «Πάγια εντολή», που
    στο Picasso είναι γραμμένη αλλιώς. Κάθε λέξη ταιριάζει με την ίδια λέξη
    του άλλου PDF που απέχει λιγότερο από 3 mm.
    """
    def dist(w: dict) -> float:
        return math.hypot(max(0.0, w['x0'] - x, x - w['x1']), (w['top'] + w['bot']) / 2 - (y + 1.5))

    # Πρώτα η ίδια η ετικέτα του πεδίου: η λέξη που τελειώνει λίγο πριν από
    # την τιμή, στην ίδια γραμμή.
    own = [w for w in ref if -0.5 < x - w['x1'] < 14 and abs(w['top'] - (y + 0.5)) < 2.0]
    if own:
        w = min(own, key=lambda w: x - w['x1'])
        cand = [t for t in tgt if t['t'] == w['t'] and abs(t['x0'] - w['x0']) < 3 and abs(t['top'] - w['top']) < 3]
        if cand:
            c = min(cand, key=lambda t: abs(t['x0'] - w['x0']) + abs(t['top'] - w['top']))
            return c['x0'] - w['x0'], c['top'] - w['top']

    pairs = []
    for w in sorted(ref, key=dist):
        if len(w['t']) < 3:
            continue
        cand = [t for t in tgt if t['t'] == w['t'] and abs(t['x0'] - w['x0']) < 3 and abs(t['top'] - w['top']) < 3]
        if cand:
            c = min(cand, key=lambda t: abs(t['x0'] - w['x0']) + abs(t['top'] - w['top']))
            pairs.append((c['x0'] - w['x0'], c['top'] - w['top']))
        if len(pairs) == 4:
            break
    if not pairs:
        raise SystemExit(f'καμία κοινή ετικέτα γύρω από ({x}, {y})')
    return statistics.median(p[0] for p in pairs), statistics.median(p[1] for p in pairs)


def build(src: Path, key: str, ref_map: dict, ref_words: list[list[dict]]) -> tuple[dict, int, int]:
    app_name, gos_name, _ = SHEETS[key]
    app = pdfplumber.open(str(src / app_name))
    gos = pdfplumber.open(str(src / gos_name))
    tw = biz.words(app)
    n_app = len(app.pages)
    terms = n_app
    page_of = {1: 1, 2: 2, 3: terms}

    fields: dict[str, list[dict]] = {}
    for fkey, v in ref_map['fields'].items():
        for pl in biz.placements(v):
            p = pl['page']
            if p > 3:
                raise SystemExit(f'{REF_KEY}: πεδίο {fkey} στη σελ. {p}')
            new = dict(pl)
            new['page'] = page_of[p]
            pg = app.pages[new['page'] - 1]
            if p == 2:
                a = biz.PAGE2_ANCHOR[fkey]
                new['y'] = round(biz.anchor_top(tw[1], a) + (pl['y'] - REF_P2_TOP[fkey]), 1)
            elif not pl.get('check'):
                dx, dy = local_shift(ref_words[p - 1], tw[new['page'] - 1], pl['x'], pl['y'])
                if abs(dx) > 0.3 or abs(dy) > 0.3:
                    raise SystemExit(f'{key}: {fkey} μετακινήθηκε ({dx:+.1f}, {dy:+.1f}) -- άλλη διάταξη;')
            if pl.get('check') and fkey != 'pliromi_pagia_entoli' and not in_box(new, boxes(pg)):
                raise SystemExit(f'{key}: το Χ του {fkey} (σελ. {new["page"]}) δεν πέφτει σε κουτί')
            if not new.get('check') and 'maxw' not in new:
                new['maxw'] = limit(pg, new)
            fields.setdefault(fkey, []).append(new)

    sigs = [{**s, 'page': terms} for s in ref_map['sigs'] if s['page'] == 3]
    for s in biz.sig_lines(app):
        if s['page'] == terms:
            continue
        sig, _ = biz.line_slot(s, s['page'], above=True)
        sigs.append(sig)
    gos_lines = biz.sig_lines(gos)
    if not gos_lines:
        raise SystemExit(f'{key}: οι ΓΟΣ ({gos_name}) χωρίς γραμμή υπογραφής πελάτη')
    for s in gos_lines:
        sig, _ = biz.line_slot(s, n_app + s['page'], above=False)
        sigs.append(sig)

    m = {
        'page_w': round(app.pages[0].width * MM, 1),
        'page_h': round(app.pages[0].height * MM, 1),
        'fields': {k: (v[0] if len(v) == 1 else v) for k, v in fields.items()},
        'sigs': sigs,
        'labels': ref_map.get('labels', {}),
    }
    return m, n_app, len(gos.pages)


def main() -> None:
    src = Path(sys.argv[1])
    write = '--write' in sys.argv
    images = '--maps-only' not in sys.argv
    ref_map = json.loads((OUT / f'{REF_KEY}.json').read_text(encoding='utf-8'))
    ref_words = biz.words(pdfplumber.open(str(src / REF_WORDS_PDF)))

    done_gos: set[str] = set()
    for key, (app_name, gos_name, gos_key) in SHEETS.items():
        m, n_app, n_gos = build(src, key, ref_map, ref_words)
        m['tail'] = gos_key or 'protergia_gos_' + key.removeprefix('protergia_')
        print(f'{key:28} αίτηση {n_app} σελ. + ΓΟΣ {n_gos} · υπογραφές {[s["page"] for s in m["sigs"]]}')
        if not write:
            continue
        if images:
            for old in OUT.glob(f'{key}-*.jpg'):
                old.unlink()
            biz.render(src / app_name, key)
            if m['tail'] not in done_gos:
                biz.render(src / gos_name, m['tail'])
                done_gos.add(m['tail'])
        (OUT / f'{key}.json').write_text(json.dumps(m, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')


if __name__ == '__main__':
    main()
