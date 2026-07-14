<?php
namespace App\Http\Controllers;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use Illuminate\Http\Request;

class AccountingController extends Controller {
    // Libro diario: todos los asientos con sus líneas
    public function journal(Request $r) {
        return JournalEntry::with('lines.account:id,codigo,nombre')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))->latest('fecha')->get();
    }
    // Mayorizar / desmayorizar (poder editar)
    public function mayorizar(Request $r) {
        JournalEntry::where('company_id',$r->company_id)->where('estado','pendiente')->update(['estado'=>'mayorizado']);
        return ['ok'=>true];
    }
    public function desmayorizar(JournalEntry $entry) { $entry->update(['estado'=>'pendiente']); return ['ok'=>true]; }
    // Estado de resultados
    public function incomeStatement(Request $r) {
        $s = $this->saldosPorTipo($r->company_id);
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
    private function saldosPorTipo($companyId): array {
        $rows = JournalEntryLine::join('journal_entries','journal_entries.id','=','journal_entry_lines.journal_entry_id')
            ->join('accounts','accounts.id','=','journal_entry_lines.account_id')
            ->where('journal_entries.company_id',$companyId)
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
            ->where("journal_entries.company_id", $r->company_id)
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
