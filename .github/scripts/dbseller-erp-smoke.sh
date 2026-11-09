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
for path in .env libs/db_conn.php libs/db_acessa.php config/application.php config/require_extensions.xml vendor/autoload.php; do
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
# In the original ERP, index.php performs bootstrap/prerequisite checks;
# login.php is the actual original login form. Test both independently.
login_page_code="$(curl --silent --show-error --location --max-time 30 \
  --cookie-jar "$temp/cookies" --cookie "$temp/cookies" \
  --output "$temp/form" --write-out '%{http_code}' http://127.0.0.1/login.php)"
[[ "$login_page_code" == 200 ]] || { echo 'Real ERP login.php returned non-200 HTTP status' >&2; exit 1; }
grep -Eiq 'usu_login|js_acessar' "$temp/form" || {
  echo 'Real ERP login.php did not render its original authentication form' >&2
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
if [[ "$login_code" != 200 ]]; then
  echo "Real ERP authenticated endpoint returned HTTP $login_code instead of 200" >&2
  # Report only the originating PHP filename and line, never the error text.
  if [[ "$login_code" == 500 ]]; then
    for logfile in /var/log/php_errors.log /var/log/apache2/error.log; do
      if [[ -r "$logfile" ]]; then
        location="$(grep -Eo '/var/www/html/[a-zA-Z0-9_./-]+\.php( on line |:)[0-9]+' "$logfile" | tail -n 1 || true)"
        if [[ -n "$location" ]]; then
          echo "ERP PHP source location: $location" >&2
          break
        fi
      fi
    done
  fi
  exit 1
fi
if ! grep -Eiq 'Abrindo E-cidade|inicio.php\?uso=' "$temp/login"; then
  echo 'Real ERP login did not create an authenticated municipal session' >&2
  if grep -Fq 'testaLogin' "$temp/login"; then
    echo 'Original ERP returned a browser-side authentication rejection' >&2
  fi
  if grep -Eiq 'registrasistema|registro do sistema' "$temp/login"; then
    echo 'Original ERP returned its initial-registration flow' >&2
  fi
  # Check counts only; no ERP rows, session values or hashes are printed.
  demo_hash="$(php -r 'echo sha1(md5("dbseller"));')"
  if metrics="$(psql -X -q -v ON_ERROR_STOP=1 -At \
      -v "demo_hash=$demo_hash" -h "$DB_HOST" -U "$DB_USERNAME" -d ecidade <<'SQL'
SELECT public.fc_set_pg_search_path();
SELECT (SELECT COUNT(*) FROM db_usuarios WHERE login = 'dbseller'),
       (SELECT COUNT(*) FROM db_usuarios WHERE login = 'dbseller' AND usuarioativo = 1),
       (SELECT COUNT(*) FROM db_usuarios WHERE login = 'dbseller' AND senha = :'demo_hash'),
       (SELECT COUNT(*) FROM db_depusu);
SQL
  )"; then
    summary="$(printf '%s\n' "$metrics" | tail -n 1)"
    if [[ "$summary" =~ ^[0-9]+\|[0-9]+\|[0-9]+\|[0-9]+$ ]]; then
      echo "Vendor demo fixture counts (user/active/hash-match/departments): $summary" >&2
    else
      echo 'Vendor demo fixture counts unavailable' >&2
    fi
  else
    echo 'Vendor demo authentication fixture query failed' >&2
  fi
  exit 1
fi

# Original login precedes the browser's institution/module selection.
# Select only an existing department assigned to that authenticated demo user.
# This is a test-only window-context fixture, not proof of UI navigation.
context_code="$(curl --silent --show-error --max-time 30 \
  --cookie-jar "$temp/cookies" --cookie "$temp/cookies" \
  --output "$temp/context" --write-out '%{http_code}' \
  http://127.0.0.1/.ci-erp-session-context.php)"
if [[ "$context_code" != 200 ]] || ! grep -Fq 'Authorized vendor Protocolo session context: PASS' "$temp/context"; then
  echo "Original ERP authenticated HTTP module context failed (HTTP $context_code)" >&2
  # Print only hard-coded, non-sensitive fixture failure categories, never
  # the raw HTTP response, SQL, cookies or municipal demonstration records.
  for reason in \
    'Original ERP demo has no Protocolo menu entry for audit' \
    'Original ERP database session initialization failed' \
    'Original ERP audit database context unavailable' \
    'Original ERP menu access audit row could not be recorded'; do
    if grep -Fxq "$reason" "$temp/context"; then
      echo "Original ERP audit context: $reason" >&2
      break
    fi
  done
  exit 1
fi


# Exercise the ORIGINAL Protocolo RPC through the authenticated vendor PHP
# session. An unknown action deliberately performs no document mutation: this
# isolates framework/DB/session compatibility before E2E document cutover.
# This is not a substitute for CRUD against Nextcloud.
rpc_code="$(curl --silent --show-error --max-time 40 \
  --request POST --data-urlencode 'json={"exec":"ecidade_ci_session_probe"}' \
  --cookie-jar "$temp/cookies" --cookie "$temp/cookies" \
  --dump-header "$temp/protocol-headers" \
  --output "$temp/protocol" --write-out '%{http_code}' \
  http://127.0.0.1/prot4_processodocumento.RPC.php)"
if [[ "$rpc_code" != 200 ]] || ! php -r '
  $response = json_decode(file_get_contents($argv[1]), true);
  exit(is_array($response) && isset($response["iStatus"])
    && (int)$response["iStatus"] === 1 ? 0 : 1);
' "$temp/protocol"; then
  echo "Real ERP Protocolo RPC failed its authenticated session probe (HTTP $rpc_code)" >&2
  if [[ "$rpc_code" == 302 || "$rpc_code" == 301 ]]; then
    # Report only the redirect PATH, never its query or session parameters.
    php -r '
      foreach (file($argv[1]) as $line) {
        if (stripos($line, "Location:") !== 0) { continue; }
        $path = parse_url(trim(substr($line, 9)), PHP_URL_PATH);
        if (is_string($path) && preg_match("#^[a-zA-Z0-9_./-]{1,100}$#D", $path)) {
          fwrite(STDERR, "ERP RPC redirect path: " . $path . "\n");
        }
      }
    ' "$temp/protocol-headers"
  fi
  exit 1
fi

after="$(psql -X -q -v ON_ERROR_STOP=1 -h "$DB_HOST" -U "$DB_USERNAME" -d ecidade \
  -tA -c 'SELECT count(*) FROM pg_catalog.pg_largeobject_metadata')"
[[ "$large_objects_before" == "$after" ]] || {
  echo 'Login smoke unexpectedly changed native PostgreSQL large objects' >&2
  exit 1
}
echo "Real DBSeller PHP 5.6/PG16/LATIN1 install, $table_count tables, login HTTP and session: PASS"
echo 'No Protocolo document CRUD or Nextcloud cutover is claimed by this smoke'
