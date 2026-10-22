#!/usr/bin/env python3
"""
(322) Χάρτες για τα επαγγελματικά φύλλα ρεύματος Protergia του Οκτωβρίου 2026.

    python tools/protergia-biz-2026-maps.py <φάκελος με τα PDF της Protergia> [--write [--maps-only]]

## Από πού έρχονται οι θέσεις

Όλες οι αιτήσεις (Value Simple/Standard/Special/Balance/Power/Sure,
Dynamic One, Picasso 2.0, Seasonal) είναι το ΙΔΙΟ έντυπο της Protergia με
άλλον πίνακα τιμών:

- σελ. 1 (στοιχεία πελάτη, παροχή) και η σελίδα «Αποδοχή όρων» είναι ίδιες
  σε όλα -- μετρημένο: οι ετικέτες τους πέφτουν στο ίδιο σημείο ±0,1 mm·
- σελ. 2 (πίνακας τιμολογίου) αλλάζει ύψος από πρόγραμμα σε πρόγραμμα·
- Dynamic One και Picasso έχουν μία σελίδα παραπάνω (ωριαία διαστήματα /
  εκκαθάριση πακέτων) πριν από τους όρους.

Γι' αυτό οι θέσεις ΔΕΝ ξαναμετρώνται: έρχονται από τον χάρτη του
`protergia_epag_simple2` (Value Simple 2.0 ε1, ελεγμένος σε εκτύπωση από το
(288)/(319)), που είναι ακριβώς το ίδιο PDF με το `aitisi_business_ε1-value-
simple-2_0.pdf` της αποστολής 22/10 (pixel προς pixel). Για κάθε θέση το
script βρίσκει τις κοντινότερες ετικέτες που υπάρχουν μία φορά και στα δύο
PDF και μεταφέρει τη θέση όσο μετακινήθηκαν αυτές. Στη σελ. 1 και στους
όρους η μετακίνηση πρέπει να βγει 0 -- αν όχι, το script σταματά.

Στη σελ. 2 οι τρεις θέσεις (ημερομηνία, «Ηλεκτρονικά», εγγύηση) ακολουθούν
η καθεμία τη δική της ετικέτα.

## Υπογραφές

- Οι δύο της σελίδας όρων: όπως στο simple2.
- Κάθε «ΥΠΟΓΡΑΦΗ / ΣΦΡΑΓΙΔΑ ΠΕΛΑΤΗ» με γραμμή από πάνω, σε όποια άλλη σελίδα
  της αίτησης (Picasso: δύο στην εκκαθάριση, Dynamic One: μία) και στους ΓΟΣ.
- (Protergia 22/10) Εταιρεία: «ΟΝΟΜΑΤΕΠΩΝΥΜΟ - ΝΟΜΙΜΟΣ ΕΚΠΡΟΣΩΠΟΣ» σε κάθε
  σημείο υπογραφής (`nomimos_ekprosopos_ypografi`, μία θέση ανά υπογραφή).

## ΓΟΣ

Οι Γενικοί Όροι κάθε προγράμματος τυπώνονται μετά την αίτηση και
υπογράφονται, όπως στα φύλλα του (283). Είναι ξεχωριστές εικόνες
(`protergia_gos_*-N.jpg`) που ο χάρτης δηλώνει με `"tail"`: τα 9 Picasso
έχουν τους ίδιους ΓΟΣ και τους κρατούν μία φορά, όχι εννέα (~3,5 MB).

Χρειάζεται pdfplumber, Pillow και pdftoppm (poppler).
"""

from __future__ import annotations

import json
import math
import statistics
import subprocess
import sys
import tempfile
from pathlib import Path

import pdfplumber
from PIL import Image

MM = 25.4 / 72
ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / 'assets' / 'forms' / 'protergia'
REF_KEY = 'protergia_epag_simple2'
REF_PDF = 'aitisi_business_ε1-value-simple-2_0.pdf'
CAPTION = 'nomimos_ekprosopos_ypografi'

