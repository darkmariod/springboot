<?php
namespace App\Http\Controllers;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Support\AsientoManual;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountingController extends Controller {
    // Libro diario: los asientos con sus líneas. Filtros opcionales (todos juntos): desde, hasta (o mes y anio), estado, q (número o concepto).
    // Los reportes (libro mayor, estados financieros) cuentan los asientos pendientes y los mayorizados; los borradores no cuentan.
    public function journal(Request $r) {
        [$desde, $hasta] = $this->periodo($r);
        $entradas = JournalEntry::with('lines.account:id,codigo,nombre')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->when($desde, fn($q)=>$q->whereDate('fecha','>=',$desde))
            ->when($hasta, fn($q)=>$q->whereDate('fecha','<=',$hasta))
            ->when($r->estado, fn($q,$e)=>$q->where('estado',$e))
            ->when(trim((string) $r->q) !== '', function($q) use ($r) {
                $t = '%'.trim((string) $r->q).'%';
                $q->where(fn($w)=>$w->where('numero','like',$t)->orWhere('concepto','like',$t));
            })
            ->orderByDesc('fecha')->orderByDesc('id')->get();

        // Los asientos anulados se reconocen por su contra-asiento, una consulta por empresa
        $anulados = [];
        foreach ($entradas->pluck('company_id')->unique() as $cid) {
            $anulados += AsientoManual::anulados((int) $cid);
        }
        $cerrados = [];
        foreach ($entradas->pluck('company_id')->unique() as $cid) {
            $cerrados[(int) $cid] = AccountingPeriod::cerradosDe((int) $cid);
        }
        return $entradas->map(fn($e)=>self::presentarAsiento($e, isset($anulados[$e->id]), isset($cerrados[(int) $e->company_id][$e->fecha->format('Y-m')])))->values();
    }

    /**
     * El asiento con lo que la pantalla necesita para decidir qué botones mostrar. Un asiento de un mes cerrado no se edita,
     * no se elimina ni se desmayoriza (hay que reabrir el período); el asiento de cierre del ejercicio nunca se edita.
     */
    public static function presentarAsiento(JournalEntry $e, bool $anulado = false, ?bool $periodoCerrado = null): array {
        $esContra = $e->esContraAsiento();
        $abierto = in_array($e->estado, AsientoManual::ESTADOS_GUARDABLES, true);
        $cerrado = $periodoCerrado ?? AccountingPeriod::estaCerrado((int) $e->company_id, $e->fecha);
        return $e->toArray() + [
            'es_contra' => $esContra,
            'anulado' => $anulado,
            'descuadrado' => AsientoManual::descuadre($e) !== null,
            'periodo_cerrado' => $cerrado,
            'puede_editar' => $abierto && ! $esContra && ! $anulado && ! $cerrado && ! $e->es_cierre,
            'puede_eliminar' => $e->manual && $abierto && ! $e->fue_mayorizado && ! $cerrado,
            'puede_desmayorizar' => $e->estado === JournalEntry::MAYORIZADO && ! $cerrado && ! $e->es_cierre,
        ];
    }

    /** Desde/hasta del filtro: las fechas dadas, o el mes completo si vienen mes y anio. */
    private function periodo(Request $r): array {
        $desde = $r->desde ?: null; $hasta = $r->hasta ?: null;
        if ($r->filled('mes') && $r->filled('anio') && (int) $r->mes >= 1 && (int) $r->mes <= 12 && (int) $r->anio >= 1900) {
            $inicio = Carbon::create((int) $r->anio, (int) $r->mes, 1);
            $desde = max($desde ?? '0000-00-00', $inicio->toDateString());
            $fin = $inicio->copy()->endOfMonth()->toDateString();
            $hasta = $hasta === null ? $fin : min($hasta, $fin);
        }
        return [$desde, $hasta];
    }

