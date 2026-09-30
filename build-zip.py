"""Builds faith-tv-series.zip, the file you upload in WordPress (Plugins > Add New Plugin > Upload Plugin).

    python build-zip.py            the direct edition (updates itself from FaithStream's signed releases)
    python build-zip.py --wporg    the wordpress.org edition: no self-updater (WordPress.org updates it),
                                   no "Update URI" header, no Updates tab
"""

import os
import re
import sys
import zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
SRC = 'faith-tv-series'
OUT = os.path.join(HERE, 'faith-tv-series.zip')
OUT_WPORG = os.path.join(HERE, 'faith-tv-series-wporg.zip')

# Never shipped: development notes and anything the wordpress.org rules don't allow.
SKIP_ALWAYS = {'.DS_Store', 'Thumbs.db'}
SKIP_WPORG = {'includes/class-updater.php'}


def build(out=OUT, wporg=False):
    with zipfile.ZipFile(out, 'w', zipfile.ZIP_DEFLATED) as z:
        for root, dirs, files in os.walk(os.path.join(HERE, SRC)):
            dirs[:] = sorted(d for d in dirs if not d.startswith('.') and d != '__pycache__')
            for name in sorted(files):
                if name.startswith('.') or name in SKIP_ALWAYS:
                    continue
                path = os.path.join(root, name)
                rel = os.path.relpath(path, os.path.join(HERE, SRC)).replace(os.sep, '/')
                if wporg and rel in SKIP_WPORG:
                    continue
                arc = os.path.relpath(path, HERE).replace(os.sep, '/')
                if wporg and rel == 'faith-tv-series.php':
                    text = open(path, encoding='utf-8', newline='').read()
                    text = re.sub(r'^ \* Update URI:.*\r?\n', '', text, flags=re.M)
                    z.writestr(arc, text)
                    continue
                z.write(path, arc)
    with zipfile.ZipFile(out) as z:
        count = len(z.namelist())
    print(f'{out}: {count} files, {os.path.getsize(out) // 1024} KB')
    return out


if __name__ == '__main__':
    if '--wporg' in sys.argv:
        build(OUT_WPORG, wporg=True)
    else:
        build()
