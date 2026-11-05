<?php

// SPDX-FileCopyrightText: 2026 LibreCode coop and contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// Public legacy method signatures shared by DBSeller and CPD Municipal.
// This is the only copy of the facade; module wrappers select it.
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
    public static function writeContent($sContent, $iOid = null)
    {
        return \ECidade\Nextcloud\LargeObjectBridge::writeContent($sContent, $iOid);
    }
    public static function leitura($iOid, $sCaminhoArquivo)
    {
        return \ECidade\Nextcloud\LargeObjectBridge::leitura($iOid, $sCaminhoArquivo);
    }
    public static function exclusao($iOid)
    {
        return \ECidade\Nextcloud\LargeObjectBridge::exclusao($iOid);
    }
}
