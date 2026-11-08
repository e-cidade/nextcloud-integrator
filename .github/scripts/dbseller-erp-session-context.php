<?php
// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Set the browser's post-login module/window context for the vendor demo.
 *
 * The real abrir.php login/session has already run in Apache. This fixture
 * selects ONLY a department already assigned to that authenticated user.
 * It is deliberately CLI-only and does not alter original ERP source.
 * It does not claim to test the browser's module-selection UI.
 */
if (PHP_SAPI !== 'cli' || getenv('ECIDADE_ERP_DISPOSABLE_CI') !== '1'
    || getenv('DB_HOST') !== 'erp-db' || getenv('DB_DATABASE') !== 'ecidade'
    || !is_file('/.dockerenv') || !isset($argv[1]) || !is_file($argv[1])) {
    fwrite(STDERR, "Refusing ERP session fixture outside disposable vendor demo\n");
    exit(2);
}

$sessionId = null;
foreach (file($argv[1], FILE_IGNORE_NEW_LINES) as $line) {
    // Netscape cookie files prefix HttpOnly session cookies with a '#'
    // comment marker. Such records are still active authentication cookies.
    if (strpos($line, '#HttpOnly_') === 0) {
        $line = substr($line, strlen('#HttpOnly_'));
    } elseif ($line === '' || strpos($line, '#') === 0) {
        continue;
    }
    $fields = explode("\t", $line);
    if (count($fields) === 7 && $fields[5] === 'PHPSESSID'
        && preg_match('/^[a-zA-Z0-9,-]{16,128}$/D', $fields[6])) {
        $sessionId = $fields[6];
    }
}
if ($sessionId === null) {
    fwrite(STDERR, "Original ERP HTTP login did not return a PHP session cookie\n");
    exit(1);
}

session_id($sessionId);
if (!session_start() || !isset($_SESSION['DB_login'], $_SESSION['DB_id_usuario'])
    || $_SESSION['DB_login'] !== 'dbseller'
    || !ctype_digit((string) $_SESSION['DB_id_usuario'])) {
    fwrite(STDERR, "Original ERP session is not authenticated as the vendor demo user\n");
    exit(1);
}

$connection = @pg_connect(
    'host=' . getenv('DB_HOST') . ' port=' . getenv('DB_PORT')
    . ' dbname=' . getenv('DB_DATABASE')
    . ' user=' . getenv('DB_USERNAME') . ' password=' . getenv('DB_PASSWORD')
);
if (!$connection || !@pg_query($connection, 'SELECT public.fc_set_pg_search_path()')) {
    fwrite(STDERR, "Original ERP demo database session is unavailable\n");
    exit(1);
}

$departments = @pg_query_params($connection,
    'SELECT dep.coddepto, dep.instit FROM db_depusu du '
    . 'INNER JOIN db_depart dep ON dep.coddepto=du.coddepto '
    . 'INNER JOIN db_config cfg ON cfg.codigo=dep.instit '
    . 'WHERE du.id_usuario=$1 ORDER BY dep.coddepto LIMIT 1',
    array((int) $_SESSION['DB_id_usuario'])
);
$department = $departments ? pg_fetch_assoc($departments) : false;
if (!$department) {
    fwrite(STDERR, "Demo user has no existing authorized ERP department/institution\n");
    exit(1);
}

$modules = @pg_query($connection,
    "SELECT id_item, nome_modulo FROM db_modulos "
    . "WHERE lower(nome_modulo) LIKE '%protocolo%' "
    . "OR lower(descr_modulo) LIKE '%protocolo%' ORDER BY id_item LIMIT 1"
);
$module = $modules ? pg_fetch_assoc($modules) : false;
if (!$module) {
    fwrite(STDERR, "Original ERP demo has no Protocolo module\n");
    exit(1);
}

// Original UI normally sets these after selecting institution, module, year
// and department. No identity, department or institution is invented here.
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
echo "Authenticated ERP PHP session selected an existing authorized demo department\n";
