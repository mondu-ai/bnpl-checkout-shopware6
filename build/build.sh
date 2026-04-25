#!/usr/bin/env bash
# Produce two self-contained plugin ZIPs from this monorepo.
#
# Usage:  build/build.sh <version> [--skip-admin] [--only sw66|sw67]
#
# For each plugin-swXX:
#   1. Copy the thin shell (plugin-swXX/src/) into dist/mondu-payment-swXX-<v>/src/.
#   2. Overlay core/src/ on top (the plugin now owns a physical copy of the
#      shared backend, no composer sub-dependency).
#   3. Overlay admin-shared/services/ into the admin bundle.
#   4. Overlay admin-shared/snippets/ into the admin bundle's sw-order snippet dir.
#   5. Render composer.json from template (substitute __VERSION__).
#   6. Unless --skip-admin: install js deps and build the admin bundle
#      (webpack for 6.6, vite for 6.7). Required for Community-Store releases;
#      skip in local dev when you're going to let Shopware rebuild admin itself.
#   7. Zip the dist folder.
#
# Exit 0 on success. Single-flag --only limits work to one plugin (useful when
# you're iterating on a single version).

set -euo pipefail

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <version> [--skip-admin] [--only sw66|sw67]" >&2
  exit 2
fi

VERSION="$1"
SKIP_ADMIN=0
ONLY=""

shift
while [[ $# -gt 0 ]]; do
  case "$1" in
    --skip-admin) SKIP_ADMIN=1; shift;;
    --only)       ONLY="$2"; shift 2;;
    *)            echo "unknown arg: $1" >&2; exit 2;;
  esac
done

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

mkdir -p dist

build_one() {
  local variant="$1"                      # sw66 | sw67
  local src_dir="plugin-${variant}"
  local out_name="mondu-payment-${variant}-${VERSION}"
  local out_dir="dist/${out_name}"

  [[ -d "$src_dir" ]] || { echo "no such plugin dir: $src_dir" >&2; return 1; }

  echo "== building ${variant} @ ${VERSION}"

  rm -rf "$out_dir"
  mkdir -p "$out_dir"

  # Shell first, then overlay core — so plugin-specific files (services.xml,
  # Mond1SW6.php, SW66/ or SW67/ subnamespace) win against shared ones in
  # case of accidental overlap.
  rsync -a "${src_dir}/src/" "${out_dir}/src/"
  rsync -a --ignore-existing "core/src/" "${out_dir}/src/"
  # core-services.xml lives next to the plugin's services.xml after overlay;
  # that's why the plugin services.xml imports ./core-services.xml.

  # Overlay admin-shared into the Vue2/Vue3 bundle of this plugin.
  #
  # Snippets are materialised in two places:
  #   * src/module/sw-order/snippet/  — picked up by Shopware's file-based
  #     snippet loader (works on both 6.6 and 6.7).
  #   * snippet/                      — only SW6.6's main.js does
  #     `import '../snippet/xx-XX.json'` + `Shopware.Locale.extend(...)`,
  #     which the webpack-based admin build requires at bundle time. SW6.7
  #     (Vite) doesn't reference these — overlay is cheap and harmless, so
  #     we do it unconditionally.
  local admin_root="${out_dir}/src/Resources/app/administration"
  if [[ -d "$admin_root" ]]; then
    mkdir -p "${admin_root}/src/services"
    mkdir -p "${admin_root}/src/module/sw-order/snippet"
    mkdir -p "${admin_root}/snippet"
    rsync -a admin-shared/services/   "${admin_root}/src/services/"
    rsync -a admin-shared/snippets/   "${admin_root}/src/module/sw-order/snippet/"
    rsync -a admin-shared/snippets/   "${admin_root}/snippet/"
  fi

  # composer.json with substituted version
  sed "s/__VERSION__/${VERSION}/g" "${src_dir}/composer.json.template" \
    > "${out_dir}/composer.json"

  # Drop files we don't want shipped
  find "$out_dir" -name 'node_modules' -type d -prune -exec rm -rf {} +
  find "$out_dir" -name '.DS_Store'     -type f -delete 2>/dev/null || true

  # Admin bundle
  if [[ "$SKIP_ADMIN" -ne 1 && -d "$admin_root" ]]; then
    if [[ "$variant" == "sw67" ]]; then
      if [[ -f "${admin_root}/package.json" ]]; then
        echo "   running vite build (sw67)"
        ( cd "$admin_root" && npm ci --no-audit --no-fund && npm run build )
      fi
    else
      # sw66 relies on Shopware's bin/build-administration which runs
      # the central webpack pipeline on the merchant side. For prebuilt
      # Community-Store release, either run the SW6.6 build-administration
      # against a throwaway Shopware checkout here, or ship the source as-is
      # and let bin/build-administration run at install time. We default to
      # ship-as-source for sw66 because many merchants still customize admin.
      echo "   sw66: shipping admin as source (Shopware will build on install)"
    fi
  fi

  ( cd dist && zip -qr "${out_name}.zip" "${out_name}" )
  echo "   wrote dist/${out_name}.zip  ($(du -h "dist/${out_name}.zip" | cut -f1))"
}

variants=(sw66 sw67)
if [[ -n "$ONLY" ]]; then
  variants=("$ONLY")
fi

for v in "${variants[@]}"; do
  build_one "$v"
done

echo "== done"
ls -la dist/*.zip 2>&1 | grep "${VERSION}"