# key => (αίτηση, ΓΟΣ, κλειδί εικόνων ΓΟΣ). ΓΟΣ None = ήδη σελίδες του φύλλου.
PICASSO_GOS = ('gos_epaggelmatiko_picasso_20.pdf', 'protergia_gos_pic_b')
SHEETS: dict[str, tuple[str, str | None, str | None]] = {
    'protergia_epag1_balance_12m': ('aitisi_value-balance-e1-12m.pdf', 'gos_value-balance-e1-12m.pdf', None),
    'protergia_epag1_standard2':   ('aitisi_ε1-value-standard-2_0.pdf', 'gos_1-value-standard-2_0.pdf', None),
    'protergia_epag1_dynamic_one': ('aitisi_epaggelmatiko_e1_dynamic_one.pdf', 'gos_epaggelmatiko_e1_dynamic_one.pdf', None),
    'protergia_epag1_special':     ('aitisi_epaggelmatiko_e1_value_special.pdf', 'gos_epaggelmatiko_e1_value_special.pdf', None),
    'protergia_epag2_power2':      ('aitisi_value-power-2_0.pdf', 'gos_e2-value-power-2_0.pdf', None),
    'protergia_epag2_standard2':   ('aitisi_ε2-value-standard-2_0.pdf', 'gos_2-value-standard-2_0.pdf', None),
    'protergia_epag2_simple2':     ('aitisi_business_ε2-value-simple-2_0.pdf', 'gos_business_value-simple-ε2-2_0.pdf', None),
    'protergia_epag2_dynamic_one': ('aitisi_epaggelmatiko_e2_dynamic_one.pdf', 'gos_epaggelmatiko_e2_dynamic_one.pdf', None),
    'protergia_epag2_special':     ('aitisi_epaggelmatiko_e2_value_special.pdf', 'gos_epaggelmatiko_e2_value_special.pdf', None),
    'protergia_epag3_sure12m_2':   ('aitisi-value-sure-12m-e3-business-2_0.pdf', 'gos-value-sure-12m-e3-business-2_0.pdf', None),
    'protergia_epag3_standard2':   ('aitisi_ε3-value-standard-2_0.pdf', 'gos_3-value-standard-2_0.pdf', None),
    'protergia_epag3_simple2':     ('aitisi_business_ε3-value-simple-2_0.pdf', 'gos_business_value-simple-ε3-2_0.pdf', None),
    'protergia_epag3_special':     ('aitisi_epaggelmatiko_e3_value_special.pdf', 'gos_epaggelmatiko_e3_value_special.pdf', None),
    # (ProtergiaPicassoPlans) s/m/l × 1/2/3 = οι τρεις τιμές κάθε μεγέθους
    'protergia_pic_b_s1': ('aitisi_epaggelmatiko_e1_picasso_small_4990.pdf', *PICASSO_GOS),
    'protergia_pic_b_s2': ('aitisi_epaggelmatiko_e1_picasso_small_6490.pdf', *PICASSO_GOS),
    'protergia_pic_b_s3': ('aitisi_epaggelmatiko_e1_picasso_small_8290.pdf', *PICASSO_GOS),
    'protergia_pic_b_m1': ('aitisi_epaggelmatiko_e1_picasso_medium_9990.pdf', *PICASSO_GOS),
    'protergia_pic_b_m2': ('aitisi_epaggelmatiko_e1_picasso_medium_12490.pdf', *PICASSO_GOS),
    'protergia_pic_b_m3': ('aitisi_epaggelmatiko_e1_picasso_medium_15490.pdf', *PICASSO_GOS),
    'protergia_pic_b_l1': ('aitisi_epaggelmatiko_e1_picasso_large_25490.pdf', *PICASSO_GOS),
    'protergia_pic_b_l2': ('aitisi_epaggelmatiko_e1_picasso_large_34490.pdf', *PICASSO_GOS),
    'protergia_pic_b_l3': ('aitisi_epaggelmatiko_e1_picasso_large_44490.pdf', *PICASSO_GOS),
    # Τα δύο που είχαν ήδη φύλλο: ίδια αίτηση (simple2) / νεότερη έκδοση
    # χωρίς προτυπωμένο Χ στο 8.6(β) (seasonal _232). Οι ΓΟΣ τους είναι ήδη
    # οι σελ. 4-6 και ίδιοι με της αποστολής 22/10.
    'protergia_epag_simple2':  (REF_PDF, 'gos_business_value-simple-ε1-2_0.pdf', None),
    'protergia_epag_seasonal': ('εο1250-2_232_v1_052026_αίτηση-επαγγελματικό-e1-value-seasonal.pdf',
                                'εο1250-2_220_v1_052026_gos_business_e1_value_seasonal.pdf', None),
}
# Τα δύο παλιά κρατούν τους ΓΟΣ ως δικές τους σελίδες (όχι tail).
OWN_GOS = {'protergia_epag_simple2', 'protergia_epag_seasonal'}

