<?php

namespace App\Services\Sri;

use App\Models\Company;
use App\Services\StorePurchaseFromXml;
use Illuminate\Http\UploadedFile;

/**
 * Provider: importa desde un XML suelto subido por el usuario.
 * El archivo XML se procesa directamente sin consultar al SRI.
 */
class XmlUploadProvider implements SriImportProvider
{
    public function __construct(
        private StorePurchaseFromXml $storer,
    ) {}

    public function key(): string
    {
        return 'xml_upload';
    }

    public function handle(Company $company, array $data): \App\Models\Purchase
    {
        $file = $data['xml_file'] ?? null;

        if (!$file instanceof UploadedFile) {
            throw new \InvalidArgumentException('Se requiere un archivo XML (campo xml_file).');
        }

        if ($file->getClientOriginalExtension() !== 'xml') {
            throw new \InvalidArgumentException('El archivo debe ser un XML válido.');
        }

        $xml = $file->get();
        if (!$xml) {
            throw new \RuntimeException('No se pudo leer el archivo XML subido.');
        }

        return $this->storer->handle($company, $xml);
    }
}
