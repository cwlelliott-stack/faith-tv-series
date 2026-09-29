"""Publishes a new version of the plugin. Websites running it see "Update available" on their Plugins page.

    python release.py 1.2.0 "Cleaner phone layout"
    python release.py 1.2.0 "Cleaner phone layout" --dry-run    (shows what would happen, changes nothing)

Steps: sets the version in the plugin files, adds the note to the changelog, builds
faith-tv-series.zip, commits, tags v1.2.0, pushes, and creates the GitHub release with
the zip attached. Needs git and the GitHub CLI (gh) signed in.
"""

import importlib.util
import os
import re
import subprocess
import sys

HERE = os.path.dirname(os.path.abspath(__file__))

# build-zip.py has a dash in its name, so load it by path.
_spec = importlib.util.spec_from_file_location('build_zip', os.path.join(HERE, 'build-zip.py'))
_build_zip = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_build_zip)
build = _build_zip.build

MAIN = os.path.join(HERE, 'faith-tv-series', 'faith-tv-series.php')
README = os.path.join(HERE, 'faith-tv-series', 'readme.txt')


def run(*cmd, capture=False):
    result = subprocess.run(cmd, cwd=HERE, check=True, text=True, capture_output=capture)
    return result.stdout.strip() if capture else None


def read(path):
    with open(path, encoding='utf-8', newline='') as f:
        return f.read()


def write(path, text):
    with open(path, 'w', encoding='utf-8', newline='') as f:
        f.write(text)


def current_version():
    m = re.search(r"define\( 'FTVS_VERSION', '([0-9.]+)' \);", read(MAIN))
    if not m:
        sys.exit('Could not find FTVS_VERSION in faith-tv-series.php')
    return m.group(1)


def as_tuple(v):
    return tuple(int(x) for x in v.split('.'))


def main():
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    dry = '--dry-run' in sys.argv
    if len(args) != 2 or not re.fullmatch(r'\d+\.\d+\.\d+', args[0]):
        sys.exit(__doc__)
    new, note = args[0], args[1].strip()
    old = current_version()
    if as_tuple(new) <= as_tuple(old):
        sys.exit(f'{new} must be higher than the current version {old}.')
    if not note:
        sys.exit('Add a short note about what changed.')

    branch = run('git', 'rev-parse', '--abbrev-ref', 'HEAD', capture=True)
    if branch != 'main':
        sys.exit(f'Release from main (you are on {branch}).')
    if run('git', 'status', '--porcelain', capture=True):
        sys.exit('Commit or stash your changes first; a release must match what is in git.')
    if run('git', 'tag', '--list', f'v{new}', capture=True):
        sys.exit(f'Tag v{new} already exists.')

    main_text = read(MAIN)
    main_text = re.sub(r'(\* Version:\s+)[0-9.]+', lambda m: m.group(1) + new, main_text, count=1)
    main_text = main_text.replace(f"define( 'FTVS_VERSION', '{old}' );", f"define( 'FTVS_VERSION', '{new}' );")
    readme = read(README)
    readme = re.sub(r'(Stable tag:\s+)[0-9.]+', lambda m: m.group(1) + new, readme, count=1)
    nl = '\r\n' if '\r\n' in readme else '\n'
    entry = f'= {new} ={nl}* {note}{nl}{nl}'
    readme = readme.replace(f'== Changelog =={nl}{nl}', f'== Changelog =={nl}{nl}{entry}', 1)

    print(f'Version {old} -> {new}: "{note}"')
    if dry:
        print('Dry run: would update faith-tv-series.php and readme.txt, build the zip, commit,')
        print(f'tag v{new}, push to GitHub and create the release. Nothing was changed.')
        return

    write(MAIN, main_text)
    write(README, readme)
    zip_path = build()

    run('git', 'add', MAIN, README)
    run('git', 'commit', '-m', f'Release {new}: {note}')
    run('git', 'tag', '-a', f'v{new}', '-m', f'Faith TV Series {new}')
    run('git', 'push', 'origin', 'main')
    run('git', 'push', 'origin', f'v{new}')
    run('gh', 'release', 'create', f'v{new}', zip_path, '--title', f'Faith TV Series {new}', '--notes', note)
    print()
    print(f'Released {new}. Websites see it within 12 hours, or right away with')
    print('Settings > Faith TV Series > Check for updates now.')


if __name__ == '__main__':
    main()
