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


def check_rtl():
    """The right-to-left stylesheets are generated; say so if one is older than its source."""
    for name in ('faith-tv-series', 'admin'):
        src = os.path.join(HERE, SRC, 'assets', name + '.css')
        rtl = os.path.join(HERE, SRC, 'assets', name + '-rtl.css')
        if not os.path.exists(rtl) or os.path.getmtime(rtl) < os.path.getmtime(src):
            print(f'WARNING: assets/{name}-rtl.css is older than {name}.css. Run: npx rtlcss {SRC}/assets/{name}.css {SRC}/assets/{name}-rtl.css')


def build(out=OUT, wporg=False):
    check_rtl()
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
                if wporg and rel == 'includes/class-admin.php':
                    # No outside calls at all: the Google Fonts line goes (it only runs in the direct edition
                    # anyway, but reviewers scan for it).
                    text = open(path, encoding='utf-8', newline='').read()
                    text, n = re.subn(r'[ \t]*if \( class_exists\( \'FTVS_Updater\' \) \) \{\r?\n[ \t]*wp_enqueue_style\( \'ftvs-admin-font\'.*?\r?\n[ \t]*\}\r?\n', '', text, flags=re.S)
                    if n != 1 or 'fonts.googleapis.com' in text:
                        sys.exit('build-zip: could not take the Google Fonts line out of class-admin.php')
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
