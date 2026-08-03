<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Contact;
use App\Models\Product;
use App\Services\InvoiceEmitter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Emite una factura de prueba y muestra la respuesta COMPLETA del SRI.
 * Sirve para debuggear la emisión: cuando el .p12 está cargado, se ve si autoriza
 * o exactamente por qué el SRI la rechaza. Uso: php artisan sri:test-emision
 */
class TestEmisionSri extends Command
{
    protected $signature = 'sri:test-emision {--company=1 : ID de la empresa}';
    protected $description = 'Emite una factura de prueba y muestra la respuesta del SRI (diagnóstico de emisión)';

    public function handle(InvoiceEmitter $emitter): int
    {
        $company = Company::find($this->option('company')) ?? Company::first();
        if (! $company) {
            $this->error('No hay empresa configurada.');
            return 1;
        }

        $this->newLine();
        $this->line('  <bg=blue;fg=white> TEST EMISIÓN SRI — '.$company->razon_social.' </>');
        $this->line('  Ambiente:     '.((int) $company->ambiente === 2 ? 'PRODUCCIÓN (cel)' : 'PRUEBAS (celcer)'));
        $this->line('  RUC:          '.$company->ruc);
        $this->line('  Firma (.p12): '.($company->certificado_p12 ? '<fg=green>CARGADA ✓</>' : '<fg=red>NO CARGADA ✗</>'));
        $this->newLine();

        if (! $company->certificado_p12) {
            $this->warn('  ⚠️  Sin .p12 la factura queda en "generado" y NO llega a AUTORIZADO.');
            $this->warn('     Cargá la firma en EDocuments → Configuración de firma y reintentá.');
            $this->newLine();
        }

        $contact = Contact::where('company_id', $company->id)->whereNotNull('identificacion')->first();
        $product = Product::where('company_id', $company->id)->where('tipo', '!=', 'servicio')->first();
        if (! $contact || ! $product) {
            $this->error('  Falta un cliente (con identificación) o un producto para la prueba de emisión.');
            return 1;
        }

        $this->line('  Emitiendo factura de prueba a '.$contact->razon_social.'...');
        try {
            $invoice = $emitter->emit($company, $contact, [[
                'codigo_principal' => $product->codigo,
                'descripcion' => $product->descripcion,
                'cantidad' => 1,
                'precio_unitario' => 10,
                'tarifa' => 15,
            ]], 'efectivo');
        } catch (Throwable $e) {
            $this->error('  ✗ EXCEPCIÓN al emitir: '.get_class($e).' — '.$e->getMessage());
            return 1;
        }

        $doc = $invoice->sriDocument()->first();

        $this->newLine();
        $this->line('  ── RESULTADO ──');
        $this->line('  Factura:         '.$invoice->numero);
        $this->line('  Clave de acceso: '.$doc->clave_acceso);
        $this->line('  XML firmado:     '.($doc->xml_firmado ? 'SÍ' : 'NO'));
        $this->line('  Estado:          '.$this->color($doc->estado));
        $this->line('  N° Autorización: '.($doc->numero_autorizacion ?: '—'));
        $this->newLine();
        $this->line('  ── RESPUESTA DEL SRI ──');
        $this->line('  '.json_encode($doc->mensajes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->newLine();

        $estado = strtoupper((string) $doc->estado);
        if ($estado === 'AUTORIZADO') {
            $this->info('  ✅ AUTORIZADO — la emisión al SRI funciona perfecto.');
        } elseif ($doc->estado === 'generado') {
            $this->warn('  ⚠️  Quedó en "generado" — falta cargar el .p12. Cargalo y volvé a correr esto.');
        } else {
            $this->error('  ✗ No llegó a AUTORIZADO. Mirá los mensajes del SRI arriba para el motivo.');
            $this->line('     Motivos comunes: certificado vencido, ambiente equivocado (pruebas vs producción),');
            $this->line('     secuencial repetido, fecha/clave de acceso, o el RUC no habilitado para facturar.');
        }
        $this->newLine();

        return 0;
    }

    private function color(string $estado): string
    {
        return match (strtoupper($estado)) {
            'AUTORIZADO' => '<fg=green>'.$estado.'</>',
            'GENERADO' => '<fg=yellow>'.$estado.'</>',
            default => '<fg=red>'.$estado.'</>',
        };
    }
}