# Σελ. 2: η θέση ακολουθεί τη δική της ετικέτα (μόνο κατακόρυφα).
PAGE2_ANCHOR = {'imerominia_aitisis': 'ΗΜΕΡΟΜΗΝΙΑ', 'ypovoli_ilektronika': 'Ηλεκτρονικά', 'poso_eggiisis': 'ΕΓΓΥΗΣΗ'}

# Σελ. 1: ετικέτες χωρίς θέση στον χάρτη του simple2, που η Protergia θέλει
# συμπληρωμένες στις εταιρείες (22/10). Μετρώνται από το ίδιο το PDF: τιμή
# 1,5 mm μετά την ετικέτα, y = top − 0,6 (όπως Α.Φ.Μ./Κ.Α.Δ. της ίδιας γραμμής).
EXTRA_P1 = {'gemi': ('ΓΕΜΗ:', 200.0)}

SIG_W, SIG_FIT = 36, 11.0
LAT = str.maketrans('ABEHIKMNOPTXYZ', 'ΑΒΕΗΙΚΜΝΟΡΤΧΥΖ')   # λατινικά που μοιάζουν ελληνικά


def words(pdf: pdfplumber.PDF) -> list[list[dict]]:
    out = []
    for pg in pdf.pages:
        ws = []
        for w in pg.extract_words():
            t = w['text'].strip('.…_ ')
            if len(t) >= 2:
                ws.append({'t': t, 'x0': w['x0'] * MM, 'x1': w['x1'] * MM, 'top': w['top'] * MM, 'bot': w['bottom'] * MM})
        out.append(ws)
    return out


def unique(ws: list[dict]) -> dict[str, dict]:
    n: dict[str, int] = {}
    for w in ws:
        n[w['t']] = n.get(w['t'], 0) + 1
    return {w['t']: w for w in ws if n[w['t']] == 1}


def shift(ref: list[dict], tgt: list[dict], x: float, y: float) -> tuple[float, float]:
    """Πόσο μετακινήθηκαν οι 4 κοντινότερες μοναδικές ετικέτες γύρω από (x, y)."""
    ru, tu = unique(ref), unique(tgt)

    def dist(w: dict) -> float:
        return math.hypot(max(0.0, w['x0'] - x, x - w['x1']), (w['top'] + w['bot']) / 2 - (y + 1.5))

    near = sorted((t for t in ru if t in tu), key=lambda t: dist(ru[t]))[:4]
    if not near:
        raise SystemExit(f'καμία κοινή ετικέτα γύρω από ({x}, {y})')
    dx = statistics.median(tu[t]['x0'] - ru[t]['x0'] for t in near)
    dy = statistics.median(tu[t]['top'] - ru[t]['top'] for t in near)
    return dx, dy


def anchor_top(ws: list[dict], text: str) -> float:
    hits = [w for w in ws if w['t'] == text]
    if len(hits) != 1:
        raise SystemExit(f'«{text}»: {len(hits)} φορές στη σελ. 2')
    return hits[0]['top']


