<?php
namespace App\Http\Controllers;

use App\Models\CardSettlement;
use App\Models\CardTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CardReconciliationController extends Controller
{
    /** Cobros con tarjeta que el procesador todavía no deposita. */
    public function pendientes(Request $r)
    {
        return CardTransaction::with('invoice:id,numero,fecha_emision')
            ->where('company_id', $r->company_id)
            ->pendientes()
            ->when($r->desde, fn ($q, $d) => $q->whereDate('fecha', '>=', $d))
            ->when($r->hasta, fn ($q, $h) => $q->whereDate('fecha', '<=', $h))
            ->orderBy('fecha')->get();
    }

    public function liquidaciones(Request $r)
    {
        return CardSettlement::withCount('transactions')
            ->withSum('transactions', 'monto')
            ->where('company_id', $r->company_id)
            ->orderByDesc('fecha')->get()
            ->map(function ($s) {
                $s->asignado  = round((float) $s->transactions_sum_monto, 2);
                $s->diferencia = round($s->asignado - (float) $s->monto_bruto, 2);
                return $s;
            });
    }

    public function crearLiquidacion(Request $r)
    {
        $d = $r->validate([
            'company_id'  => ['required', 'exists:companies,id'],
            'fecha'       => ['required', 'date'],
            'procesador'  => ['required', 'string', 'max:80'],
            'lote'        => ['nullable', 'string', 'max:60'],
            'monto_bruto' => ['required', 'numeric', 'min:0'],
            'comision'    => ['nullable', 'numeric', 'min:0'],
            'notas'       => ['nullable', 'string', 'max:400'],
        ]);
        $d['comision'] = $d['comision'] ?? 0;
        // Lo que el banco realmente deposita es el bruto menos su comisión.
        $d['monto_neto'] = round($d['monto_bruto'] - $d['comision'], 2);

        return response()->json(CardSettlement::create($d), 201);
    }

    /** Asigna cobros a una liquidación (o los libera si se manda vacío). */
    public function asignar(Request $r, CardSettlement $settlement)
    {
        $d = $r->validate(['transaction_ids' => ['present', 'array'],
                           'transaction_ids.*' => ['integer', 'exists:card_transactions,id']]);

        DB::transaction(function () use ($d, $settlement) {
            CardTransaction::where('card_settlement_id', $settlement->id)
                ->update(['card_settlement_id' => null]);
            if ($d['transaction_ids']) {
                CardTransaction::whereIn('id', $d['transaction_ids'])
                    ->where('company_id', $settlement->company_id)
                    ->update(['card_settlement_id' => $settlement->id]);
            }
            $asignado = (float) $settlement->transactions()->sum('monto');
            $settlement->update([
                'estado' => abs($asignado - (float) $settlement->monto_bruto) < 0.01 && $asignado > 0
                    ? 'conciliada' : 'abierta',
            ]);
        });

        return $settlement->fresh()->loadCount('transactions');
    }

    /** Sugiere qué cobros cuadran con el monto de la liquidación. */
    public function sugerir(Request $r, CardSettlement $settlement)
    {
        $pend = CardTransaction::where('company_id', $settlement->company_id)
            ->pendientes()->whereDate('fecha', '<=', $settlement->fecha)
            ->orderBy('fecha')->get();

        $objetivo = (float) $settlement->monto_bruto;
        $suma = 0; $elegidos = [];
        foreach ($pend as $t) {                     // se acumulan sin pasarse del monto
            if (round($suma + (float) $t->monto, 2) <= $objetivo + 0.001) {
                $elegidos[] = $t->id;
                $suma = round($suma + (float) $t->monto, 2);
            }
        }

        return ['transaction_ids' => $elegidos, 'suma' => $suma,
                'objetivo' => $objetivo, 'cuadra' => abs($suma - $objetivo) < 0.01];
    }

    public function eliminarLiquidacion(CardSettlement $settlement)
    {
        CardTransaction::where('card_settlement_id', $settlement->id)->update(['card_settlement_id' => null]);
        $settlement->delete();

        return response()->noContent();
    }

    /** Resumen para la cabecera de la pantalla. */
    public function resumen(Request $r)
    {
        $base = CardTransaction::where('company_id', $r->company_id);

        return [
            'pendientes'       => (clone $base)->pendientes()->count(),
            'monto_pendiente'  => round((float) (clone $base)->pendientes()->sum('monto'), 2),
            'conciliado'       => round((float) (clone $base)->whereNotNull('card_settlement_id')->sum('monto'), 2),
            'comisiones'       => round((float) CardSettlement::where('company_id', $r->company_id)->sum('comision'), 2),
            'liquidaciones_abiertas' => CardSettlement::where('company_id', $r->company_id)->where('estado', 'abierta')->count(),
        ];
    }
}
