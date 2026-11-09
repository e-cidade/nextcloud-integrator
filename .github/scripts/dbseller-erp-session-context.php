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

// The vendor's normal menu opener calls db_logs(): it creates a real
// db_logsacessa audit row and stores its codsequen as DB_acessado. The
// generated document DAO needs this foreign key when auditing an INSERT.
// This disposable fixture selects a real item of the verified original
// Protocolo module, and writes the exact vendor audit-record shape without
// claiming that a browser menu page was visited or disabling the audit.
$menuItems = @pg_query_params(
    $connection,
    'SELECT i.id_item FROM db_itensmenu i '
    . 'INNER JOIN db_menu m ON m.id_item_filho=i.id_item '
    . "WHERE m.modulo=$1 AND trim(i.funcao)<>'' ORDER BY i.id_item LIMIT 1",
    array((int) $module['id_item'])
);
$menu = $menuItems ? pg_fetch_assoc($menuItems) : false;
if (!$menu) {
    http_response_code(500);
    exit('Original ERP demo has no Protocolo menu entry for audit');
}

// Like the real db_savesession(), initialize the backend database session
// before issuing the original audit-table INSERT.
if (!@pg_query($connection, 'SELECT fc_startsession()')) {
    http_response_code(500);
    exit('Original ERP database session initialization failed');
}
$databaseContext = array(
    'DB_LOGIN' => 'dbseller',
    'DB_ID_USUARIO' => (string) $_SESSION['DB_id_usuario'],
    'DB_CODDEPTO' => (string) $department['coddepto'],
    'DB_INSTIT' => (string) $department['instit'],
    'DB_ANOUSU' => date('Y'),
    'DB_DATAUSU' => date('Y-m-d'),
);
foreach ($databaseContext as $key => $value) {
    if (!@pg_query_params($connection, 'SELECT fc_putsession($1,$2)', array($key, $value))) {
        http_response_code(500);
        exit('Original ERP audit database context unavailable');
    }
}
// The original demo has a twelfth 'auditoria' column with a default,
// whereas legacy db_logs() inserts the first eleven positional columns.
// Name the columns to avoid shifting/omitting the default audit flag.
// The BEFORE INSERT partition trigger returns NULL, so INSERT RETURNING
// has no result row; reserve the sequence first and verify the child row.
$sequence = @pg_query($connection, "SELECT nextval('db_logsacessa_codsequen_seq')");
$accessId = $sequence ? pg_fetch_result($sequence, 0, 0) : false;
if ($accessId === false || (int) $accessId <= 0) {
    http_response_code(500);
    exit('Original ERP audit access sequence unavailable');
}
$access = @pg_query_params(
    $connection,
    'INSERT INTO db_logsacessa '
    . '(codsequen,ip,data,hora,arquivo,obs,id_usuario,id_modulo,id_item,coddepto,instit) '
    . 'VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11)',
    array(
        (int) $accessId,
        $_SERVER['REMOTE_ADDR'],
        date('Y-m-d'),
        date('H:i:s'),
        $_SERVER['REQUEST_URI'],
        'CI authenticated Protocolo module selection',
        (int) $_SESSION['DB_id_usuario'],
        (int) $module['id_item'],
        (int) $menu['id_item'],
        (int) $department['coddepto'],
        (int) $department['instit'],
    )
);
if (!$access) {
    http_response_code(500);
    exit('Original ERP menu access audit row could not be recorded');
}
$verifiedAudit = @pg_query_params(
    $connection,
    'SELECT 1 FROM db_logsacessa '
    . 'WHERE codsequen=$1 AND id_usuario=$2 AND id_modulo=$3 '
    . 'AND id_item=$4 AND coddepto=$5 AND instit=$6 LIMIT 1',
    array(
        (int) $accessId,
        (int) $_SESSION['DB_id_usuario'],
        (int) $module['id_item'],
        (int) $menu['id_item'],
        (int) $department['coddepto'],
        (int) $department['instit'],
    )
);
if (!$verifiedAudit || pg_num_rows($verifiedAudit) !== 1) {
    http_response_code(500);
    exit('Original ERP audit partition did not persist access record');
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
$_SESSION['DB_itemmenu_acessado'] = (int) $menu['id_item'];
$_SESSION['DB_acessado'] = (int) $accessId;
session_write_close();
pg_close($connection);
header('Content-Type: text/plain');
echo "Authorized vendor Protocolo session context: PASS\n";
