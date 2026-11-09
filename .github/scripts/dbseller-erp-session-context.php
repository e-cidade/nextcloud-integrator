<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Select a real authorized vendor department after the ORIGINAL HTTP login.
 *
 * Only called through Apache's normal FrontController/session lifecycle in
 * disposable GitHub Actions. This is NOT a login or authentication shim:
 * it refuses anonymous sessions, foreign departments and remote callers.
 * This test-only fixture does not claim to exercise browser menu navigation.
 */
if (PHP_SAPI !== 'apache2handler'
    || getenv('ECIDADE_ERP_DISPOSABLE_CI') !== '1'
    || getenv('DB_HOST') !== 'erp-db' || getenv('DB_DATABASE') !== 'ecidade'
    || !is_file('/.dockerenv')
    || !isset($_SERVER['SCRIPT_NAME'], $_SERVER['REMOTE_ADDR'])
    || $_SERVER['SCRIPT_NAME'] !== '/.ci-erp-session-context.php'
    || $_SERVER['REMOTE_ADDR'] !== '127.0.0.1') {
    http_response_code(403);
    exit('Disposable ERP HTTP session context denied');
}

// The vendor's app.php has already selected its original window session and
// closed it before dispatching this PHP page. Reopen that same PHP session.
if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
    http_response_code(401);
    exit('Original ERP HTTP session could not be opened');
}
if (!isset($_SESSION['DB_login'], $_SESSION['DB_id_usuario'])
    || $_SESSION['DB_login'] !== 'dbseller'
    || !ctype_digit((string) $_SESSION['DB_id_usuario'])) {
    http_response_code(401);
    exit('Original ERP HTTP session lacks authenticated demo identity');
}

$connection = @pg_connect(
    'host=' . getenv('DB_HOST') . ' port=' . getenv('DB_PORT')
    . ' dbname=' . getenv('DB_DATABASE')
    . ' user=' . getenv('DB_USERNAME') . ' password=' . getenv('DB_PASSWORD')
);
if (!$connection || !@pg_query($connection, 'SELECT public.fc_set_pg_search_path()')) {
    http_response_code(500);
    exit('Original ERP demo database session is unavailable');
}

$departments = @pg_query_params(
    $connection,
    'SELECT dep.coddepto, dep.instit FROM db_depusu du '
    . 'INNER JOIN db_depart dep ON dep.coddepto=du.coddepto '
    . 'INNER JOIN db_config cfg ON cfg.codigo=dep.instit '
    . 'WHERE du.id_usuario=$1 ORDER BY dep.coddepto LIMIT 1',
    array((int) $_SESSION['DB_id_usuario'])
);
$department = $departments ? pg_fetch_assoc($departments) : false;
if (!$department) {
    http_response_code(403);
    exit('Demo user has no existing authorized ERP department/institution');
}

$modules = @pg_query(
    $connection,
    "SELECT id_item, nome_modulo FROM db_modulos "
    . "WHERE lower(nome_modulo) LIKE '%protocolo%' "
    . "OR lower(descr_modulo) LIKE '%protocolo%' ORDER BY id_item LIMIT 1"
);
$module = $modules ? pg_fetch_assoc($modules) : false;
if (!$module) {
    http_response_code(500);
    exit('Original ERP demo has no Protocolo module');
}

// The real UI normally selects institution/module/year/department. Change
// ONLY those window context attributes after verifying the existing identity.
$_SESSION['DB_coddepto'] = (int) $department['coddepto'];
$_SESSION['DB_instit'] = (int) $department['instit'];
$_SESSION['DB_modulo'] = (int) $module['id_item'];
$_SESSION['DB_nome_modulo'] = $module['nome_modulo'];
$_SESSION['DB_anousu'] = (int) date('Y');
$_SESSION['DB_datausu'] = time();
$_SESSION['DB_uol_hora'] = time();
$_SESSION['DB_itemmenu_acessado'] = 0;
session_write_close();
pg_close($connection);
header('Content-Type: text/plain');
echo "Authorized vendor Protocolo session context: PASS\n";
