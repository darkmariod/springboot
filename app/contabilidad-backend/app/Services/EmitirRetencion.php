<?php

namespace App\Services;

use App\Actions\EmitirSriDocument;
use App\Models\Company;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\Withholding;
use App\Support\ComprobantesCompra;
use App\Support\Cuentas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Retención que la empresa le hace a un proveedor al registrar su compra (comprobante 07).
 *
 * Una retención es un comprobante con varias líneas (renta o IVA, código, base, porcentaje).
 * Cada línea queda en su propia fila de `withholdings` (el 103 y el 104 suman por fila), todas con
 * el mismo número, y se asienta UNA vez:
 *   Debe 2.1.01 Cuentas por pagar          por el total retenido (o 2.1.11 si la compra nació en
 *                                          Cuentas por pagar relacionadas: se salda la cuenta de la compra)
 *   Haber 2.1.09 Retención IVA por pagar    por lo retenido de IVA
 *   Haber 2.1.10 Retención renta por pagar  por lo retenido de renta
 * El saldo de la compra baja en lo retenido, así el mayor y el subdiario de cuentas por pagar siguen iguales.
 *
 * El comprobante electrónico se crea con el mismo mecanismo de los demás documentos (EmitirSriDocument):
 * sin certificado de firma queda en estado "generado"; con certificado se firma y se envía al SRI.
 */
class EmitirRetencion
{
    /** Código de impuesto del XML del SRI. */
    private const IMPUESTO_SRI = ['renta' => '1', 'iva' => '2'];

    /**
     * Los códigos 721/723/725 del catálogo son los casilleros del formulario 104; el XML del SRI
     * lleva el código del porcentaje de IVA: 1 = 30%, 2 = 70%, 3 = 100%.
     */
    private const IVA_CODIGO_XML = ['721' => '1', '723' => '2', '725' => '3'];

    /**
     * Registra la retención: filas, asiento y baja del saldo. No toca el SRI.
     *
     * @param  array  $lineas  [['tipo'=>'renta|iva','codigo'=>'303','base_imponible'=>100,'porcentaje'=>null], ...]
     *                         En la forma anterior (una línea sin código) el código se deduce del porcentaje.
     * @return array{numero:string,fecha:string,total:float,filas:Collection<int,Withholding>,compra:Purchase,electronico:bool}
     *
     * @throws ValidationException 422 en español si el código, el porcentaje o el saldo no cuadran
     */
    public function registrar(Purchase $purchase, array $lineas, ?string $numeroManual = null): array
    {
        $lineas = $this->normalizar($lineas);

        return DB::transaction(function () use ($purchase, $lineas, $numeroManual) {
            $compra = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            $company = Company::findOrFail($compra->company_id);

            $total = round(array_sum(array_column($lineas, 'valor')), 2);
            $saldo = round((float) $compra->saldo_pendiente, 2);
            if ($total > $saldo + 0.004) {
                throw ValidationException::withMessages(['lineas' => [sprintf(
                    'La retención ($%s) supera el saldo pendiente de la compra ($%s).',
                    number_format($total, 2, '.', ''), number_format($saldo, 2, '.', ''),
                )]]);
            }

            // Con número propio (retención en papel) no se gasta secuencial ni se genera comprobante electrónico
            $electronico = $numeroManual === null || trim($numeroManual) === '';
            $numero = $electronico
                ? sprintf('%s-%s-%09d', $company->estab, $company->pto_emi, (int) $company->secuencial)
                : trim($numeroManual);
            $fecha = now()->toDateString();

            $filas = collect($lineas)->map(fn (array $l) => Withholding::create([
                'company_id' => $compra->company_id,
                'invoice_id' => null, // retención emitida, no recibida
                'purchase_id' => $compra->id,
                'contact_id' => $compra->contact_id,
                'tipo' => 'emitida',
                'numero' => $numero,
                'clave_acceso' => null,
                'fecha' => $fecha,
                // El 103 agrupa por este código y solo conoce los de renta: la retención de IVA
                // va sin código aquí (su código viaja en el JSON de abajo y en el comprobante).
                'codigo_retencion' => $l['tipo'] === 'renta' ? $l['codigo'] : null,
                'base_imponible' => $l['base'],
                'porcentaje' => $l['porcentaje'],
                'total_retenido' => $l['valor'],
                'xml' => json_encode([
                    'tipo' => $l['tipo'], 'codigo' => $l['codigo'], 'porcentaje' => $l['porcentaje'],
                    'base_imponible' => $l['base'], 'purchase_id' => $compra->id,
                ]),
            ]));

            $iva = round(collect($lineas)->where('tipo', 'iva')->sum('valor'), 2);
            $renta = round(collect($lineas)->where('tipo', 'renta')->sum('valor'), 2);
            $asiento = [Cuentas::linea(Cuentas::cxpDe($compra), $total, 0, $numero)];
            if ($iva > 0) {
                $asiento[] = Cuentas::linea('retencion_iva_por_pagar', 0, $iva, $numero);
            }
            if ($renta > 0) {
                $asiento[] = Cuentas::linea('retencion_renta_por_pagar', 0, $renta, $numero);
            }
            $tipos = ($iva > 0 && $renta > 0) ? 'IVA y RENTA' : ($iva > 0 ? 'IVA' : 'RENTA');
            SimpleEntry::make($compra->company_id, "Retención emitida $numero — $tipos", $asiento, $filas->first());

            $compra->decrement('saldo_pendiente', $total);
            if ($electronico) {
                $company->increment('secuencial');
            }

            return [
                'numero' => $numero, 'fecha' => $fecha, 'total' => $total, 'filas' => $filas,
                'compra' => $compra->fresh(), 'electronico' => $electronico,
            ];
        });
    }

