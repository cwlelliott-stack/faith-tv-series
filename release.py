"""Publishes a new version of the plugin. Websites running it see "Update available" on their Plugins page.

    python release.py 1.3.0 "Sunday live, sermon library"
    python release.py 1.3.0 "Sunday live, sermon library" --rollout 25   (a quarter of sites first)
    python release.py 1.3.0 "..." --dry-run          (shows what would happen, changes nothing)
    python release.py --rollout 1.3.0 100            (widen or hold back a release: 0 = hold it)

Steps: sets the version in the plugin files, adds the note to the changelog, builds
faith-tv-series.zip, signs latest.json (version, zip address, its SHA-256, rollout), commits,
tags v1.3.0, pushes, and creates the GitHub release with both files attached.

Websites install an update only if latest.json is signed with FaithStream's release key and
the zip matches its SHA-256. The key is ~/.faith-tv-series/release-signing.key on the
release computer (never in git). BACK IT UP: without it, installed sites won't accept new
versions until someone uploads a zip by hand. Needs git, the GitHub CLI (gh) signed in, and
Python's "cryptography" package.
"""

import base64
import hashlib
import importlib.util
import json
import os
import re
import subprocess
import sys
import tempfile
from datetime import datetime, timezone

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = 'cwlelliott-stack/faith-tv-series'
KEY = os.path.join(os.path.expanduser('~'), '.faith-tv-series', 'release-signing.key')

# build-zip.py has a dash in its name, so load it by path.
_spec = importlib.util.spec_from_file_location('build_zip', os.path.join(HERE, 'build-zip.py'))
_build_zip = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_build_zip)
build = _build_zip.build

MAIN = os.path.join(HERE, 'faith-tv-series', 'faith-tv-series.php')
README = os.path.join(HERE, 'faith-tv-series', 'readme.txt')
UPDATER = os.path.join(HERE, 'faith-tv-series', 'includes', 'class-updater.php')


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


def signing_key():
    try:
        from cryptography.hazmat.primitives import serialization
    except ImportError:
        sys.exit('Python\'s "cryptography" package is needed to sign releases: pip install cryptography')
    if not os.path.exists(KEY):
        sys.exit(f'The release signing key is missing: {KEY}\nRestore it from your backup (sites only accept releases signed with it).')
    key = serialization.load_pem_private_key(read_bytes(KEY), password=None)
    pub = key.public_key().public_bytes(serialization.Encoding.Raw, serialization.PublicFormat.Raw)
    shipped = re.search(r"const PUBLIC_KEY = '([^']+)';", read(UPDATER))
    if not shipped or base64.b64encode(pub).decode() != shipped.group(1):
        sys.exit('The signing key does not match the public key in class-updater.php. Sites would refuse this release.')
    return key


def read_bytes(path):
    with open(path, 'rb') as f:
        return f.read()


