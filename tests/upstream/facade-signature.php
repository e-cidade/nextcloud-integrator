<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Compare the method contracts of a pinned original fork class and its
 * shared integrator wrapper. Separate PHP processes avoid duplicate classes.
 * This is a signature contract, not a claim of ERP bootstrap or SPI wiring.
 */
if (PHP_SAPI !== 'cli' || getenv('ECIDADE_SYNTHETIC_FORK_CONTRACT') !== '1') {
    fwrite(STDERR, "Refusing non-synthetic source contract\n");
    exit(2);
}

function publicMethods($file)
{
    if (!is_file($file) || basename($file) !== 'DBLargeObject.php') {
        throw new RuntimeException('Source contract is missing');
    }
    require_once $file;
    $ref = new ReflectionClass('DBLargeObject');
    if (!$ref->isAbstract()) {
        throw new RuntimeException('Legacy facade must be abstract');
    }
    $methods = [];
    foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (!$method->isStatic() || $method->getDeclaringClass()->getName() !== 'DBLargeObject') {
            throw new RuntimeException('Legacy method must be declared public static');
        }
        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $parameters[] = [
                'name' => $parameter->getName(),
                'optional' => $parameter->isOptional(),
                'default' => $parameter->isDefaultValueAvailable()
                    ? $parameter->getDefaultValue() : null,
            ];
        }
        $methods[$method->getName()] = $parameters;
    }
    ksort($methods);
    return $methods;
}

if (isset($argv[1]) && $argv[1] === '--extract') {
    try {
        echo json_encode(publicMethods($argv[2])), PHP_EOL;
        exit(0);
    } catch (Exception $error) {
        fwrite(STDERR, "Cannot extract source facade signature\n");
        exit(1);
    }
}

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php facade-signature.php <original.php> <replacement.php>\n");
    exit(2);
}
function snapshot($file)
{
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__)
        . ' --extract ' . escapeshellarg($file) . ' 2>&1';
    $output = [];
    $status = 1;
    exec($cmd, $output, $status);
    $value = json_decode(implode("\n", $output), true);
    if ($status !== 0 || !is_array($value)) {
        throw new RuntimeException('Pinned method signature unavailable');
    }
    return $value;
}
try {
    $original = snapshot($argv[1]);
    $adapter = snapshot($argv[2]);
    if ($original !== $adapter) {
        throw new RuntimeException('Pinned original and adapter method signatures diverge');
    }
    echo 'PASS pinned facade signatures: ', implode(', ', array_keys($original)), PHP_EOL;
} catch (Exception $error) {
    fwrite(STDERR, 'FAIL ' . $error->getMessage() . "\n");
    exit(1);
}
