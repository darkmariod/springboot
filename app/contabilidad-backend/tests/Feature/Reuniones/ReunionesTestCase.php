<?php

namespace Tests\Feature\Reuniones;

use App\Actions\EmitirSriDocument;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Purchase;
use App\Models\SriDocument;
use App\Models\User;
use App\Support\Cuentas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\CorreoTestCase;

/**
 * Base de las pruebas de aceptación de las reuniones (consultor tributario, contadora anterior, competidor y plataforma).
 *
 * Cada prueba cuenta una escena como la viviría una persona: se prepara la empresa, se actúa por la API como lo hace la
 * pantalla y se comprueban números y estados reales. No se repite el detalle de las pruebas unitarias: aquí se verifica que
 * lo que se pidió en la reunión se cumple HOY.
 *
 * Reutiliza `CorreoTestCase`: de ahí vienen la empresa con el plan Completo, el cliente y el proveedor de ejemplo, el producto
 * de servicio SERV-1, la bodega principal, el SRI falso (`sriFalso()`), el certificado de prueba (`conCertificado()`) y el
 * transporte de correo en memoria (nada sale a la red). Esta clase agrega los usuarios por rol, el plan de cuentas y las
 * ayudas para facturar, comprar y revisar asientos.
 *
 * Por defecto el SRI se simula (la factura nace con su comprobante «generado», sin firmar ni enviar): es lo que pasa en una
 * empresa que aún no sube su certificado. Una prueba que necesita el comprobante real llama `conComprobanteReal()`.
 */
abstract class ReunionesTestCase extends CorreoTestCase
{
    protected User $admin;
    protected User $contador;
    protected User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        // Sin certificado el SRI no recibe nada: la factura queda con su comprobante generado. Se simula para que la prueba
        // sea rápida; la que necesita el comprobante real llama conComprobanteReal().
        $this->mock(EmitirSriDocument::class)->shouldReceive('execute')->andReturn(new SriDocument);

