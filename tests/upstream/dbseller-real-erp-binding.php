<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * CI-only binding at the original DBSeller DBLargeObject extension point.
 * This test seam is NOT a production authorization implementation.
 */
if (PHP_SAPI !== 'apache2handler'
    || getenv('ECIDADE_ERP_DISPOSABLE_CI') !== '1'
    || getenv('ECIDADE_ERP_NEXTCLOUD_E2E') !== '1'
    || getenv('DB_HOST') !== 'erp-db'
    || getenv('DB_DATABASE') !== 'ecidade'
    || !is_file('/.dockerenv')
    || !isset($_SERVER['SCRIPT_NAME'])
    || $_SERVER['SCRIPT_NAME'] !== '/prot4_processodocumento.RPC.php'
    || !isset($_SESSION['DB_login'], $_SESSION['DB_id_usuario'], $_SESSION['DB_coddepto'], $_SESSION['DB_instit'])
    || $_SESSION['DB_login'] !== 'dbseller'
    || !ctype_digit((string) $_SESSION['DB_id_usuario'])) {
    throw new RuntimeException('Refusing untrusted ERP document binding');
}

global $conn;
if (!is_resource($conn) || !function_exists('pg_transaction_status')) {
    throw new RuntimeException('Original ERP PostgreSQL handle was not initialized');
}
$user = (int) $_SESSION['DB_id_usuario'];
$department = (int) $_SESSION['DB_coddepto'];
$institution = (int) $_SESSION['DB_instit'];
$authorization = @pg_query_params(
    $conn,
    'SELECT 1 FROM db_usuarios u INNER JOIN db_depusu du ON du.id_usuario=u.id_usuario '
    . 'INNER JOIN db_depart d ON d.coddepto=du.coddepto '
    . 'WHERE u.id_usuario=$1 AND u.login=$2 AND u.usuarioativo=1 '
    . 'AND d.coddepto=$3 AND d.instit=$4 LIMIT 1',
    [$user, 'dbseller', $department, $institution]
);
if (!$authorization || pg_num_rows($authorization) !== 1) {
    throw new RuntimeException('Real ERP user lacks the selected test department');
}

spl_autoload_register(function ($class) {
    $prefix = 'ECidade\\Nextcloud\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $file = '/sdk/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

class CiOriginalDbSellerPolicy implements \ECidade\Nextcloud\Contract\DocumentAccessPolicy
{
    private $actor;
    private $scope;

    public function __construct($actor, \ECidade\Nextcloud\Domain\Scope $scope)
    {
        $this->actor = $actor;
        $this->scope = $scope;
    }

    public function allows(\ECidade\Nextcloud\Domain\AdapterContext $context, $operation, $legacyId)
    {
        return $context->actor() === $this->actor
            && $context->backend() === 'dbseller'
            && $context->surface() === 'large-object-facade'
            && $context->scope()->equals($this->scope)
            && in_array($operation, ['reserve', 'create', 'read', 'update', 'delete', 'inspect'], true);
    }
}

$scope = new \ECidade\Nextcloud\Domain\Scope('installation-a', 'municipio-a');
$remote = new \ECidade\Nextcloud\Transport\NextcloudMvpClient($scope, [
    'url' => 'https://synthetic-nextcloud/ocs/v2.php/apps/ecidadelab/api/v2',
    'user' => 'synthetic-admin',
    'token' => getenv('MVP_SYNTHETIC_PASSWORD'),
    'ca_file' => '/tmp/ecidade-nextcloud-ca.pem',
    'pending_read_key' => getenv('MVP_SYNTHETIC_PENDING_KEY'),
]);
$ledger = new \ECidade\Nextcloud\Persistence\PgDocumentLedger($conn);
$coordinator = new \ECidade\Nextcloud\Application\DocumentCoordinator($ledger, $remote);
$actor = 'erp-demo-' . $user;
$spi = new \ECidade\Nextcloud\Application\AdapterServiceV1(
    $coordinator,
    $ledger,
    new CiOriginalDbSellerPolicy($actor, $scope),
    [
        'id' => 'dbseller',
        'production_ready' => false,
        'storage_capabilities' => [[
            'surface' => 'large-object-facade',
            'usage' => 'persistent-document',
            'spi' => 1,
            'operations' => ['reserve', 'create', 'read', 'update', 'delete', 'inspect'],
        ]],
    ]
);
\ECidade\Nextcloud\LargeObjectBridge::bind(
    $spi,
    new \ECidade\Nextcloud\Domain\AdapterContext(
        $scope,
        'dbseller',
        'large-object-facade',
        $actor
    ),
    $ledger
);
