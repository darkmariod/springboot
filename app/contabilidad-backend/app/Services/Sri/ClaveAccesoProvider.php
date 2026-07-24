<?php

namespace App\Services\Sri;

use App\Models\Company;
use App\Services\SriXmlDownloader;
use App\Services\StorePurchaseFromXml;

/**
 * Provider: importa por clave de acceso (49 dígitos).
 * Descarga el XML del SRI y lo convierte en Purchase.
 */
class ClaveAccesoProvider implements SriImportProvider
{
    public function __construct(
        private SriXmlDownloader $downloader,
        private StorePurchaseFromXml $storer,
    ) {}

    public function key(): string
    {
        return 'clave_acceso';
    }

    public function handle(Company $company, array $data): \App\Models\Purchase
    {
        $clave = trim((string) ($data['clave_acceso'] ?? ''));
        if (strlen($clave) !== 49 || !ctype_digit($clave)) {
            throw new \InvalidArgumentException('La clave de acceso debe tener 49 dígitos.');
        }

        $xml = $this->downloader->download($clave, (int) $company->ambiente);
        if (!$xml) {
            throw new \RuntimeException('El SRI no devolvió el XML para esta clave de acceso (pasó el mes o no está autorizado).');
        }

        return $this->storer->handle($company, $xml);
    }
}
