#!/usr/bin/env bash
# Uploads a plugin version to the WordPress.org catalog (SVN of the plugin "seowriting"): the folder goes to trunk,
# trunk is copied to tags/<version>. The steps of wp-plugin/svn-backup readme.md, plus checks and a watchdog.
#
# Usage: wporg-release.sh <version> <plugin dir> [--commit]
#   without --commit: dry run - prepares the working copy and prints what would change, nothing is sent
# Env (GitLab CI masked + protected variables, never in the repository or the job log):
#   WPORG_SVN_USER      - WordPress.org account with commit access to the plugin
#   WPORG_SVN_PASSWORD  - its SVN password (WordPress.org profile -> Account & Security -> SVN password)
# Needs: svn (Subversion >= 1.10 for --password-from-stdin), rsync, timeout.
set -euo pipefail

VERSION=${1:?version, e.g. 1.13.0}
SRC=$(cd "${2:?plugin dir}" && pwd)
MODE=${3:-dry-run}
SVN_URL=${WPORG_SVN_URL:-https://plugins.svn.wordpress.org/seowriting}
# the commit to WordPress.org has hung before without an error (Vova, 12.10.2026): stop it after this many seconds
COMMIT_TIMEOUT=${WPORG_COMMIT_TIMEOUT:-900}

fail() { echo "wporg-release: $*" >&2; exit 1; }
here=$(cd "$(dirname "$0")" && pwd)

"$here/check-version.sh" "$VERSION" "$SRC"

if svn ls "$SVN_URL/tags/$VERSION/" --non-interactive >/dev/null 2>&1; then
    fail "tags/$VERSION already exists on WordPress.org: a released version is never overwritten, make the next version"
fi

WC=$(mktemp -d)
trap 'rm -rf "$WC"' EXIT
echo "== checkout $SVN_URL (trunk; tags and assets are not downloaded)"
svn checkout --depth immediates --non-interactive "$SVN_URL" "$WC" >/dev/null
svn update --set-depth infinity --non-interactive "$WC/trunk" >/dev/null

echo "== trunk <- $SRC"
rsync -a --delete --exclude '.svn' --exclude '.DS_Store' "$SRC/" "$WC/trunk/"
cd "$WC"
# new files -> svn add, files gone from the folder -> svn rm
svn status trunk | awk '/^\?/ {sub(/^\?[ ]+/, ""); print}' | while IFS= read -r f; do svn add --parents --quiet "$f"; done
svn status trunk | awk '/^!/ {sub(/^![ ]+/, ""); print}' | while IFS= read -r f; do svn rm --quiet "$f"; done

echo "== tags/$VERSION <- trunk"
svn copy --quiet trunk "tags/$VERSION"

echo "== changes (svn status)"
svn status
STABLE=$(grep -E '^Stable tag:' trunk/readme.txt | awk '{print $3}')
[ "$STABLE" = "$VERSION" ] || fail "trunk readme.txt Stable tag is '$STABLE', not $VERSION"

if [ "$MODE" != "--commit" ]; then
    echo "== dry run: nothing was sent. Run with --commit to upload $VERSION."
    exit 0
fi

: "${WPORG_SVN_USER:?WPORG_SVN_USER is not set}"
: "${WPORG_SVN_PASSWORD:?WPORG_SVN_PASSWORD is not set}"
echo "== commit to WordPress.org (stopped after ${COMMIT_TIMEOUT}s if it hangs)"
set +e
printf '%s' "$WPORG_SVN_PASSWORD" | timeout "$COMMIT_TIMEOUT" svn commit -m "New version of plugin: $VERSION" \
    --username "$WPORG_SVN_USER" --password-from-stdin --non-interactive --no-auth-cache
RC=$?
set -e

echo "== check what WordPress.org has"
if svn ls "$SVN_URL/tags/$VERSION/seowriting.php" --non-interactive >/dev/null 2>&1 \
    && svn cat "$SVN_URL/trunk/readme.txt" --non-interactive | grep -Eq "^Stable tag: ${VERSION//./\\.}[[:space:]]*$"; then
    echo "wporg-release: $VERSION is on WordPress.org (tags/$VERSION, trunk Stable tag). The catalog page updates in a few minutes."
    exit 0
fi
[ "$RC" -eq 124 ] && fail "the commit hung and was stopped after ${COMMIT_TIMEOUT}s; nothing is on WordPress.org yet - run the job again"
fail "svn commit exit code $RC; tags/$VERSION is not on WordPress.org"
