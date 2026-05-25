#!/usr/bin/env bash
# Promote a built plugin from this monorepo to its flat release branch.
#
# Usage:  build/release-to-flat.sh <version> <variant> [--skip-admin] [--push] [--tag <name>]
#
#   <version>        e.g. 1.5.1, 2.2.0
#   <variant>        sw66 | sw67
#   --skip-admin     forwarded to build/build.sh (sw67 normally needs the admin build,
#                    sw66 ships admin as source either way)
#   --push           push the release branch + tag to origin (default: dry-run, leaves
#                    the worktree in place for inspection)
#   --tag <name>     custom tag name (default: <version>-<variant>)
#
# Strategy:
#   - main-monorepo is the source of truth (this repo, this branch).
#   - main-v6.6 / main-v6.7 are *flat release branches* — they look like a
#     standalone Shopware plugin (composer.json + src/ at the root). Existing
#     install pipelines (e.g. scripts/02b_SW6_module.sh's
#     `composer require mondu/shopware6-payment`) target these branches and
#     don't need to know that a monorepo exists upstream.
#   - This script bridges the two: build flat, replace src/+composer.json on
#     the release branch, commit with a back-pointer to the monorepo SHA,
#     optionally tag and push.
#
# Why we rewrite the package name:
#   plugin-sw66/composer.json.template ships as "mondu/shopware66-payment" and
#   plugin-sw67 as "mondu/shopware67-payment" so composer can tell them apart
#   when both are exposed via path-repos during monorepo development. The flat
#   release branches have always shipped under "mondu/shopware6-payment", and
#   that's the name 02b_SW6_module.sh + the Community Store know about — so we
#   rewrite it back at release time. Internal split, external stable.

set -euo pipefail

if [[ $# -lt 2 ]]; then
  echo "Usage: $0 <version> <variant> [--skip-admin] [--push] [--tag <name>]" >&2
  exit 2
fi

VERSION="$1"
VARIANT="$2"
shift 2

SKIP_ADMIN=0
PUSH=0
TAG=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --skip-admin) SKIP_ADMIN=1; shift;;
    --push)       PUSH=1; shift;;
    --tag)        TAG="$2"; shift 2;;
    *) echo "unknown arg: $1" >&2; exit 2;;
  esac
done

case "$VARIANT" in
  sw66) RELEASE_BRANCH="main-monorepo-v6.6"; SW_VER="6.6";;
  sw67) RELEASE_BRANCH="main-monorepo-v6.7"; SW_VER="6.7";;
  *) echo "variant must be sw66 or sw67" >&2; exit 2;;
esac

[[ -n "$TAG" ]] || TAG="${VERSION}-${VARIANT}"

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

SOURCE_SHA="$(git rev-parse HEAD)"
SOURCE_BRANCH="$(git rev-parse --abbrev-ref HEAD)"

echo "== building flat plugin: ${VARIANT} ${VERSION}"
build_args=("$VERSION" "--only" "$VARIANT")
[[ $SKIP_ADMIN -eq 1 ]] && build_args+=("--skip-admin")
./build/build.sh "${build_args[@]}"

DIST_DIR="dist/mondu-payment-${VARIANT}-${VERSION}"
[[ -d "$DIST_DIR" ]] || { echo "build did not produce $DIST_DIR" >&2; exit 1; }

# Rewrite package name back to legacy (see header for rationale).
# `sed -i.bak` works on both BSD (macOS) and GNU sed.
sed -i.bak \
  's|"mondu/shopware'"${VARIANT#sw}"'-payment"|"mondu/shopware6-payment"|' \
  "$DIST_DIR/composer.json"
rm -f "$DIST_DIR/composer.json.bak"

echo "== preparing release worktree on ${RELEASE_BRANCH}"
git fetch origin "$RELEASE_BRANCH"

WORK_DIR="$(mktemp -d -t "release-${VARIANT}-XXXXXX")/wt"
mkdir -p "$(dirname "$WORK_DIR")"
git worktree add "$WORK_DIR" "$RELEASE_BRANCH"

cleanup() {
  cd "$ROOT"
  git worktree remove --force "$WORK_DIR" 2>/dev/null || true
}
trap cleanup EXIT

# Replace only generated artefacts on the release branch.
# Anything else there (LICENSE.txt, README.md, .github/, install.sh, etc.) is
# infrastructure for merchants and CI, owned by the release branch — don't
# touch it. If the monorepo grows new top-level outputs, list them here.
( cd "$WORK_DIR" && rm -rf src composer.json )
rsync -a --exclude=.git/ "$DIST_DIR/" "$WORK_DIR/"

cd "$WORK_DIR"
git add -A

if git diff --cached --quiet; then
  echo "nothing to release: ${RELEASE_BRANCH} already matches the build"
  exit 0
fi

git commit -m "Release ${VERSION} for Shopware ${SW_VER}

Built from monorepo: ${SOURCE_BRANCH} @ ${SOURCE_SHA}"

if [[ $PUSH -eq 1 ]]; then
  git push origin "$RELEASE_BRANCH"
  git tag "$TAG"
  git push origin "$TAG"
  echo "== pushed ${RELEASE_BRANCH} and tag ${TAG}"
else
  trap - EXIT
  echo
  echo "== DRY RUN — release commit prepared in worktree:"
  echo "   ${WORK_DIR}"
  echo "   to inspect: git -C '${WORK_DIR}' show"
  echo "   to publish: git -C '${WORK_DIR}' push origin ${RELEASE_BRANCH}"
  echo "              && git -C '${WORK_DIR}' tag ${TAG}"
  echo "              && git -C '${WORK_DIR}' push origin ${TAG}"
  echo "   when done: git worktree remove --force '${WORK_DIR}'"
fi
