<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Support\AsientoManual;
use App\Support\NumeroAsiento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Asientos escritos o corregidos a mano (T4.1). Solo admin y contador (lo exige la ruta).
 *
 *  - POST   journal-entries           asiento manual (borrador o pendiente)
 *  - PUT    journal-entries/{id}      edita un asiento en borrador o pendiente, manual o generado por un documento
 *  - DELETE journal-entries/{id}      elimina un asiento manual que nunca se mayorizó
 *  - POST   journal-entries/{id}/contabilizar   pasa un borrador a pendiente
 *
 * Un asiento mayorizado no se edita: primero se desmayoriza (AccountingController::desmayorizar).
 * Editar un asiento generado conserva su vínculo con el documento (origen_*) y deja constancia en la auditoría.
 */
class JournalEntryController extends Controller
{
    public function store(Request $r)
    {
        $d = $this->validar($r, true);
        $this->exigirEmpresa($r, (int) $d['company_id']);

        $entry = DB::transaction(function () use ($d) {
            // Mientras se numera nadie más crea asientos de esta empresa (en SQLite la escritura ya es de una sola vía)
            Company::whereKey($d['company_id'])->lockForUpdate()->first();
            $norm = AsientoManual::normalizarLineas((int) $d['company_id'], $d['lines']);
            $e = JournalEntry::create([
                'company_id' => $d['company_id'],
                'numero' => NumeroAsiento::siguiente((int) $d['company_id']),
                'fecha' => $d['fecha'],
                'concepto' => $d['concepto'],
                'origen_type' => null,
                'origen_id' => null,
                'total_debe' => $norm['debe'],
                'total_haber' => $norm['haber'],
                'estado' => $d['estado'] ?? JournalEntry::PENDIENTE,
                'manual' => true,
            ]);
            $e->lines()->createMany($norm['lineas']);

            return $e;
        });

        return response()->json($this->presentar($entry->fresh('lines.account')), 201);
    }

    public function update(Request $r, JournalEntry $entry)
    {
        $this->exigirEmpresa($r, (int) $entry->company_id);
        $d = $this->validar($r, false);

        $entry = DB::transaction(function () use ($entry, $d, $r) {
            $actual = JournalEntry::whereKey($entry->id)->lockForUpdate()->with('lines.account')->firstOrFail();
            // Mes cerrado (el actual del asiento o aquel al que se lo quiere mover): antes que cualquier otro motivo.
            // Esta ruta guarda sin eventos del modelo, por eso la guarda se llama aquí; si falla, nada se tocó todavía
            AccountingPeriod::exigirAbierto((int) $actual->company_id, $actual->fecha);
            AccountingPeriod::exigirAbierto((int) $actual->company_id, $d['fecha']);
            AsientoManual::exigirEditable($actual);

            $estado = $d['estado'] ?? $actual->estado;
            if (! $actual->manual && $estado !== $actual->estado) {
                abort(422, 'El estado de un asiento generado por un documento no se cambia aquí.');
            }
            $norm = AsientoManual::normalizarLineas((int) $actual->company_id, $d['lines'], $actual, true);

            if (AsientoManual::sinCambios($actual, $d['fecha'], $d['concepto'], $estado, $norm['lineas'])) {
                return $actual;
            }

            $antes = AsientoManual::foto($actual);
            $actual->lines()->delete();
            $actual->lines()->createMany($norm['lineas']);
            $actual->fill([
                'fecha' => $d['fecha'],
                'concepto' => $d['concepto'],
                'total_debe' => $norm['debe'],
                'total_haber' => $norm['haber'],
                'estado' => $estado,
            ]);
            // Un asiento que nació de un documento y ahora lo corrigió una persona: el chequeo contable lo usa para explicar diferencias
            if (! $actual->manual) {
                $actual->fill(['editado_manualmente' => true, 'editado_at' => now(), 'editado_por' => $r->user()?->id]);
            }
            $actual->saveQuietly();

            $actual = $actual->fresh('lines.account');
            $despues = AsientoManual::foto($actual);
            if ($antes !== $despues) {
                $actual->registrarAuditoria('edito_asiento', ['antes' => $antes, 'despues' => $despues]);
            }

            return $actual;
        });

        return $this->presentar($entry);
    }

