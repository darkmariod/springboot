<?php
namespace App\Services;
use SoapClient;

class SriXmlDownloader {
    private const WSDL = [
        1 => 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl',
        2 => 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline?wsdl',
    ];
    public function download(string $claveAcceso, int $ambiente = 2): ?string {
        $client = new SoapClient(self::WSDL[$ambiente] ?? self::WSDL[2], ['exceptions' => true]);
        $res = $client->autorizacionComprobante(['claveAccesoComprobante' => $claveAcceso]);
        $aut = $res->RespuestaAutorizacionComprobante->autorizaciones->autorizacion ?? null;
        if (is_array($aut)) $aut = $aut[0] ?? null;
        if (! $aut || ($aut->estado ?? '') !== 'AUTORIZADO') return null;
        return (string) $aut->comprobante;
    }
}