def sig_lines(pdf: pdfplumber.PDF) -> list[dict]:
    """«ΥΠΟΓΡΑΦΗ … ΠΕΛΑΤΗ» με γραμμή από πάνω: σελίδα, γραμμή, top ετικέτας."""
    out = []
    for i, pg in enumerate(pdf.pages, 1):
        ws = pg.extract_words()
        for j, w in enumerate(ws):
            if not w['text'].translate(LAT).startswith('ΥΠΟΓΡΑΦΗ'):
                continue
            row = ' '.join(x['text'].translate(LAT) for x in ws[j:j + 5] if abs(x['top'] - w['top']) < 2)
            if 'ΠΕΛΑΤΗ' not in row:
                continue
            x0, top = w['x0'] * MM, w['top'] * MM
            best = None
            for e in pg.edges:
                ex0, ex1, ey = e['x0'] * MM, e['x1'] * MM, e['top'] * MM
                if e['orientation'] != 'h' or ex1 - ex0 < 20 or not (top - 9 < ey < top - 0.3):
                    continue
                if ex0 - 5 > x0 + 20 or ex1 < x0:
                    continue
                if best is None or ey > best[2]:
                    best = (ex0, ex1, ey)
            if best:
                out.append({'page': i, 'x0': best[0], 'x1': best[1], 'line': best[2], 'label': top})
    return out


def line_slot(s: dict, page: int, above: bool) -> tuple[dict, dict]:
    """Υπογραφή πάνω στη γραμμή και η λεζάντα εταιρείας.

    above=True: η ετικέτα κάθεται κολλητά κάτω από τη γραμμή, μέσα σε κουτί
    (Picasso, Dynamic One) -- η λεζάντα μπαίνει ΠΑΝΩ στη γραμμή και η
    υπογραφή από πάνω της. Αλλιώς (ΓΟΣ) η λεζάντα κάτω από την ετικέτα.
    """
    x = round(s['x0'] + 1.0, 1)
    w = min(SIG_W, round(s['x1'] - s['x0'] - 2.0, 1))
    if above:
        bottom = s['line'] - 2.6
        cap = {'page': page, 'x': x, 'y': round(s['line'] - 3.1, 1), 'size': 6.0, 'maxw': round(s['x1'] - s['x0'] - 2.0, 1)}
    else:
        bottom = s['line'] + 0.3
        cap = {'page': page, 'x': round(s['x0'], 1), 'y': round(s['label'] + 2.1, 1), 'size': 6.0, 'maxw': 90.0}
    sig = {'page': page, 'x': x, 'y': round(bottom - SIG_FIT, 1), 'w': w, 'h': 0, 'fit_h': SIG_FIT}
    return sig, cap


def placements(v) -> list[dict]:
    return v if isinstance(v, list) else [v]


def build(src: Path, key: str, ref_map: dict, ref_words: list[list[dict]]) -> tuple[dict, int, int]:
    app_name, gos_name, _ = SHEETS[key]
    app = pdfplumber.open(str(src / app_name))
    gos = pdfplumber.open(str(src / gos_name))
    tw = words(app)
    n_app = len(app.pages)
    terms = n_app                       # η «Αποδοχή όρων» είναι πάντα η τελευταία
    page_of = {1: 1, 2: 2, 3: terms}

    fields: dict[str, list[dict]] = {}
    for fkey, v in ref_map['fields'].items():
        if fkey == CAPTION or fkey in EXTRA_P1:
            continue
        for pl in placements(v):
            p = pl['page']
            if p > 3:
                raise SystemExit(f'{REF_KEY}: πεδίο {fkey} στη σελ. {p}')
            new = dict(pl)
            new['page'] = page_of[p]
            if p == 2:
                a = PAGE2_ANCHOR[fkey]
                new['y'] = round(pl['y'] + anchor_top(tw[1], a) - anchor_top(ref_words[1], a), 1)
            else:
                dx, dy = shift(ref_words[p - 1], tw[page_of[p] - 1], pl['x'], pl['y'])
                if abs(dx) > 0.3 or abs(dy) > 0.3:
                    raise SystemExit(f'{key}: {fkey} μετακινήθηκε ({dx:+.1f}, {dy:+.1f}) -- άλλη διάταξη;')
            fields.setdefault(fkey, []).append(new)

    for fkey, (label, right) in EXTRA_P1.items():
        hits = [w for w in tw[0] if w['t'] == label]
        if len(hits) != 1:
            raise SystemExit(f'{key}: «{label}» {len(hits)} φορές στη σελ. 1')
        x = round(hits[0]['x1'] + 1.5, 1)
        fields[fkey] = [{'page': 1, 'x': x, 'y': round(hits[0]['top'] - 0.6, 1), 'maxw': round(right - x, 1)}]

    sigs: list[dict] = []
    captions: list[dict] = []

    # Οι δύο της σελίδας όρων, όπως στο simple2 (μετρημένες στο (288)).
    for s in ref_map['sigs']:
        if s['page'] != 3:
            continue
        sigs.append({**s, 'page': terms})
    # Λεζάντα κάτω από τις τελείες: «ΥΠΟΓΡΑΦΗ ΠΕΛΑΤΗ:…» (157,3 → Γ. 160,2) και
    # «ΥΠΟΓΡΑΦΗ/ΣΦΡΑΓΙΔΑ ΠΕΛΑΤΗ ……» (243,8 → πλαίσιο 247,2).
    captions.append({'page': terms, 'x': 150.0, 'y': 157.1, 'size': 5.5, 'maxw': 47.0})
    captions.append({'page': terms, 'x': 110.0, 'y': 243.9, 'size': 5.5, 'maxw': 87.0})

    for s in sig_lines(app):
        if s['page'] == terms:
            continue
        sig, cap = line_slot(s, s['page'], above=True)
        sigs.append(sig)
        captions.append(cap)

    gos_lines = sig_lines(gos)
    if not gos_lines:
        raise SystemExit(f'{key}: οι ΓΟΣ ({gos_name}) χωρίς γραμμή υπογραφής πελάτη')
    for s in gos_lines:
        sig, cap = line_slot(s, n_app + s['page'], above=False)
        sigs.append(sig)
        captions.append(cap)

    fields[CAPTION] = captions
    out_fields = {k: (v[0] if len(v) == 1 else v) for k, v in fields.items()}

    m = {
        'page_w': round(app.pages[0].width * MM, 1),
        'page_h': round(app.pages[0].height * MM, 1),
        'fields': out_fields,
        'sigs': sigs,
        'labels': {**ref_map.get('labels', {}), 'gemi': 'Αρ. ΓΕΜΗ',
                   CAPTION: 'Λεζάντα νόμιμου εκπροσώπου κάτω από κάθε υπογραφή'},
    }
    return m, n_app, len(gos.pages)


