#!/usr/bin/env python3
import re
import zipfile
from pathlib import Path

p = Path(r"c:\Users\dev\Pictures\B2B data.xlsx")
with zipfile.ZipFile(p) as z:
    shared = z.read("xl/sharedStrings.xml").decode("utf-8", errors="replace")
    strings = re.findall(r"<t[^>]*>([^<]*)</t>", shared)
    print("shared count", len(strings))
    for s in strings[:40]:
        print(repr(s))
