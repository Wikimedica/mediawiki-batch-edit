#!/usr/bin/env python3
"""Replace hard-coded task ids (Gestion:Rédaction/Tâches/1234) with __task_id_N__ placeholders.

The counter restarts at each "vars" block, so every item gets __task_id_1__, __task_id_2__, ...
Works on text, so draft files that are not valid JSON yet are converted too.
Usage: convert-task-ids.py FILE [FILE ...]   (files are modified in place; use git diff to review)
"""
import re
import sys

TASK = re.compile(r'Gestion:Rédaction/Tâches/(\d+|__task_id(?:_\w+?)?__)')
VARS = re.compile(r'"vars"\s*:')

for path in sys.argv[1:]:
    text = open(path, encoding='utf-8').read()
    out, pos, n, changed = [], 0, 0, 0
    tokens = sorted([(m.start(), 'vars', m) for m in VARS.finditer(text)] +
                    [(m.start(), 'task', m) for m in TASK.finditer(text)], key=lambda t: t[0])
    for start, kind, m in tokens:
        if kind == 'vars':
            n = 0
            continue
        n += 1
        out.append(text[pos:m.start(1)])
        out.append(f'__task_id_{n}__')
        pos = m.end(1)
        changed += 1
    out.append(text[pos:])
    open(path, 'w', encoding='utf-8').write(''.join(out))
    print(f'{path}: {changed} task ids replaced')