def manifest(key, version, sha256, notes, rollout):
    """latest.json: the release details as a string, and the Ed25519 signature of exactly that string."""
    readme = read(README)
    tested = re.search(r'Tested up to:\s+([0-9.]+)', readme)
    payload = json.dumps(
        {
            'version': version,
            'package': f'https://github.com/{REPO}/releases/download/v{version}/faith-tv-series.zip',
            'sha256': sha256,
            'requires': '6.0',
            'requires_php': '7.4',
            'tested': tested.group(1) if tested else '',
            'notes': notes,
            'published': datetime.now(timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ'),
            'rollout': rollout,
        },
        sort_keys=True,
        separators=(',', ':'),
        ensure_ascii=False,
    )
    sig = base64.b64encode(key.sign(payload.encode('utf-8'))).decode()
    return json.dumps({'payload': payload, 'sig': sig}, ensure_ascii=False)


def option(name, default):
    if name in sys.argv:
        i = sys.argv.index(name)
        if i + 1 < len(sys.argv):
            return sys.argv[i + 1]
    return default


def change_rollout(version, percent):
    """Re-sign an existing release's latest.json with a new rollout (0 holds it back everywhere)."""
    key = signing_key()
    folder = tempfile.mkdtemp()
    run('gh', 'release', 'download', f'v{version}', '--repo', REPO, '--pattern', 'latest.json', '--dir', folder)
    old = json.loads(read(os.path.join(folder, 'latest.json')))
    data = json.loads(old['payload'])
    path = os.path.join(folder, 'latest.json')
    write(path, manifest(key, version, data['sha256'], data.get('notes', ''), percent))
    run('gh', 'release', 'upload', f'v{version}', path, '--repo', REPO, '--clobber')
    print(f'{version} now reaches {percent}% of sites' + (' (held back).' if percent == 0 else '.'))


def main():
    if len(sys.argv) >= 4 and sys.argv[1] == '--rollout':
        if not re.fullmatch(r'\d+\.\d+\.\d+', sys.argv[2]) or not sys.argv[3].isdigit():
            sys.exit(__doc__)
        change_rollout(sys.argv[2], max(0, min(100, int(sys.argv[3]))))
        return

    flags_with_values = {'--rollout'}
    args = []
    skip = False
    for a in sys.argv[1:]:
        if skip:
            skip = False
            continue
        if a in flags_with_values:
            skip = True
            continue
        if not a.startswith('--'):
            args.append(a)
    dry = '--dry-run' in sys.argv
    rollout = max(0, min(100, int(option('--rollout', '100'))))
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

    # Check everything that could fail later before changing anything.
    key = signing_key()
    try:
        subprocess.run(['gh', 'auth', 'status'], cwd=HERE, check=True, capture_output=True, text=True)
    except (OSError, subprocess.CalledProcessError):
        sys.exit('The GitHub CLI (gh) is missing or not signed in. Run: gh auth login')
    run('git', 'fetch', '--quiet', 'origin', 'main', '--tags')
    behind = run('git', 'rev-list', '--count', 'HEAD..origin/main', capture=True)
    if behind and behind != '0':
        sys.exit(f'Your main is {behind} commit(s) behind GitHub. Run: git pull')
    if run('git', 'ls-remote', '--tags', 'origin', f'refs/tags/v{new}', capture=True):
        sys.exit(f'Tag v{new} already exists on GitHub.')

    main_text = read(MAIN)
    main_text = re.sub(r'(\* Version:\s+)[0-9.]+', lambda m: m.group(1) + new, main_text, count=1)
    main_text = main_text.replace(f"define( 'FTVS_VERSION', '{old}' );", f"define( 'FTVS_VERSION', '{new}' );")
    readme = read(README)
    readme = re.sub(r'(Stable tag:\s+)[0-9.]+', lambda m: m.group(1) + new, readme, count=1)
    nl = '\r\n' if '\r\n' in readme else '\n'
    entry = f'= {new} ={nl}* {note}{nl}{nl}'
    readme = readme.replace(f'== Changelog =={nl}{nl}', f'== Changelog =={nl}{nl}{entry}', 1)

    print(f'Version {old} -> {new}: "{note}" (rollout {rollout}%)')
    if dry:
        print('Dry run: would update faith-tv-series.php and readme.txt, build and sign, commit,')
        print(f'tag v{new}, push to GitHub and create the release. Nothing was changed.')
        return

    write(MAIN, main_text)
    write(README, readme)
    zip_path = build()
    sha = hashlib.sha256(read_bytes(zip_path)).hexdigest()
    latest = os.path.join(HERE, 'latest.json')
    write(latest, manifest(key, new, sha, note, rollout))

    run('git', 'add', MAIN, README)
    run('git', 'commit', '-m', f'Release {new}: {note}')
    run('git', 'tag', '-a', f'v{new}', '-m', f'Faith TV Series {new}')
    steps = [
        ['git', 'push', 'origin', 'main'],
        ['git', 'push', 'origin', f'v{new}'],
        ['gh', 'release', 'create', f'v{new}', zip_path, latest, '--title', f'Faith TV Series {new}', '--notes', note],
    ]
    for i, step in enumerate(steps):
        try:
            run(*step)
        except (OSError, subprocess.CalledProcessError) as err:
            print()
            print('This step failed: ' + ' '.join(step))
            print(f'  ({err})')
            print('The version commit and tag are made locally. Finish by running these yourself:')
            for rest in steps[i:]:
                print('  ' + ' '.join(f'"{a}"' if ' ' in a else a for a in rest))
            sys.exit(1)
    print()
    print(f'Released {new} to {rollout}% of sites. Websites see it within 12 hours, or right away')
    print('with Faith Stream > Updates > Check for updates now.')
    if rollout < 100:
        print(f'When it looks good: python release.py --rollout {new} 100')


if __name__ == '__main__':
    main()
