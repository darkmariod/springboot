<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * T11.4 (D4): borrar un contacto con documentos o un usuario con historial responde 422 con un mensaje claro.
 * Antes la base rechazaba el borrado con una llave foránea y la API devolvía un 500 con el SQL.
 */
class BorrarConHistorialTest extends SeguridadTestCase
{
    private const MSG_CONTACTO = 'No se puede eliminar: tiene documentos registrados.';
    private const MSG_USUARIO = 'Este usuario tiene historial. Desactívalo en lugar de eliminarlo.';

    private int $cedulas = 0;

    private function contacto(): Contact
    {
        return Contact::create([
            'company_id' => $this->empresa->id, 'tipo_identificacion' => '05', 'identificacion' => '17'.str_pad((string) ++$this->cedulas, 8, '0', STR_PAD_LEFT),
            'razon_social' => 'Cliente '.$this->cedulas, 'es_cliente' => true,
        ]);
    }

    /** Un documento mínimo de cada tabla que apunta al contacto. */
    private function documento(string $tabla, Contact $c): void
    {
        $base = ['company_id' => $this->empresa->id, 'contact_id' => $c->id];
        $totales = ['items' => '[]', 'total_sin_impuestos' => 0, 'total_impuesto' => 0, 'importe_total' => 0];

        match ($tabla) {
            'invoices', 'quotes' => DB::table($tabla)->insert($base + $totales),
            'purchases' => DB::table($tabla)->insert($base + $totales + ['fecha_emision' => '2026-10-01']),
            'credit_notes' => DB::table($tabla)->insert($base + ['tipo' => 'interna', 'fecha' => '2026-10-01', 'motivo' => 'Devolución', 'importe_total' => 5]),
            'advances' => DB::table($tabla)->insert($base + ['fecha' => '2026-10-01', 'monto' => 5, 'saldo' => 5, 'forma_pago' => 'efectivo']),
            'withholdings' => DB::table($tabla)->insert($base + ['numero' => '001-001-000000001', 'total_retenido' => 1]),
            'employees' => Employee::create($base + [
                'cedula' => '1799999999', 'nombres' => 'Empleado Consumos', 'cargo' => 'Técnico', 'fecha_ingreso' => '2025-01-01', 'sueldo' => 500,
            ]),
        };
    }

    public static function tablasConDocumentos(): array
    {
        return [
            'factura' => ['invoices'], 'cotización' => ['quotes'], 'compra' => ['purchases'], 'nota de crédito' => ['credit_notes'],
            'anticipo' => ['advances'], 'retención' => ['withholdings'], 'empleado (consumos del rol)' => ['employees'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('tablasConDocumentos')]
    public function test_un_contacto_con_documentos_no_se_elimina_y_responde_422_claro(string $tabla): void
    {
        $this->como('admin');
        $c = $this->contacto();
        $this->documento($tabla, $c);

        $r = $this->deleteJson("/api/contacts/{$c->id}");

        $r->assertStatus(422);
        $this->assertSame(self::MSG_CONTACTO, $r->json('message'));
        $this->assertStringNotContainsString('SQL', $r->getContent());
        $this->assertNotNull(Contact::find($c->id), 'El contacto debe seguir existiendo.');
    }

    public function test_un_contacto_sin_documentos_se_elimina(): void
    {
        $this->como('contador');
        $c = $this->contacto();

        $this->deleteJson("/api/contacts/{$c->id}")->assertNoContent();

        $this->assertNull(Contact::find($c->id));
    }

    public function test_un_contacto_con_documentos_de_otro_contacto_no_bloquea_a_los_demas(): void
    {
        $this->como('admin');
        $conHistorial = $this->contacto();
        $libre = $this->contacto();
        $this->documento('invoices', $conHistorial);

        $this->deleteJson("/api/contacts/{$libre->id}")->assertNoContent();
        $this->deleteJson("/api/contacts/{$conHistorial->id}")->assertStatus(422);
    }

    public function test_un_usuario_con_registros_de_auditoria_no_se_elimina_y_responde_422_claro(): void
    {
        $this->como('admin');
        $u = $this->usuario('cajero');
        AuditLog::create(['user_id' => $u->id, 'accion' => 'ingreso', 'modelo' => 'Sesión', 'descripcion' => $u->email, 'ip' => '127.0.0.1']);

        $r = $this->deleteJson("/api/users/{$u->id}");

        $r->assertStatus(422);
        $this->assertSame(self::MSG_USUARIO, $r->json('message'));
        $this->assertStringNotContainsString('SQL', $r->getContent());
        $this->assertNotNull(User::find($u->id));
    }

    public function test_un_usuario_que_ya_inicio_sesion_tiene_historial(): void
    {
        $u = $this->usuario('cajero', ['password' => 'clave-segura-1', 'email' => 'con.historial@empresa.test']);
        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'clave-segura-1'])->assertOk();

        $this->como('admin');
        $this->deleteJson("/api/users/{$u->id}")->assertStatus(422)->assertJsonPath('message', self::MSG_USUARIO);
    }

    public function test_un_usuario_sin_historial_se_elimina_con_sus_tokens(): void
    {
        $this->como('admin');
        $u = $this->usuario('cajero');
        $u->createToken('web');

        $this->deleteJson("/api/users/{$u->id}")->assertNoContent();

        $this->assertNull(User::find($u->id));
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $u->id)->count());
    }

    public function test_el_administrador_no_puede_eliminarse_a_si_mismo(): void
    {
        $admin = $this->como('admin');

        $this->deleteJson("/api/users/{$admin->id}")->assertStatus(422);
        $this->assertNotNull(User::find($admin->id));
    }
}
