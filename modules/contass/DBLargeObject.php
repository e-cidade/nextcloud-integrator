<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// Exact public Contass DBLargeObject method surface; not full Laravel integration.
if (getenv('ECIDADE_ADAPTER') !== 'contass') {
    throw new RuntimeException('Wrong facade for selected module');
}
$root = getenv('ECIDADE_INTEGRATOR_ROOT');
require_once $root.'/src/DocumentClient.php';
require_once $root.'/src/LargeObjectBridge.php';
abstract class DBLargeObject
{
    public static function criaOID($lTemTransacaoAtiva = false)
    {
        return \ECidade\Nextcloud\LargeObjectBridge::criaOID($lTemTransacaoAtiva);
    }
    public static function escrita($sCaminhoArquivo, $iOid = null)
    {
        return \ECidade\Nextcloud\LargeObjectBridge::escrita($sCaminhoArquivo, $iOid);
    }
    public static function leitura($iOid, $sCaminhoArquivo)
    {
        return \ECidade\Nextcloud\LargeObjectBridge::leitura($iOid, $sCaminhoArquivo);
    }
    public static function exclusao($iOid)
    {
        return \ECidade\Nextcloud\LargeObjectBridge::exclusao($iOid);
    }
    public static function gerarArquivoOid($sCaminhoArquivo, $lTemTransacaoAtiva = false)
    {
        return \ECidade\Nextcloud\LargeObjectBridge::gerarArquivoOid($sCaminhoArquivo, $lTemTransacaoAtiva);
    }
}
