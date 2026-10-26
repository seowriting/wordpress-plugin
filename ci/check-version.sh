#!/usr/bin/env bash
# Checks that a plugin folder is ready to be released as <version>:
# the version is the same in the plugin header, in SEOWriting::$version and in readme.txt (Stable tag),
# and readme.txt has a changelog entry "= <version> (YYYY/MM/DD) =".
# Usage: check-version.sh <version> <plugin dir, e.g. seowriting>
set -euo pipefail

VERSION=${1:?version, e.g. 1.13.0}
DIR=${2:?plugin dir, e.g. seowriting}

fail() { echo "check-version: $*" >&2; exit 1; }

[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "version must look like 1.13.0, got '$VERSION'"
[ -f "$DIR/seowriting.php" ] || fail "$DIR/seowriting.php not found"
[ -f "$DIR/readme.txt" ] || fail "$DIR/readme.txt not found"

V_RE=${VERSION//./\\.}
grep -Eq "^ \* Version: +${V_RE}[[:space:]]*$" "$DIR/seowriting.php" || fail "plugin header 'Version:' is not $VERSION"
grep -Eq "public \\\$version = '${V_RE}';" "$DIR/seowriting.php" || fail "SEOWriting::\$version is not '$VERSION'"
grep -Eq "^Stable tag: ${V_RE}[[:space:]]*$" "$DIR/readme.txt" || fail "readme.txt 'Stable tag:' is not $VERSION"
grep -Eq "^= ${V_RE} \([0-9]{4}/[0-9]{2}/[0-9]{2}\) =[[:space:]]*$" "$DIR/readme.txt" || fail "readme.txt has no changelog entry '= $VERSION (YYYY/MM/DD) ='"

echo "check-version: $VERSION ok (header, \$version, Stable tag, changelog)"
