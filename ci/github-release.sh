#!/usr/bin/env bash
# Publishes a plugin version on GitHub (public repository seowriting/wordpress-plugin): pushes main and the version tag
# from GitLab, creates a release with seowriting.<version>.zip attached (as release.sh) and the changelog of the version.
# The site builds its own /download/wp-seowriting.zip from GitHub (button "WordPress plugin: Update" in the admin).
#
# Usage (from the repository root, HEAD = the tagged commit): github-release.sh <version> [--commit]
#   without --commit: dry run - checks and builds the zip, prints what would be pushed, nothing is sent
# Env (GitLab CI masked + protected variable): GITHUB_TOKEN - fine-grained token of the repository, Contents: read and write.
# Optional: GITHUB_REPO (default seowriting/wordpress-plugin). Needs: git with full history (GIT_DEPTH 0), zip, curl, jq.
set -euo pipefail

VERSION=${1:?version, e.g. 1.13.0}
MODE=${2:-dry-run}
REPO=${GITHUB_REPO:-seowriting/wordpress-plugin}
ZIP="seowriting.$VERSION.zip"

fail() { echo "github-release: $*" >&2; exit 1; }
here=$(cd "$(dirname "$0")" && pwd)

"$here/check-version.sh" "$VERSION" seowriting
TAG_SHA=$(git rev-parse "refs/tags/$VERSION^{commit}" 2>/dev/null) || fail "tag $VERSION not found in this checkout"
[ "$TAG_SHA" = "$(git rev-parse HEAD)" ] || fail "HEAD is not the commit of tag $VERSION"

# the changelog section of this version from readme.txt: lines after "= <version> (date) =" up to the next "= x.y.z"
NOTES=$(awk -v v="$VERSION" '
    $0 ~ "^= " v " \\(" {on=1; next}
    on && /^= [0-9]+\.[0-9]+\.[0-9]+/ {exit}
    on {print}' seowriting/readme.txt | sed '/./,$!d')
[ -n "$NOTES" ] || fail "empty changelog for $VERSION in readme.txt"

rm -f "$ZIP"
zip -rq "$ZIP" seowriting -x '*.DS_Store'
echo "== $ZIP: $(wc -c < "$ZIP") bytes"

AUTH_HEADER=""
if [ -n "${GITHUB_TOKEN:-}" ]; then
    AUTH_HEADER="Authorization: Basic $(printf 'x-access-token:%s' "$GITHUB_TOKEN" | base64 | tr -d '\n')"
fi
GH_URL="https://github.com/$REPO.git"

# GitHub main must be an ancestor of the tag: we only fast-forward, never force
GH_MAIN=$(git -c http.extraheader="$AUTH_HEADER" ls-remote "$GH_URL" refs/heads/main | awk '{print $1}')
if [ -n "$GH_MAIN" ] && ! git merge-base --is-ancestor "$GH_MAIN" "$TAG_SHA" 2>/dev/null; then
    fail "GitHub main ($GH_MAIN) is not in the history of $VERSION: somebody pushed to GitHub directly, sort it out by hand"
fi
if git -c http.extraheader="$AUTH_HEADER" ls-remote --exit-code "$GH_URL" "refs/tags/$VERSION" >/dev/null 2>&1; then
    fail "tag $VERSION already exists on GitHub"
fi

echo "== would push $TAG_SHA to $REPO main (now ${GH_MAIN:-empty}) and tag $VERSION, then create release $VERSION with $ZIP"
echo "$NOTES"
if [ "$MODE" != "--commit" ]; then
    echo "== dry run: nothing was sent"
    exit 0
fi
: "${GITHUB_TOKEN:?GITHUB_TOKEN is not set}"

git -c http.extraheader="$AUTH_HEADER" push "$GH_URL" "$TAG_SHA:refs/heads/main" "refs/tags/$VERSION:refs/tags/$VERSION"

API="https://api.github.com/repos/$REPO"
H=(-H "Authorization: Bearer $GITHUB_TOKEN" -H "Accept: application/vnd.github+json" -H "X-GitHub-Api-Version: 2022-11-28")
BODY=$(jq -n --arg tag "$VERSION" --arg notes "$NOTES" '{tag_name: $tag, name: $tag, body: $notes, draft: false, prerelease: false}')
RELEASE=$(curl -sS --fail-with-body "${H[@]}" -X POST "$API/releases" -d "$BODY")
RELEASE_ID=$(printf '%s' "$RELEASE" | jq -r '.id')
[ "$RELEASE_ID" != "null" ] || fail "release not created: $(printf '%s' "$RELEASE" | jq -r '.message')"

curl -sS --fail-with-body "${H[@]}" -H "Content-Type: application/zip" -X POST \
    "https://uploads.github.com/repos/$REPO/releases/$RELEASE_ID/assets?name=$ZIP" --data-binary "@$ZIP" | jq -r '.browser_download_url'
echo "github-release: $VERSION published on GitHub"