        Cuentas::sembrar($this->company->id);
        $this->admin = $this->usuarioDeLaEmpresa('admin');
        $this->contador = $this->usuarioDeLaEmpresa('contador');
        $this->cajero = $this->usuarioDeLaEmpresa('cajero');
        $this->como('admin');
    }

    // ------------------------------------------------------------ personas

    protected function usuarioDeLaEmpresa(string $rol): User
    {
        return User::factory()->create([
            'rol' => $rol, 'activo' => true, 'company_id' => $this->company->id, 'es_superadmin' => false,
            'name' => 'Prueba '.$rol, 'email' => $rol.'.reuniones@empresa.test',
        ]);
    }

    /** Entra como el administrador, el contador o el cajero de la empresa de la prueba. */
    protected function como(string $rol): User
    {
        $usuario = match ($rol) {
            'admin' => $this->admin, 'contador' => $this->contador, 'cajero' => $this->cajero,
        };
        Sanctum::actingAs($usuario);

        return $usuario;
    }

    protected function contacto(string $identificacion, string $nombre, array $extra = []): Contact
    {
        return Contact::create($extra + [
            'company_id' => $this->company->id, 'tipo_identificacion' => strlen($identificacion) === 13 ? '04' : '05',
            'identificacion' => $identificacion, 'razon_social' => $nombre,
        ]);
    }

    /** Un contacto que es cliente y proveedor a la vez (la escena del cruce de saldos). */
    protected function clienteYProveedor(): Contact
    {
        return $this->contacto('1790099887001', 'Comercial Doble SA', ['es_cliente' => true, 'es_proveedor' => true]);
    }

    // ------------------------------------------------------------ el SRI

    /** La factura nace con su comprobante real «generado» (sin certificado no sale nada al SRI). */
    protected function conComprobanteReal(): void
    {
        $this->app->forgetInstance(EmitirSriDocument::class);
    }

    // ------------------------------------------------------------ documentos por la API

    /** Factura de un servicio: $base más IVA a la tarifa dada. Por defecto a crédito. */
    protected function facturar(?Contact $cliente = null, float $base = 100, float $tarifa = 15, string $formaPago = 'credito'): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => ($cliente ?? $this->cliente)->id, 'forma_pago' => $formaPago,
            'items' => [['codigo_principal' => 'SERV-1', 'descripcion' => 'Servicio', 'cantidad' => 1, 'precio_unitario' => $base, 'tarifa' => $tarifa]],
        ])->assertCreated();

        return Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    /** Factura de bienes. @param array<int,array{0:string,1:float|int,2:float|int}> $lineas [código, cantidad, precio] */
    protected function facturarBienes(array $lineas, ?Contact $cliente = null, string $formaPago = 'efectivo'): Invoice
    {
        $this->postJson('/api/invoices', [
            'company_id' => $this->company->id, 'contact_id' => ($cliente ?? $this->cliente)->id, 'forma_pago' => $formaPago,
            'items' => array_map(fn ($l) => [
                'codigo_principal' => $l[0], 'descripcion' => $l[0], 'cantidad' => $l[1], 'precio_unitario' => $l[2], 'tarifa' => 15,
            ], $lineas),
        ])->assertCreated();

        return Invoice::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    /**
     * Compra por la API. Cada línea es [código, cantidad, precio unitario] con IVA 15 %; un código nuevo crea el bien.
     *
     * @param array<int,array{0:string,1:float|int,2:float|int}> $lineas
     */
    protected function comprar(?Contact $proveedor = null, array $lineas = [['SERV-1', 1, 100]], ?string $fecha = null, array $extra = []): Purchase
    {
        $this->postJson('/api/purchases', $extra + [
            'company_id' => $this->company->id, 'contact_id' => ($proveedor ?? $this->proveedor)->id,
            'numero' => sprintf('001-001-%09d', 500 + ++$this->n), 'fecha_emision' => $fecha ?? now()->toDateString(),
            'items' => array_map(fn ($l) => [
                'codigo_principal' => $l[0], 'descripcion' => $l[0], 'cantidad' => $l[1], 'precio_unitario' => $l[2], 'tarifa' => 15,
            ], $lineas),
        ])->assertCreated();

        return Purchase::where('company_id', $this->company->id)->latest('id')->firstOrFail();
    }

    // ------------------------------------------------------------ contabilidad

    /** El chequeo contable estricto de la empresa termina en 0 (cartera, inventario y mayor cuadran). */
    protected function assertContabilidadCuadra(): void
    {
        $this->artisan('contable:chequeo', ['--company' => $this->company->id, '--estricto' => true])->assertExitCode(0);
    }

    /** El asiento de un documento (el primero que no es una reversión); con `$concepto` el que empieza así. */
    protected function asientoDe(Model $origen, ?string $concepto = null): JournalEntry
    {
        $q = JournalEntry::where('origen_type', $origen->getMorphClass())->where('origen_id', $origen->getKey())->orderBy('id');
        if ($concepto !== null) {
            $q->where('concepto', 'like', $concepto.'%');
        } else {
            $q->where('concepto', 'not like', 'Reversión%');
        }

        return $q->firstOrFail();
    }

    /** Líneas [código, debe, haber] de un asiento, sin importar el orden, y que cuadre. */
    protected function assertLineas(JournalEntry $asiento, array $esperado): void
    {
        $real = $asiento->lines()->with('account')->get()
            ->map(fn ($l) => [$l->account->codigo, round((float) $l->debe, 2), round((float) $l->haber, 2)])->all();
        $ordenar = function (array $filas): array {
            $filas = array_map(fn ($f) => [(string) $f[0], round((float) $f[1], 2), round((float) $f[2], 2)], $filas);
            usort($filas, fn ($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

            return $filas;
        };
        $this->assertEquals($ordenar($esperado), $ordenar($real), 'Líneas del asiento «'.$asiento->concepto.'»');
        $this->assertEquals(round((float) $asiento->total_debe, 2), round((float) $asiento->total_haber, 2), 'El asiento cuadra');
    }

    /** Saldo del mayor de esas cuentas: (Debe − Haber) × signo. Sirve para decir «el mayor dice lo mismo que la pantalla». */
    protected function saldoMayor(array $codigos, int $signo = 1): float
    {
        $q = JournalEntryLine::join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entries.company_id', $this->company->id)->where('journal_entries.estado', '!=', 'borrador')
            ->whereIn('accounts.codigo', $codigos);

        return round($signo * ((float) $q->sum('journal_entry_lines.debe') - (float) (clone $q)->sum('journal_entry_lines.haber')), 2);
    }

    // ------------------------------------------------------------ archivos del SRI

    /** Clave de acceso de 49 dígitos: fecha(8) tipo(2) ruc(13) ambiente(1) serie(6) secuencial(9) código(8) emisión(1) verificador(1). */
    protected function claveSri(string $tipo, string $rucEmisor, string $secuencial, string $fecha = '10102026'): string
    {
        $clave = $fecha.$tipo.$rucEmisor.'2'.'001001'.$secuencial.'12345678'.'1'.'3';
        $this->assertSame(49, strlen($clave));

        return $clave;
    }

    /** XML de una factura de compra como lo baja el contador del portal del SRI. @param array<int,array{0:string,1:float|int,2:float|int}> $lineas */
    protected function xmlCompra(string $secuencial, array $lineas, string $rucProveedor = '1790011223001'): string
    {
        $detalles = '';
        $base = 0;
        foreach ($lineas as [$codigo, $cant, $precio]) {
            $sub = $cant * $precio;
            $base += $sub;
            $detalles .= "<detalle><codigoPrincipal>{$codigo}</codigoPrincipal><descripcion>{$codigo}</descripcion>"
                ."<cantidad>{$cant}</cantidad><precioUnitario>{$precio}</precioUnitario>"
                ."<impuestos><impuesto><codigo>2</codigo><tarifa>15</tarifa><baseImponible>{$sub}</baseImponible><valor>".($sub * 0.15).'</valor></impuesto></impuestos></detalle>';
        }
        $iva = $base * 0.15;
        $total = $base + $iva;
        $clave = str_pad($secuencial, 49, '1', STR_PAD_LEFT);

        return "<factura><infoTributaria><ruc>{$rucProveedor}</ruc><razonSocial>Proveedor Test SA</razonSocial>"
            ."<estab>001</estab><ptoEmi>001</ptoEmi><secuencial>{$secuencial}</secuencial><claveAcceso>{$clave}</claveAcceso></infoTributaria>"
            ."<infoFactura><fechaEmision>05/10/2026</fechaEmision><totalSinImpuestos>{$base}</totalSinImpuestos>"
            ."<totalConImpuestos><totalImpuesto><codigo>2</codigo><valor>{$iva}</valor></totalImpuesto></totalConImpuestos>"
            ."<importeTotal>{$total}</importeTotal></infoFactura><detalles>{$detalles}</detalles></factura>";
    }

    protected function subirXmlCompra(string $secuencial, array $lineas, array $extra = [])
    {
        return $this->post('/api/purchases/import', $extra + [
            'company_id' => $this->company->id,
            'xml' => UploadedFile::fake()->createWithContent('compra.xml', $this->xmlCompra($secuencial, $lineas)),
        ], ['Accept' => 'application/json']);
    }

    /** El documento del SRI de una factura, puesto en el estado que dejó el SRI. */
    protected function marcarSri(Invoice $factura, string $estado): SriDocument
    {
        $doc = $factura->sriDocument()->firstOrFail();
        $doc->update([
            'estado' => $estado,
            'numero_autorizacion' => $estado === 'AUTORIZADO' ? $doc->clave_acceso : null,
            'mensajes' => $estado === 'AUTORIZADO'
                ? ['RespuestaAutorizacionComprobante' => ['autorizaciones' => ['autorizacion' => [
                    'estado' => 'AUTORIZADO', 'numeroAutorizacion' => $doc->clave_acceso, 'fechaAutorizacion' => '2026-10-07T10:15:30-05:00',
                ]]]]
                : null,
        ]);

        return $doc->fresh();
    }
}
