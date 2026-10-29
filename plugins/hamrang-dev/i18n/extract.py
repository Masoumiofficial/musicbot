#!/usr/bin/env python3
"""Extract translatable strings (text domain hamrang-watermark) from the plugin's PHP files.
Prints a JSON list of {msgid, plural, context, refs, comment}."""
import re, sys, os, json
ROOT = os.path.join(os.path.dirname(__file__), '..', '..', 'hamrang-watermark')
DOMAIN = 'hamrang-watermark'
STR = r"""(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")"""
FUNCS = {
    '__': ('s',), '_e': ('s',), 'esc_html__': ('s',), 'esc_html_e': ('s',), 'esc_attr__': ('s',), 'esc_attr_e': ('s',),
    '_x': ('s', 'c'), '_ex': ('s', 'c'), 'esc_html_x': ('s', 'c'), 'esc_attr_x': ('s', 'c'),
    '_n': ('s', 'p', 'n'), '_nx': ('s', 'p', 'n', 'c'),
}
pat = re.compile(r"\b(" + "|".join(re.escape(f) for f in FUNCS) + r")\(\s*" )
def unq(m1, m2):
    s = m1 if m1 is not None else m2
    if m1 is not None:
        return s.replace("\\'", "'").replace('\\\\', '\\')
    return bytes(s, 'utf-8').decode('unicode_escape').encode('latin-1').decode('utf-8') if '\\' in s else s
entries = {}
for dp, dn, fn in os.walk(ROOT):
    for f in sorted(fn):
        if not f.endswith('.php'):
            continue
        path = os.path.join(dp, f)
        rel = os.path.relpath(path, ROOT)
        src = open(path, encoding='utf-8').read()
        lines = src.split('\n')
        for m in pat.finditer(src):
            func = m.group(1)
            spec = FUNCS[func]
            pos = m.end()
            args = []
            ok = True
            for kind in spec:
                if kind == 'n':
                    # skip a non-string argument up to the next comma at depth 0
                    depth = 0; i = pos
                    while i < len(src):
                        ch = src[i]
                        if ch in '([': depth += 1
                        elif ch in ')]':
                            if depth == 0: break
                            depth -= 1
                        elif ch == ',' and depth == 0: break
                        i += 1
                    args.append(None); pos = i
                else:
                    sm = re.compile(r"\s*" + STR + r"\s*").match(src, pos)
                    if not sm:
                        ok = False; break
                    args.append(unq(sm.group(1), sm.group(2))); pos = sm.end()
                cm = re.compile(r"\s*,\s*").match(src, pos)
                if cm: pos = cm.end()
            if not ok:
                continue
            dm = re.compile(r"\s*" + STR).match(src, pos)
            if not dm or unq(dm.group(1), dm.group(2)) != DOMAIN:
                continue
            line = src.count('\n', 0, m.start()) + 1
            # translators comment on the previous lines
            comment = ''
            same = re.search(r"translators:\s*(.*?)\s*\*/", lines[line - 1][: m.start() - (len(src) - len(src.split('\n', line - 1)[-1]) if False else 0)])
            for back in range(0, 4):
                if line - back - 1 < 0: break
                prev = lines[line - back - 1]
                if back == 0:
                    prev = prev[: prev.find(func + '(')] if func + '(' in prev else ''
                cmm = re.search(r"translators:\s*(.*?)\s*\*/", prev)
                if cmm:
                    comment = cmm.group(1); break
                if back > 0 and pat.search(prev):
                    break
            d = dict(zip(spec, args))
            key = (d.get('c') or '', d['s'])
            e = entries.setdefault(key, {'msgid': d['s'], 'plural': d.get('p'), 'context': d.get('c') or '', 'refs': [], 'comment': comment})
            e['refs'].append(f"{rel}:{line}")
            if comment and not e['comment']:
                e['comment'] = comment
json.dump(list(entries.values()), sys.stdout, ensure_ascii=False, indent=1)
