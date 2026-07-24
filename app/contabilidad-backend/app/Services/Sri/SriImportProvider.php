<?php

namespace App\Services\Sri;

use App\Models\Company;
use Illuminate\Http\UploadedFile;

/**
 * Interface para providers de importación de comprobantes SRI.
 * Cada provider sabe cómo recibir y procesar un comprobante externo.
 */
interface SriImportProvider
{
    /**
     * Identificador único del provider (ej: 'clave_acceso', 'xml_upload', 'api_paga').
     */
    public function key(): string;

    /**
     * Procesa la importación y retorna un Purchase creado.
     *
     * @param  Company  $company
     * @param  array    $data  Datos del request (campos específicos del provider)
     * @return \App\Models\Purchase
     */
    public function handle(Company $company, array $data): \App\Models\Purchase;
}