    /**
     * Crea el comprobante electrónico de la retención con EmitirSriDocument. Nunca lanza: si algo
     * falla, la retención ya está registrada y se devuelve el estado "error" para poder reintentar.
     *
     * @return array{sri_document:?SriDocument,estado:string,mensaje:string}
     */
    public function generarDocumento(Withholding $withholding): array
    {
        $filas = $this->filasDelComprobante($withholding);
        $principal = $filas->first();

        if ($existente = $principal->sriDocument()->first()) {
            return $this->resultado($existente);
        }
        if ($filas->contains(fn (Withholding $w) => empty($this->extra($w)['codigo']))) {
            return ['sri_document' => null, 'estado' => 'no_generado',
                'mensaje' => 'La retención quedó registrada, pero le falta el código del SRI para generar el comprobante electrónico.'];
        }

        try {
            $company = Company::findOrFail($principal->company_id);
            // El comprobante conserva el secuencial que ya se asignó a la retención (el contador de la empresa ya avanzó)
            $company->forceFill(['secuencial' => (int) substr($principal->numero, -9)]);

            $doc = app(EmitirSriDocument::class)->execute($principal, 'comprobanteRetencion', $company, $this->payload($company, $filas));
            $filas->each(fn (Withholding $w) => $w->update(['clave_acceso' => $doc->clave_acceso]));

            return $this->resultado($doc);
        } catch (\Throwable $e) {
            Log::warning('No se pudo generar el comprobante de retención '.$principal->numero.': '.$e->getMessage());
            // Si el documento alcanzó a crearse antes del fallo (p. ej. al firmar), ese estado manda
            if ($parcial = $principal->sriDocument()->first()) {
                return $this->resultado($parcial);
            }

            return ['sri_document' => null, 'estado' => 'error', 'mensaje' => sprintf(
                'La retención quedó registrada, pero no se pudo generar el comprobante electrónico (%s). Puedes reintentarlo desde la compra.',
                $e->getMessage(),
            )];
        }
    }

    /** Las filas (líneas) del comprobante al que pertenece la retención, la principal primero. */
    public function filasDelComprobante(Withholding $withholding): Collection
    {
        return Withholding::where('company_id', $withholding->company_id)
            ->where('tipo', 'emitida')
            ->where('numero', $withholding->numero)
            ->when($withholding->purchase_id, fn ($q, $id) => $q->where('purchase_id', $id))
            ->orderBy('id')->get();
    }

    /** Tipo y código de una fila (el JSON que guarda `registrar`). */
    public function extra(Withholding $w): array
    {
        return json_decode((string) $w->xml, true) ?: [];
    }

    // ------------------------------------------------------------- privados

    /** Valida cada línea contra el catálogo y calcula porcentaje y valor. */
    private function normalizar(array $lineas): array
    {
        $salida = [];
        foreach (array_values($lineas) as $i => $l) {
            $tipo = (string) ($l['tipo'] ?? '');
            if (! in_array($tipo, ['renta', 'iva'], true)) {
                throw ValidationException::withMessages(["lineas.$i.tipo" => ['El tipo de retención debe ser renta o IVA.']]);
            }
            $base = round((float) ($l['base_imponible'] ?? 0), 2);
            if ($base <= 0) {
                throw ValidationException::withMessages(["lineas.$i.base_imponible" => ['La base imponible debe ser mayor a cero.']]);
            }

            $codigo = isset($l['codigo']) && $l['codigo'] !== '' ? (string) $l['codigo'] : null;
            if ($codigo !== null) {
                $entrada = collect(config("retenciones.$tipo"))->firstWhere('codigo', $codigo);
                if (! $entrada) {
                    throw ValidationException::withMessages(["lineas.$i.codigo" => [
                        sprintf('El código %s no es un código de retención de %s válido.', $codigo, $tipo === 'iva' ? 'IVA' : 'renta'),
                    ]]);
                }
                // El porcentaje es el del código; solo los códigos "otros porcentajes" (0 en el catálogo) lo piden
                $porcentaje = (float) $entrada['porcentaje'];
                if ($porcentaje <= 0) {
                    $porcentaje = (float) ($l['porcentaje'] ?? 0);
                    if ($porcentaje <= 0 || $porcentaje > 100) {
                        throw ValidationException::withMessages(["lineas.$i.porcentaje" => [
                            sprintf('Indica el porcentaje de retención para el código %s (mayor a 0 y hasta 100).', $codigo),
                        ]]);
                    }
                }
            } else {
                // Forma anterior: llega el porcentaje y el código se deduce de él
                $porcentaje = (float) ($l['porcentaje'] ?? 0);
                $codigo = $this->deducirCodigo($tipo, $porcentaje);
            }

            $valor = round($base * $porcentaje / 100, 2);
            if ($valor <= 0) {
                throw ValidationException::withMessages(["lineas.$i.base_imponible" => ['El valor retenido debe ser mayor a cero.']]);
            }
            $salida[] = ['tipo' => $tipo, 'codigo' => $codigo, 'base' => $base, 'porcentaje' => $porcentaje, 'valor' => $valor];
        }

        return $salida;
    }