    public function destroy(Request $r, JournalEntry $entry)
    {
        $this->exigirEmpresa($r, (int) $entry->company_id);

        DB::transaction(function () use ($entry) {
            $actual = JournalEntry::whereKey($entry->id)->lockForUpdate()->with('lines.account')->firstOrFail();
            AccountingPeriod::exigirAbierto((int) $actual->company_id, $actual->fecha);   // borra sin eventos del modelo
            if (! $actual->manual) {
                abort(422, 'Un asiento generado por un documento no se elimina: se reversa al anular el documento.');
            }
            if ($actual->estado === JournalEntry::MAYORIZADO || $actual->fue_mayorizado) {
                abort(422, 'Este asiento ya fue mayorizado y no se puede eliminar.');
            }
            $actual->registrarAuditoria('elimino_asiento', ['antes' => AsientoManual::foto($actual)]);
            $actual->lines()->delete();
            $actual->deleteQuietly();
        });

        return response()->json(['ok' => true]);
    }

    /** Borrador → pendiente: el asiento entra a los reportes. */
    public function contabilizar(Request $r, JournalEntry $entry)
    {
        $this->exigirEmpresa($r, (int) $entry->company_id);

        $entry = DB::transaction(function () use ($entry) {
            $actual = JournalEntry::whereKey($entry->id)->lockForUpdate()->with('lines.account')->firstOrFail();
            if ($actual->estado !== JournalEntry::BORRADOR) {
                abort(422, 'Solo se contabiliza un asiento que está en borrador.');
            }
            if ($falla = AsientoManual::descuadre($actual)) {
                abort(422, 'El asiento no cuadra: debe '.number_format($falla['debe'], 2, '.', '').', haber '.number_format($falla['haber'], 2, '.', ''));
            }
            $actual->update(['estado' => JournalEntry::PENDIENTE]);

            return $actual->fresh('lines.account');
        });

        return $this->presentar($entry);
    }

    /** @return array<string,mixed> */
    private function validar(Request $r, bool $crea): array
    {
        $reglas = [
            'fecha' => ['required', 'date'],
            'concepto' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'estado' => ['nullable', 'in:'.implode(',', AsientoManual::ESTADOS_GUARDABLES)],
        ];
        if ($crea) {
            $reglas['company_id'] = ['required', 'integer', 'exists:companies,id'];
        }

        return $r->validate($reglas, [
            'fecha.required' => 'Escribe la fecha del asiento.',
            'fecha.date' => 'La fecha del asiento no es válida.',
            'concepto.required' => 'Escribe el concepto del asiento.',
            'concepto.max' => 'El concepto no puede pasar de 255 caracteres.',
            'lines.required' => 'El asiento necesita al menos dos líneas.',
            'lines.array' => 'El asiento necesita al menos dos líneas.',
            'lines.min' => 'El asiento necesita al menos dos líneas.',
            'estado.in' => 'El estado del asiento solo puede ser borrador o pendiente.',
            'company_id.required' => 'Falta la empresa.',
            'company_id.exists' => 'La empresa no existe.',
        ]);
    }

    /**
     * El asiento tiene que ser de la empresa que se pide (company_id) y el plan de esa empresa debe incluir contabilidad.
     * No se limita por la empresa del usuario: el selector de empresas es el mismo para todos los usuarios con sesión
     * (la contadora sembrada pertenece a otra empresa y trabaja en la demo), igual que el resto de la API.
     */
    private function exigirEmpresa(Request $r, int $companyId): void
    {
        if ($r->filled('company_id')) {
            abort_unless((int) $r->input('company_id') === $companyId, 404, 'No se encontró el asiento en esta empresa.');
        }
        $company = Company::find($companyId);
        abort_unless($company, 404, 'No se encontró la empresa.');
        abort_if($company->planVencido(), 402, 'El plan de esta empresa venció. Renueva para seguir usando el sistema.');
        abort_unless($company->tieneFeature('contabilidad'), 402, "Tu plan {$company->plan} no incluye este módulo. Actualiza el plan para activarlo.");
    }

    /** @return array<string,mixed> */
    private function presentar(JournalEntry $e): array
    {
        return AccountingController::presentarAsiento($e, isset(AsientoManual::anulados($e->company_id, $e)[$e->id]));
    }
}
