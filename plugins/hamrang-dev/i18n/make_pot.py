#!/usr/bin/env python3
"""Build languages/hamrang-watermark.pot, languages/hamrang-watermark-fa_IR.po and .mo
from the plugin sources + fa_IR.py. Requires: polib (pip install polib)."""
import json, os, subprocess, sys, datetime
import polib

HERE = os.path.dirname(os.path.abspath(__file__))
PLUGIN = os.path.normpath(os.path.join(HERE, '..', '..', 'hamrang-watermark'))
LANG = os.path.join(PLUGIN, 'languages')
os.makedirs(LANG, exist_ok=True)
sys.path.insert(0, HERE)
from fa_IR import T  # noqa: E402

version = '1.0.0'
with open(os.path.join(PLUGIN, 'hamrang-watermark.php'), encoding='utf-8') as fh:
    for line in fh:
        if line.strip().startswith('* Version:'):
            version = line.split(':', 1)[1].strip()

strings = json.loads(subprocess.check_output([sys.executable, os.path.join(HERE, 'extract.py')]).decode('utf-8'))
now = datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%d %H:%M+0000')

def meta(lang):
    m = {
        'Project-Id-Version': 'Hamrang – Smart Cover Watermark %s' % version,
        'Report-Msgid-Bugs-To': 'https://ahangban.ir/hamrang',
        'POT-Creation-Date': now,
        'PO-Revision-Date': now,
        'Last-Translator': 'Hamrang <info@ahangban.ir>',
        'Language-Team': 'Persian',
        'MIME-Version': '1.0',
        'Content-Type': 'text/plain; charset=UTF-8',
        'Content-Transfer-Encoding': '8bit',
        'X-Generator': 'hamrang-dev/make_pot.py',
        'X-Domain': 'hamrang-watermark',
    }
    if lang:
        m['Language'] = lang
        m['Plural-Forms'] = 'nplurals=2; plural=(n > 1);'
    else:
        m['Plural-Forms'] = 'nplurals=INTEGER; plural=EXPRESSION;'
    return m

def build(lang):
    po = polib.POFile()
    po.metadata = meta(lang)
    missing = []
    for s in strings:
        e = polib.POEntry(msgid=s['msgid'], occurrences=[tuple(r.rsplit(':', 1)) for r in s['refs']])
        if s['context']:
            e.msgctxt = s['context']
        if s['comment']:
            e.comment = 'translators: ' + s['comment']
        if s['plural']:
            e.msgid_plural = s['plural']
            e.msgstr_plural = {0: '', 1: ''}
        if lang:
            tr = T.get(s['msgid'])
            if tr is None:
                missing.append(s['msgid'])
            elif s['plural']:
                sg, pl = tr if isinstance(tr, tuple) else (tr, tr)
                e.msgstr_plural = {0: sg, 1: pl}
            else:
                e.msgstr = tr if isinstance(tr, str) else tr[0]
        po.append(e)
    return po, missing

pot, _ = build(None)
pot.save(os.path.join(LANG, 'hamrang-watermark.pot'))
po, missing = build('fa_IR')
po.save(os.path.join(LANG, 'hamrang-watermark-fa_IR.po'))
po.save_as_mofile(os.path.join(LANG, 'hamrang-watermark-fa_IR.mo'))
unused = [k for k in T if k not in {s['msgid'] for s in strings}]
print('strings: %d, translated: %d, missing: %d, unused keys: %d' % (len(strings), len(strings) - len(missing), len(missing), len(unused)))
for m in missing:
    print('  MISSING:', m)
for u in unused:
    print('  UNUSED :', u)
# placeholder sanity check
import re
for s in strings:
    tr = T.get(s['msgid'])
    if not tr:
        continue
    for src, dst in ((s['msgid'], tr if isinstance(tr, str) else tr[0]), (s['plural'] or s['msgid'], tr if isinstance(tr, str) else tr[1])):
        a = sorted(re.findall(r'%(?:\d+\$)?[sd]', src))
        b = sorted(re.findall(r'%(?:\d+\$)?[sd]', dst))
        if a != b:
            print('  PLACEHOLDER MISMATCH:', src, '->', dst)