    private function deducirCodigo(string $tipo, float $porcentaje): ?string
    {
        if ($tipo === 'iva') {
            return [30 => '721', 70 => '723', 100 => '725'][(int) round($porcentaje)] ?? null;
        }
        foreach (config('retenciones.renta') as $c) {
            if ($c['porcentaje'] > 0 && abs($c['porcentaje'] - $porcentaje) < 0.001) {
                return $c['codigo'];
            }
        }

        return '340'; // otras retenciones
    }

    private function resultado(SriDocument $doc): array
    {
        $estado = (string) $doc->estado;
        $mensaje = match (strtolower($estado)) {
            'generado' => 'La retención quedó registrada y su comprobante electrónico generado; se enviará al SRI cuando la empresa cargue su certificado de firma.',
            'firmado' => 'El comprobante de retención está firmado, pero todavía no se pudo enviar al SRI.',
            'enviado' => 'El comprobante de retención se envió al SRI y falta su autorización.',
            'autorizado' => 'El comprobante de retención fue autorizado por el SRI.',
            default => "Estado del comprobante de retención en el SRI: $estado.",
        };

        return ['sri_document' => $doc, 'estado' => $estado, 'mensaje' => $mensaje];
    }

    /** Datos del comprobante de retención (codDoc 07) para la librería del SRI. */
    private function payload(Company $company, Collection $filas): array
    {
        $principal = $filas->first();
        $compra = Purchase::with('contact')->findOrFail($principal->purchase_id);
        $proveedor = $compra->contact;
        $fecha = \Carbon\Carbon::parse($principal->fecha);
        [$estab, $ptoEmi] = array_pad(explode('-', (string) $principal->numero), 2, null);

        $impuestos = $filas->map(function (Withholding $w) use ($compra) {
            $extra = $this->extra($w);
            $tipo = $extra['tipo'];
            $codigo = (string) $extra['codigo'];

            return [
                'codigo' => self::IMPUESTO_SRI[$tipo],
                'codigoRetencion' => $tipo === 'iva' ? (self::IVA_CODIGO_XML[$codigo] ?? $codigo) : $codigo,
                'baseImponible' => number_format((float) $w->base_imponible, 2, '.', ''),
                'porcentajeRetener' => rtrim(rtrim(number_format((float) $w->porcentaje, 2, '.', ''), '0'), '.'),
                'valorRetenido' => number_format((float) $w->total_retenido, 2, '.', ''),
                'codDocSustento' => ComprobantesCompra::codigo($compra->tipo_comprobante),
                'numDocSustento' => $this->numDocSustento($compra),
                'fechaEmisionDocSustento' => $compra->fecha_emision->format('Y-m-d'),
            ];
        })->values()->all();

        return [
            'infoTributaria' => ['codDoc' => '07', 'estab' => $estab ?: $company->estab, 'ptoEmi' => $ptoEmi ?: $company->pto_emi],
            'infoCompRetencion' => [
                'fechaEmision' => $fecha->format('Y-m-d'),
                'dirEstablecimiento' => $company->dir_matriz,
                'obligadoContabilidad' => $company->obligado_contabilidad ? 'SI' : 'NO',
                'tipoIdentificacionSujetoRetenido' => $proveedor?->tipo_identificacion ?: '04',
                'razonSocialSujetoRetenido' => $proveedor?->razon_social ?? '',
                'identificacionSujetoRetenido' => $proveedor?->identificacion ?? '',
                'periodoFiscal' => $fecha->format('m/Y'),
            ],
            'impuestos' => $impuestos,
            // La librería escribe estos dos campos siempre y el SRI no acepta campos adicionales vacíos
            'infoAdicional' => [
                'telefono' => $proveedor?->telefono ?: 'N/A',
                'email' => $proveedor?->email ?: 'N/A',
            ],
        ];
    }

    /** Número del documento sustento en los 15 dígitos del SRI (establecimiento + punto + secuencial). */
    private function numDocSustento(Purchase $compra): string
    {
        $digitos = preg_replace('/\D/', '', (string) $compra->numero);
        if (strlen($digitos) >= 15) {
            return substr($digitos, -15);
        }

        return str_pad((string) ($compra->establecimiento ?: '001'), 3, '0', STR_PAD_LEFT)
            .str_pad((string) ($compra->punto_emision ?: '001'), 3, '0', STR_PAD_LEFT)
            .str_pad(substr($digitos, -9), 9, '0', STR_PAD_LEFT);
    }
}
