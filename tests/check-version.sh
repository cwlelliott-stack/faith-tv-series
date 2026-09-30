#!/usr/bin/env bash
# The plugin's version is written in three places, and they must agree:
#   faith-tv-series/faith-tv-series.php   "Version:" in the plugin header
#   faith-tv-series/faith-tv-series.php   define( 'FTVS_VERSION', '...' )
#   faith-tv-series/readme.txt            "Stable tag:"
# release.py sets all three; this catches a hand edit that missed one.
#
#   bash tests/check-version.sh            # the three agree
#   bash tests/check-version.sh v1.3.0     # ...and equal this tag (a leading "v" is ignored)
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
plugin=faith-tv-series/faith-tv-series.php
readme=faith-tv-series/readme.txt

header="$(sed -n 's/^[[:space:]*]*Version:[[:space:]]*\([^[:space:]]*\).*$/\1/p' "$plugin" | head -n 1)"
const="$(sed -n "s/^define([[:space:]]*'FTVS_VERSION',[[:space:]]*'\([^']*\)'.*$/\1/p" "$plugin" | head -n 1)"
stable="$(sed -n 's/^Stable tag:[[:space:]]*\([^[:space:]]*\).*$/\1/p' "$readme" | head -n 1)"

echo "plugin header Version: ${header:-<not found>}"
echo "FTVS_VERSION:          ${const:-<not found>}"
echo "readme.txt Stable tag: ${stable:-<not found>}"

fail=0
for v in "$header" "$const" "$stable"; do
	if ! printf '%s' "$v" | grep -Eq '^[0-9]+(\.[0-9]+){1,3}$'; then
		echo "ERROR: '$v' is not a version number like 1.2.3" >&2
		fail=1
	fi
done
if [ "$header" != "$const" ] || [ "$header" != "$stable" ]; then
	echo "ERROR: the three versions differ" >&2
	fail=1
fi

if [ $# -gt 0 ]; then
	tag="${1#refs/tags/}"
	tag="${tag#v}"
	echo "tag:                   $tag"
	if [ "$tag" != "$header" ]; then
		echo "ERROR: the tag ($1) does not match the plugin version ($header)" >&2
		fail=1
	fi
fi

if [ "$fail" -eq 0 ]; then
	echo "OK"
fi
exit "$fail"
