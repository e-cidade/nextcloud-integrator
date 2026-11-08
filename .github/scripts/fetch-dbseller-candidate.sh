#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Read only three source files from the exact public upstream Git commit.
# Do not check out a mutable ref, trust arbitrary local paths, or repin manifests.
set -euo pipefail

if [ "$#" -ne 2 ] || [ -z "${GITHUB_ENV:-}" ]; then
  echo "Usage: fetch-dbseller-candidate.sh <40-char-sha> <disposable-directory>" >&2
  exit 2
fi
sha="$1"
root="$2"
pr="${DBSELLER_CANDIDATE_PR:-}"
if [ -n "$pr" ] && ! [[ "$pr" =~ ^[1-9][0-9]*$ ]]; then
  echo "Invalid GitHub pull request number" >&2
  exit 2
fi
if ! [[ "$sha" =~ ^[a-f0-9]{40}$ ]]; then
  echo "Refusing non-immutable DBSeller candidate ref" >&2
  exit 2
fi
if [ -e "$root/.git" ]; then
  echo "Refusing a reused DBSeller candidate checkout" >&2
  exit 2
fi
mkdir -p "$root"
git -C "$root" init -q
git -C "$root" remote add origin https://github.com/e-cidade/e-cidade-DBSeller.git
git -C "$root" sparse-checkout init --no-cone
git -C "$root" sparse-checkout set --no-cone \
  /ecidade/std/DBLargeObject.php \
  /ecidade/model/protocolo/ProcessoDocumento.model.php \
  /ecidade/classes/db_protprocessodocumento_classe.php
if [ -n "$pr" ]; then
  # This supports external fork PRs via GitHub's immutable pull request head
  # ref, but never trusts the ref without checking its exact commit SHA.
  git -C "$root" fetch --depth 1 --filter=blob:none origin "refs/pull/$pr/head"
else
  git -C "$root" fetch --depth 1 --filter=blob:none origin "$sha"
fi
test "$(git -C "$root" rev-parse FETCH_HEAD)" = "$sha" || {
  echo "Upstream pull request changed during checkout" >&2
  exit 1
}
git -C "$root" checkout --quiet --detach FETCH_HEAD
test "$(git -C "$root" rev-parse HEAD)" = "$sha"

for path in \
  ecidade/std/DBLargeObject.php \
  ecidade/model/protocolo/ProcessoDocumento.model.php \
  ecidade/classes/db_protprocessodocumento_classe.php; do
  test -f "$root/$path" && test ! -L "$root/$path" || {
    echo "Missing or symlinked upstream PHP source" >&2
    exit 1
  }
  expected="$(git -C "$root" rev-parse "HEAD:$path")"
  actual="$(git hash-object "$root/$path")"
  test "$expected" = "$actual" || {
    echo "Upstream source does not match the tested Git revision" >&2
    exit 1
  }
done
model="$(git -C "$root" rev-parse HEAD:ecidade/model/protocolo/ProcessoDocumento.model.php)"
dao="$(git -C "$root" rev-parse HEAD:ecidade/classes/db_protprocessodocumento_classe.php)"
variant=fixed
if [ "$model" = 4935e0514c8b206853f071d60612647503cedb99 ]; then
  # The historically pinned bug remains a CHARACTERIZATION, not an approval.
  variant=legacy
fi
{
  echo "DBSELLER_CANDIDATE_SHA=$sha"
  echo "DBSELLER_CANDIDATE_VARIANT=$variant"
  echo "ECIDADE_DBSELLER_CANDIDATE_MODEL_BLOB=$model"
  echo "ECIDADE_DBSELLER_CANDIDATE_DAO_BLOB=$dao"
} >> "$GITHUB_ENV"
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
  {
    echo "### DBSeller upstream revision"
    echo "Commit: \`$sha\`"
    echo "Original model/DAO integrity: verified against Git tree"
    echo "Regression expectation: \`$variant\`"
    echo "Coverage: Protocolo DAO/SPI and synthetic Nextcloud integration; not full ERP HTTP"
  } >> "$GITHUB_STEP_SUMMARY"
fi
echo "DBSeller upstream source attested: $sha ($variant)"