def render(pdf: Path, key: str) -> int:
    """Σελίδες σε <key>-N.jpg, 150 dpi (A4 → 1241×1754), JPEG 85."""
    with tempfile.TemporaryDirectory() as tmp:
        subprocess.run(['pdftoppm', '-r', '150', '-png', str(pdf), f'{tmp}/p'], check=True)
        pngs = sorted(Path(tmp).glob('p-*.png'), key=lambda p: int(p.stem.rsplit('-', 1)[1]))
        for n, png in enumerate(pngs, 1):
            Image.open(png).convert('RGB').save(OUT / f'{key}-{n}.jpg', 'JPEG', quality=85, optimize=True, dpi=(150, 150))
        return len(pngs)


def main() -> None:
    src = Path(sys.argv[1])
    write = '--write' in sys.argv
    images = '--maps-only' not in sys.argv
    ref_map = json.loads((OUT / f'{REF_KEY}.json').read_text(encoding='utf-8'))
    ref_words = words(pdfplumber.open(str(src / REF_PDF)))

    done_gos: set[str] = set()
    for key, (app_name, gos_name, gos_key) in SHEETS.items():
        m, n_app, n_gos = build(src, key, ref_map, ref_words)
        if key not in OWN_GOS:
            m['tail'] = gos_key or 'protergia_gos_' + key.removeprefix('protergia_')
        print(f'{key:30} αίτηση {n_app} σελ. + ΓΟΣ {n_gos} · υπογραφές {[s["page"] for s in m["sigs"]]}')
        if not write:
            continue
        if key == 'protergia_epag_simple2' or not images:
            pass                                  # οι εικόνες είναι ήδη αυτές (pixel προς pixel)
        elif key in OWN_GOS:
            if render(src / app_name, key) != n_app:
                raise SystemExit(key)
        else:
            for old in OUT.glob(f'{key}-*.jpg'):
                old.unlink()
            render(src / app_name, key)
            if m['tail'] not in done_gos:
                render(src / gos_name, m['tail'])
                done_gos.add(m['tail'])
        (OUT / f'{key}.json').write_text(json.dumps(m, ensure_ascii=False, indent=1) + '\n', encoding='utf-8')


if __name__ == '__main__':
    main()
