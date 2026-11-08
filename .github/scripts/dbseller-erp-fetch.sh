#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Fetch the ENTIRE upstream ERP for its documented installer. An unrelated
# sparse checkout is insufficient for a real framework/bootstrap test.
set -euo pipefail

if [[ ${ECIDADE_ERP_DISPOSABLE_CI:-} != 1 || ${GITHUB_ACTIONS:-} != true ]]; then
  echo 'This installer checkout is restricted to disposable GitHub Actions' >&2
  exit 2
fi
sha="${1:?40-character source SHA required}"
pr="${2:-}"
if ! [[ "$sha" =~ ^[a-f0-9]{40}$ ]]; then
  echo 'Refusing mutable or malformed DBSeller revision' >&2
  exit 2
fi
if [[ -n "$pr" ]] && ! [[ "$pr" =~ ^[1-9][0-9]*$ ]]; then
  echo 'Invalid upstream pull request number' >&2
  exit 2
fi
root="$GITHUB_WORKSPACE/.build/dbseller-erp"
if [[ -e "$root/.git" || -e "$root/ecidade" ]]; then
  echo 'Refusing to reuse previous ERP working tree' >&2
  exit 2
fi
mkdir -p "$root"
git -C "$root" init -q
git -C "$root" remote add origin https://github.com/e-cidade/e-cidade-DBSeller.git
if [[ -n "$pr" ]]; then
  git -C "$root" fetch --depth 1 --filter=blob:none origin "refs/pull/$pr/head"
else
  git -C "$root" fetch --depth 1 --filter=blob:none origin "$sha"
fi
[[ "$(git -C "$root" rev-parse FETCH_HEAD)" == "$sha" ]] || {
  echo 'Candidate changed between workflow trigger and checkout' >&2
  exit 1
}
git -C "$root" -c advice.detachedHead=false checkout -q --detach FETCH_HEAD
[[ "$(git -C "$root" rev-parse HEAD)" == "$sha" ]]

for path in docker/database/Dockerfile docker/php56-apache/Dockerfile \
  ecidade/docker/install.sh ecidade/docker/init.sql ecidade/docker/pos.sql \
  ecidade/docker/ecidade_base.sql.gz ecidade/composer.lock \
  ecidade/FrontController.php ecidade/prot4_processodocumento.RPC.php; do
  if [[ ! -s "$root/$path" || -L "$root/$path" ]]; then
    echo "Required upstream installation asset missing: $path" >&2
    exit 1
  fi
  [[ "$(git -C "$root" rev-parse "HEAD:$path")" == "$(git hash-object "$root/$path")" ]] || {
    echo "Upstream installation asset does not match Git tree: $path" >&2
    exit 1
  }
done
gzip -t "$root/ecidade/docker/ecidade_base.sql.gz"
echo "DBSELLER_ERP_ROOT=$root" >> "$GITHUB_ENV"
echo "DBSeller ERP source and vendor database are verified at $sha"
