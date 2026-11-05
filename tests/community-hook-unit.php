<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/**
 * Tests community adapter creation in a disposable PHP-only workspace.
 * No Docker socket, no shell script, no production credentials or documents.
 * Compatible with PHP 5.6+.
 */
$source = dirname(__DIR__);
$tmp = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR . 'ecidade-adapter-'.getmypid().'-'.mt_rand(10000, 99999);

function assertTrue($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function copyFixture($source, $target, $relative)
{
    $from = $source.'/'.$relative;
    $to = $target.'/'.$relative;
    if (!is_dir(dirname($to)) && !mkdir(dirname($to), 0700, true)) {
        throw new RuntimeException('Cannot prepare test directory');
    }
    if (!copy($from, $to)) {
        throw new RuntimeException('Cannot copy fixture '.$relative);
    }
}
function phpCommand(array $args, array $env = [])
{
    $parts = [escapeshellarg(PHP_BINARY)];
    foreach ($args as $arg) {
        $parts[] = escapeshellarg($arg);
    }
    $descriptors = [
        0 => ['pipe','r'],1 => ['pipe','w'],2 => ['pipe','w']
    ];
    $process = proc_open(
        implode(' ', $parts),
        $descriptors,
        $pipes,
        null,
        count($env) ? array_merge($_ENV, $env) : null
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot launch PHP child process');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    return [$code,$stdout,$stderr];
}
function removeFixture($path)
{
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

try {
    $files = [
        'bootstrap.php', 'bin/integrator.php',
        'src/AdapterRegistry.php', 'src/DocumentClient.php',
        'src/LargeObjectBridge.php', 'src/StandardLargeObjectFacade.php',
        'modules/dbseller/module.json', 'modules/dbseller/DBLargeObject.php'
    ];
    foreach ($files as $file) {
        copyFixture($source, $tmp, $file);
    }

    $command = $tmp.'/bin/integrator.php';
    $scaffold = phpCommand([$command, 'scaffold',
        'community-contract-smoke', 'dbseller']);
    assertTrue($scaffold[0] === 0, 'Scaffold did not run: '.$scaffold[2]);

    $rejected = phpCommand([$command, 'select', 'community-contract-smoke']);
    assertTrue($rejected[0] !== 0, 'Unreviewed module was selectable');
    assertTrue(
        strpos($rejected[2], 'unreviewed') !== false,
        'Module refused for an unexpected reason'
    );

    $modulePath = $tmp.'/modules/community-contract-smoke';
    $manifestFile = $modulePath.'/module.json';
    $manifest = json_decode(file_get_contents($manifestFile), true);
    assertTrue(is_array($manifest), 'Scaffold created invalid JSON');

    $upstream = $tmp.'/upstream';
    if (!mkdir($upstream, 0700)) {
        throw new RuntimeException('Cannot create upstream fixture');
    }
    $content = "<?php // synthetic version fixture\n";
    file_put_contents($upstream.'/contract.php', $content);
    $blob = sha1('blob '.strlen($content)."\0".$content);

    $manifest['tested'] = true;
    $manifest['certification'] = 'unit-test-only';
    $manifest['hooks'] = [
        ['type' => 'source-signature',
            'source' => ['path' => 'contract.php','git_blob' => $blob]],
        ['type' => 'php-bootstrap','script' => 'register.php']
    ];
    file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT)."\n");
    file_put_contents(
        $modulePath.'/register.php',
        "<?php \$GLOBALS['community_hook_loaded'] = true;\n"
    );

    $verified = phpCommand([$command,'verify','community-contract-smoke',$upstream]);
    assertTrue($verified[0] === 0, 'Source verification failed: '.$verified[2]);
    assertTrue(
        strpos($verified[1], 'PASS adapter community-contract-smoke') !== false,
        'No source verification evidence'
    );

    $child = 'require '.var_export($tmp.'/bootstrap.php', true).'; '
        .'if (empty($GLOBALS["community_hook_loaded"])) exit(7); '
        .'echo "PASS community PHP bootstrap\n";';
    $loaded = phpCommand(['-r',$child], [
        'ECIDADE_INTEGRATOR_ROOT' => $tmp,
        'ECIDADE_ADAPTER' => 'community-contract-smoke',
        'ECIDADE_POC_CLIENT' => '1'
    ]);
    assertTrue(
        $loaded[0] === 0 && strpos($loaded[1], 'PASS community PHP bootstrap') !== false,
        'Module bootstrap not loaded: '.$loaded[2]
    );

    echo "PASS PHP scaffold, default-deny, source fingerprint and community adapter hook\n";
} catch (Exception $e) {
    fwrite(STDERR, 'FAIL community adapter: '.$e->getMessage()."\n");
    exit(1);
} finally {
    removeFixture($tmp);
}