    // Mayorizar: solo los asientos PENDIENTES y cuadrados. Sin filtros mayoriza todos los pendientes de la empresa (como siempre);
    // con ids, desde/hasta o mes y anio, solo esos. Lo que no se mayoriza vuelve en `omitidos` con su motivo.
    public function mayorizar(Request $r) {
        $d = $r->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'ids' => ['nullable', 'array'], 'ids.*' => ['integer'],
            'desde' => ['nullable', 'date'], 'hasta' => ['nullable', 'date'],
            'mes' => ['nullable', 'integer', 'between:1,12'], 'anio' => ['nullable', 'integer', 'between:2000,2100'],
        ], [
            'company_id.required' => 'Falta la empresa.',
            'desde.date' => 'La fecha desde no es válida.', 'hasta.date' => 'La fecha hasta no es válida.',
            'mes.between' => 'El mes debe estar entre 1 y 12.', 'anio.between' => 'El año no es válido.',
        ]);
        $cid = (int) $d['company_id'];
        $this->exigirEmpresa($r, $cid);
        if ($r->filled('mes') xor $r->filled('anio')) abort(422, 'Para elegir un mes indica también el año.');
        [$desde, $hasta] = $this->periodo($r);
        $ids = array_values(array_unique(array_map('intval', $d['ids'] ?? [])));
        if (array_key_exists('ids', $d) && ! $ids) {
            return ['ok'=>true, 'mayorizados'=>0, 'numeros'=>[], 'omitidos'=>[]];
        }

        $omitidos = [];
        $listos = [];
        $candidatos = JournalEntry::where('company_id', $cid)->with('lines')
            ->when($ids, fn($q)=>$q->whereIn('id', $ids), fn($q)=>$q->whereIn('estado', AsientoManual::ESTADOS_GUARDABLES)
                ->when($desde, fn($w)=>$w->whereDate('fecha','>=',$desde))->when($hasta, fn($w)=>$w->whereDate('fecha','<=',$hasta)))
            ->orderBy('fecha')->orderBy('id')->get();

        foreach ($ids as $id) {
            if (! $candidatos->contains('id', $id)) $omitidos[] = ['numero'=>"#$id", 'motivo'=>'No se encontró el asiento en esta empresa.'];
        }
        $cerrados = AccountingPeriod::cerradosDe($cid);
        foreach ($candidatos as $e) {
            $fecha = $e->fecha->toDateString();
            if ($e->estado === JournalEntry::MAYORIZADO) { $omitidos[] = ['numero'=>$e->numero, 'motivo'=>'Ya está mayorizado.']; continue; }
            if (isset($cerrados[$e->fecha->format('Y-m')])) { $omitidos[] = ['numero'=>$e->numero, 'motivo'=>AccountingPeriod::mensajeCerrado($e->fecha->format('Y-m'))]; continue; }
            if (($desde && $fecha < $desde) || ($hasta && $fecha > $hasta)) { $omitidos[] = ['numero'=>$e->numero, 'motivo'=>'Está fuera del período elegido.']; continue; }
            if ($e->estado === JournalEntry::BORRADOR) { $omitidos[] = ['numero'=>$e->numero, 'motivo'=>'Es un borrador: contabilízalo primero.']; continue; }
            if ($falla = AsientoManual::descuadre($e)) { $omitidos[] = ['numero'=>$e->numero, 'motivo'=>$falla['motivo']]; continue; }
            $listos[$e->id] = $e->numero;
        }

        if ($listos) {
            DB::transaction(function () use ($listos, $cid) {
                JournalEntry::where('company_id', $cid)->whereIn('id', array_keys($listos))
                    ->update(['estado' => JournalEntry::MAYORIZADO, 'fue_mayorizado' => true]);
                JournalEntry::auditarAccion('mayorizo_asientos', count($listos).' asientos mayorizados', ['numeros' => array_values($listos)], $cid);
            });
        }
        return ['ok'=>true, 'mayorizados'=>count($listos), 'numeros'=>array_values($listos), 'omitidos'=>$omitidos];
    }

    // Desmayorizar: el asiento vuelve a pendiente para poder editarlo
    public function desmayorizar(Request $r, JournalEntry $entry) {
        $this->exigirEmpresa($r, (int) $entry->company_id);
        // El mes cerrado se avisa primero (antes que cualquier otro motivo): hay que reabrir el período
        AccountingPeriod::exigirAbierto((int) $entry->company_id, $entry->fecha);
        if ($entry->es_cierre) abort(422, 'El asiento de cierre del ejercicio no se desmayoriza.');
        if ($entry->estado === JournalEntry::BORRADOR) abort(422, 'Un borrador no está mayorizado.');
        if ($entry->estado === JournalEntry::MAYORIZADO) $entry->update(['estado'=>JournalEntry::PENDIENTE]);
        return ['ok'=>true];
    }

    /** La empresa pedida debe ser la del asiento y su plan incluir contabilidad (el selector de empresas es global, como en el resto de la API). */
    private function exigirEmpresa(Request $r, int $companyId): void {
        if ($r->filled('company_id')) abort_unless((int) $r->input('company_id') === $companyId, 404, 'No se encontró el asiento en esta empresa.');
        $company = Company::find($companyId);
        abort_unless($company, 404, 'No se encontró la empresa.');
        abort_unless($company->tieneFeature('contabilidad'), 402, "Tu plan {$company->plan} no incluye este módulo. Actualiza el plan para activarlo.");
    }
    // Estado de resultados
    // (el asiento de cierre del ejercicio no cuenta: pasa el resultado a patrimonio, y aquí se quiere ver lo que se vendió y se gastó)
    public function incomeStatement(Request $r) {
        $s = $this->saldosPorTipo($r->company_id, true);
        $ing = $s['ingreso'] ?? 0; $gas = $s['gasto'] ?? 0;
        return ['ingresos'=>round($ing,2),'gastos'=>round($gas,2),'utilidad_ejercicio'=>round($ing-$gas,2)];
    }
    // Balance general
    public function balanceSheet(Request $r) {
        $s = $this->saldosPorTipo($r->company_id);
        $act = $s['activo'] ?? 0; $pas = $s['pasivo'] ?? 0; $pat = $s['patrimonio'] ?? 0;
        $util = ($s['ingreso'] ?? 0) - ($s['gasto'] ?? 0);
        return ['activos'=>round($act,2),'pasivos'=>round($pas,2),'patrimonio'=>round($pat+$util,2),
            'cuadrado'=>round($act,2)===round($pas+$pat+$util,2)];
    }
    private function saldosPorTipo($companyId, bool $excluirCierre = false): array {
        $rows = JournalEntryLine::join('journal_entries','journal_entries.id','=','journal_entry_lines.journal_entry_id')
            ->join('accounts','accounts.id','=','journal_entry_lines.account_id')
            ->where('journal_entries.company_id',$companyId)->where('journal_entries.estado','!=',JournalEntry::BORRADOR)
            ->when($excluirCierre, fn($q)=>$q->where('journal_entries.es_cierre', false))
            ->selectRaw('accounts.tipo, SUM(journal_entry_lines.debe) as d, SUM(journal_entry_lines.haber) as h')
            ->groupBy('accounts.tipo')->get();
        $saldos = [];
        foreach ($rows as $r) {
            $neto = in_array($r->tipo,['activo','gasto']) ? ($r->d - $r->h) : ($r->h - $r->d);
            $saldos[$r->tipo] = ($saldos[$r->tipo] ?? 0) + $neto;
        }
        return $saldos;
    }

    // Libro mayor: agrupado por cuenta contable
    public function ledger(\Illuminate\Http\Request $r)
    {
        $rows = \App\Models\JournalEntryLine::join("journal_entries", "journal_entries.id", "=", "journal_entry_lines.journal_entry_id")
            ->join("accounts", "accounts.id", "=", "journal_entry_lines.account_id")
            ->where("journal_entries.company_id", $r->company_id)->where("journal_entries.estado", "!=", JournalEntry::BORRADOR)
            ->selectRaw("accounts.codigo, accounts.nombre, accounts.tipo, SUM(journal_entry_lines.debe) as debe, SUM(journal_entry_lines.haber) as haber, COUNT(*) as movimientos")
            ->groupBy("accounts.codigo", "accounts.nombre", "accounts.tipo")->orderBy("accounts.codigo")->get()
            ->map(function ($a) {
                $saldo = in_array($a->tipo, ["activo", "gasto"]) ? $a->debe - $a->haber : $a->haber - $a->debe;
                return ["codigo" => $a->codigo, "nombre" => $a->nombre, "tipo" => $a->tipo,
                    "debe" => round($a->debe, 2), "haber" => round($a->haber, 2),
                    "saldo" => round($saldo, 2), "movimientos" => $a->movimientos];
            });
        return $rows;
    }
}
