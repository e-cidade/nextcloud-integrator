#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Run INSIDE the real vendor PHP 5.6/Apache container after install.sh.
set -euo pipefail
if [[ ${ECIDADE_ERP_DISPOSABLE_CI:-} != 1 || ${DB_HOST:-} != erp-db \
    || ${DB_DATABASE:-} != ecidade || ! -f /.dockerenv ]]; then
  echo 'Refusing to run ERP checks outside a disposable database' >&2
  exit 2
fi
cd /var/www/html
php -r 'exit(strpos(PHP_VERSION, "5.6.") === 0 ? 0 : 1);'
for path in .env libs/db_conn.php config/application.php vendor/autoload.php; do
  test -s "$path" || { echo "Vendor installation did not create $path" >&2; exit 1; }
done

# Query catalog metadata only. No fixture rows or credentials are ever printed.
export PGPASSWORD="$DB_PASSWORD"
psql -X -q -v ON_ERROR_STOP=1 -h "$DB_HOST" -U "$DB_USERNAME" -d ecidade \
  -tA -c "SELECT public.fc_set_pg_search_path()" >/dev/null
encoding="$(psql -X -q -v ON_ERROR_STOP=1 -h "$DB_HOST" -U "$DB_USERNAME" -d postgres \
  -tA -c "SELECT pg_encoding_to_char(encoding) FROM pg_database WHERE datname = 'ecidade'")"
[[ "$encoding" == LATIN1 ]] || { echo 'Vendor database encoding is not LATIN1' >&2; exit 1; }
table_count="$(psql -X -q -v ON_ERROR_STOP=1 -h "$DB_HOST" -U "$DB_USERNAME" -d ecidade \
  -tA -c "SELECT count(*) FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema')")"
[[ "$table_count" =~ ^[0-9]+$ && "$table_count" -ge 100 ]] || {
  echo 'Vendor database restore did not create its municipal schema' >&2
  exit 1
}
protocol_tables="$(psql -X -q -v ON_ERROR_STOP=1 -h "$DB_HOST" -U "$DB_USERNAME" -d ecidade \
  -tA -c "SELECT count(DISTINCT tablename) FROM pg_catalog.pg_tables WHERE tablename IN ('db_usuarios','protprocesso','protprocessodocumento')")"
[[ "$protocol_tables" == 3 ]] || {
  echo 'Protocolo or user tables missing from the real vendor schema' >&2
  exit 1
}

# Establish a baseline for any native large objects in the vendor example.
# This is NOT a proof that the ERP already uses Nextcloud for its documents.
large_objects_before="$(psql -X -q -v ON_ERROR_STOP=1 -h "$DB_HOST" -U "$DB_USERNAME" -d ecidade \
  -tA -c 'SELECT count(*) FROM pg_catalog.pg_largeobject_metadata')"

temp="$(mktemp -d)"
trap 'rm -rf "$temp"' EXIT
home_code="$(curl --silent --show-error --location --max-time 30 \
  --cookie-jar "$temp/cookies" --cookie "$temp/cookies" \
  --output "$temp/home" --write-out '%{http_code}' http://127.0.0.1/index.php)"
[[ "$home_code" == 200 ]] || { echo 'Real ERP index returned non-200 HTTP status' >&2; exit 1; }
grep -Eiq 'usu_login|js_acessar' "$temp/home" || {
  echo 'ERP HTTP response is not the original login page' >&2
  exit 1
}

# The public demo setup documents a dbseller/dbseller account. Exercise the
# genuine PHP session/login flow, not the synthetic RPC token in prior tests.
demo_hash="$(printf dbseller | md5sum | cut -d' ' -f1)"
auth="$(printf 'DB_login=dbseller&DB_senha=%s' "$demo_hash" | base64 -w 0)"
login_code="$(curl --silent --show-error --max-time 30 \
  --get --data-urlencode "sAuth=$auth" \
  --cookie-jar "$temp/cookies" --cookie "$temp/cookies" \
  --output "$temp/login" --write-out '%{http_code}' \
  http://127.0.0.1/abrir.php)"
[[ "$login_code" == 200 ]] || { echo 'Real ERP login request did not return HTTP 200' >&2; exit 1; }
grep -Eiq 'Abrindo E-cidade|inicio.php\?uso=' "$temp/login" || {
  echo 'Real ERP login did not create an authenticated municipal session' >&2
  exit 1
}

after="$(psql -X -q -v ON_ERROR_STOP=1 -h "$DB_HOST" -U "$DB_USERNAME" -d ecidade \
  -tA -c 'SELECT count(*) FROM pg_catalog.pg_largeobject_metadata')"
[[ "$large_objects_before" == "$after" ]] || {
  echo 'Login smoke unexpectedly changed native PostgreSQL large objects' >&2
  exit 1
}
echo "Real DBSeller PHP 5.6/PG16/LATIN1 install, $table_count tables, login HTTP and session: PASS"
echo 'No Protocolo document CRUD or Nextcloud cutover is claimed by this smoke'
