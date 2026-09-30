#!/usr/bin/env bash
# Syntax checks (no WordPress needed). CI runs them on PHP 7.4 to 8.4 and Node 20.
#
#   bash tests/lint.sh          # PHP and JavaScript
#   bash tests/lint.sh php      # php -l on every .php file of the plugin and of tests/
#   bash tests/lint.sh js       # node --check on every plugin script (assets/vendor/ is skipped)
#
# Use another PHP (files are sent on stdin, so Docker works too):
#   PHP="docker exec -i ftvs3-wp php" bash tests/lint.sh php
#   PHP="docker run --rm -i php:7.4-cli php" bash tests/lint.sh php
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."
PHP="${PHP:-php}"
NODE="${NODE:-node}"
what="${1:-all}"
failed=0

lint_php() {
	if ! $PHP -r 'echo 1;' > /dev/null 2>&1; then
		echo "FAIL no PHP to check with ($PHP). Install PHP, or: PHP=\"docker exec -i ftvs3-wp php\" bash tests/lint.sh php"
		failed=1
		return
	fi
	echo "PHP $($PHP -r 'echo PHP_VERSION;')"
	local count=0 file out status
	while IFS= read -r -d '' file; do
		count=$((count + 1))
		out="$($PHP -l < "$file" 2>&1)"
		status=$?
		# Compile-time deprecations and warnings (new in 8.x) count as failures too: this plugin
		# claims to run on every PHP from 7.4 up.
		if [ "$status" -ne 0 ] || printf '%s' "$out" | grep -Eq '(Deprecated|Warning|Fatal error|Parse error):'; then
			echo "FAIL $file"
			printf '%s\n' "$out" | sed 's/^/     /'
			failed=1
		fi
	done < <(find faith-tv-series tests -name '*.php' -print0 | sort -z)
	echo "checked $count PHP files"
}

lint_js() {
	echo "Node $($NODE --version)"
	local count=0 file out
	while IFS= read -r -d '' file; do
		count=$((count + 1))
		if ! out="$($NODE --check "$file" 2>&1)"; then
			echo "FAIL $file"
			printf '%s\n' "$out" | sed 's/^/     /'
			failed=1
		fi
	done < <(find faith-tv-series/assets -name '*.js' -not -path '*/vendor/*' -print0 | sort -z)
	echo "checked $count JavaScript files"
}

case "$what" in
	php) lint_php ;;
	js) lint_js ;;
	all) lint_php; lint_js ;;
	*) echo "usage: bash tests/lint.sh [php|js]" >&2; exit 2 ;;
esac

if [ "$failed" -eq 0 ]; then
	echo "OK"
fi
exit "$failed"
