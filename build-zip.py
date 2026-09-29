"""Builds faith-tv-series.zip, the file you upload in WordPress (Plugins > Add New Plugin > Upload Plugin)."""

import os
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = 'faith-tv-series'
OUT = os.path.join(HERE, 'faith-tv-series.zip')


def build(out=OUT):
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
        for root, dirs, files in os.walk(os.path.join(HERE, SRC)):
            dirs[:] = sorted(d for d in dirs if not d.startswith('.'))
            for name in sorted(files):
                if name.startswith('.'):
                    continue
                path = os.path.join(root, name)
                z.write(path, os.path.relpath(path, HERE).replace(os.sep, '/'))
    with zipfile.ZipFile(out) as z:
        count = len(z.namelist())
    print(f'{out}: {count} files, {os.path.getsize(out) // 1024} KB')
    return out


if __name__ == '__main__':
    build()
