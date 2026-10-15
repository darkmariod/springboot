<?php
namespace App\Http\Controllers;
use App\Models\Company;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalEntry;
use App\Models\Purchase;
use App\Support\ActivosFijos;
use App\Support\ContraAsiento;
use App\Support\DepreciacionActivos;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Activos fijos (T7.2): la ficha de cada activo, su depreciación mensual en línea recta y su baja. Solo administrador y contador,
 * plan Completo. La contabilidad vive en App\Support\ActivosFijos (alta, compras) y App\Support\DepreciacionActivos (depreciar, baja).
 */
class FixedAssetController extends Controller {
    /** Campos que fijan la cuota y el asiento de alta: no cambian cuando ya hay depreciación contabilizada o el activo está dado de baja. */
    private const BLOQUEADOS = ['costo','valor_residual','vida_util_meses','fecha_inicio_depreciacion','fecha_adquisicion'];

    public function index(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'grupo'=>['nullable', Rule::in(array_keys(FixedAsset::GRUPOS))],
            'estado'=>['nullable', Rule::in([FixedAsset::ACTIVO, FixedAsset::DADO_DE_BAJA])],
            'q'=>['nullable','string','max:100'],
        ]);
        $cid = (int) $d['company_id'];
        $this->exigirUsuarioDeLaEmpresa($r, $cid);

        $activos = FixedAsset::with(['employee:id,nombres','purchase:id,numero'])->where('company_id', $cid)
            ->when($d['grupo'] ?? null, fn($q,$g)=>$q->where('grupo',$g))
            ->when($d['estado'] ?? null, fn($q,$e)=>$q->where('estado',$e))
            ->when(trim((string) ($d['q'] ?? '')) !== '', function($q) use ($d) {
                $t = '%'.trim($d['q']).'%';
                $q->where(fn($w)=>$w->where('codigo','like',$t)->orWhere('nombre','like',$t)->orWhere('serie','like',$t)->orWhere('departamento','like',$t));
            })
            ->orderBy('codigo')->get();
        $hecho = ActivosFijos::resumen($cid, $activos->pluck('id')->all());
        $filas = $activos->map(fn(FixedAsset $a) => $this->presentar($a, $hecho[$a->id] ?? null))->values();

        // Los totales son de los activos que siguen en libros (los dados de baja ya salieron)
        $vigentes = $filas->where('estado', FixedAsset::ACTIVO);
        $costo = $vigentes->sum(fn($f) => ActivosFijos::centavos($f['costo']));
        $acumulada = $vigentes->sum(fn($f) => ActivosFijos::centavos($f['depreciacion_acumulada']));
        return [
            'activos'=>$filas,
            'totales'=>[
                'costo'=>$costo / 100, 'depreciacion_acumulada'=>$acumulada / 100, 'valor_en_libros'=>($costo - $acumulada) / 100,
                'activos'=>$vigentes->count(), 'dados_de_baja'=>$filas->where('estado', FixedAsset::DADO_DE_BAJA)->count(),
            ],
            'grupos'=>collect(FixedAsset::GRUPOS)->map(fn($nombre,$valor)=>['valor'=>$valor,'nombre'=>$nombre])->values(),
        ];
    }

    /** El código que se sugiere al crear una ficha nueva. */
    public function siguienteCodigo(Request $r) {
        $d = $r->validate(['company_id'=>['required','exists:companies,id']]);
        $this->exigirUsuarioDeLaEmpresa($r, (int) $d['company_id']);
        return ['codigo'=>ActivosFijos::siguienteCodigo((int) $d['company_id'])];
    }

    /** Compras de activo fijo (sustento 03 o 04) con algo por repartir en fichas, para ligarlas desde el formulario. */
    public function compras(Request $r) {
        $d = $r->validate(['company_id'=>['required','exists:companies,id']]);
        $this->exigirUsuarioDeLaEmpresa($r, (int) $d['company_id']);
        return ActivosFijos::comprasPendientes((int) $d['company_id']);
    }

    public function show(Request $r, FixedAsset $asset) {
        $cid = $this->empresaDe($r, $asset);
        $hecho = ActivosFijos::resumen($cid, [$asset->id]);
        $asset->load(['employee:id,nombres','purchase:id,numero']);
        return $this->presentar($asset, $hecho[$asset->id] ?? null) + ['cronograma'=>$this->cronograma($asset, $hecho[$asset->id] ?? null)];
    }

    public function store(Request $r) {
        $d = $this->validar($r);
        $cid = (int) $d['company_id'];
        $this->exigirUsuarioDeLaEmpresa($r, $cid);
        $compra = ! empty($d['purchase_id']) ? Purchase::where('company_id', $cid)->find($d['purchase_id']) : null;
        $this->validarCruzado($d, null);

        $a = DB::transaction(function() use ($r, $d, $cid, $compra) {
            // La fila de la empresa se bloquea para que dos altas a la vez no tomen el mismo código
            Company::whereKey($cid)->lockForUpdate()->value('id');
            $codigo = trim((string) ($d['codigo'] ?? ''));
            $contra = $this->contrapartidaDelAlta($r, $d, $compra, null);
            $a = FixedAsset::create([
                'company_id'=>$cid, 'codigo'=>$codigo !== '' ? $codigo : ActivosFijos::siguienteCodigo($cid),
                'nombre'=>trim($d['nombre']), 'descripcion'=>$d['descripcion'] ?? null, 'grupo'=>$d['grupo'],
                'departamento'=>$d['departamento'] ?? null, 'employee_id'=>$d['employee_id'] ?? null,
                'fecha_adquisicion'=>$d['fecha_adquisicion'], 'costo'=>round((float) $d['costo'], 2),
                'valor_residual'=>round((float) ($d['valor_residual'] ?? 0), 2), 'vida_util_meses'=>(int) $d['vida_util_meses'],
                'metodo'=>$d['metodo'] ?? 'linea_recta', 'fecha_inicio_depreciacion'=>$d['fecha_inicio_depreciacion'],
                'estado'=>FixedAsset::ACTIVO, 'purchase_id'=>$compra?->id, 'serie'=>$d['serie'] ?? null, 'observacion'=>$d['observacion'] ?? null,
            ]);
            // Con una compra capitalizada el costo ya está asentado: la ficha solo lo reparte. Si no, el alta asienta Debe Activos fijos / Haber la contrapartida
            if ($contra) {
                $a->update(['journal_entry_alta_id'=>ActivosFijos::asentarAlta($a, $contra)->id]);
            }
            return $a;
        });

        return response()->json($this->fichaFresca($a), 201);
    }

    public function update(Request $r, FixedAsset $asset) {
        $cid = $this->empresaDe($r, $asset);
        $d = $this->validar($r, $asset);
        // La pantalla manda la ficha completa: lo que no viene se queda como estaba
        $d += $asset->only(['codigo','nombre','grupo','fecha_adquisicion','costo','valor_residual','vida_util_meses','fecha_inicio_depreciacion']);
        $d['fecha_adquisicion'] = Carbon::parse($d['fecha_adquisicion'])->toDateString();
        $d['fecha_inicio_depreciacion'] = Carbon::parse($d['fecha_inicio_depreciacion'])->startOfMonth()->toDateString();

        $a = DB::transaction(function() use ($r, $d, $cid, $asset) {
            $a = FixedAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $conDepreciacion = FixedAssetDepreciation::where('fixed_asset_id', $a->id)->exists();
            $bloqueado = $conDepreciacion || $a->estado === FixedAsset::DADO_DE_BAJA;

            $iguales = [
                'costo'=>fn()=>ActivosFijos::centavos($d['costo']) === ActivosFijos::centavos($a->costo),
                'valor_residual'=>fn()=>ActivosFijos::centavos($d['valor_residual'] ?? 0) === ActivosFijos::centavos($a->valor_residual),
                'vida_util_meses'=>fn()=>(int) $d['vida_util_meses'] === (int) $a->vida_util_meses,
                'fecha_inicio_depreciacion'=>fn()=>$d['fecha_inicio_depreciacion'] === $a->fecha_inicio_depreciacion->toDateString(),
                'fecha_adquisicion'=>fn()=>$d['fecha_adquisicion'] === $a->fecha_adquisicion->toDateString(),
            ];
            if ($bloqueado) {
                $motivo = $a->estado === FixedAsset::DADO_DE_BAJA ? 'ya está dado de baja' : 'ya tiene depreciación contabilizada';
                $errores = [];
                foreach (self::BLOQUEADOS as $campo) {
                    if (! $iguales[$campo]()) {
                        $errores[$campo] = ["Este activo $motivo: no se puede cambiar. Los datos generales (nombre, responsable, ubicación...) sí se pueden editar."];
                    }
                }
                if ($errores) {
                    throw ValidationException::withMessages($errores);
                }
            }
            $this->validarCruzado($d, $a);

            // Antes de depreciar: cambiar el costo o la fecha de adquisición mueve el alta. Se reversa la anterior y se registra la nueva
            $cambiaAlta = ! $bloqueado && $a->journal_entry_alta_id && (! $iguales['costo']() || ! $iguales['fecha_adquisicion']());
            $contraPrevia = null;
            if ($cambiaAlta) {
                $anterior = JournalEntry::with('lines')->findOrFail($a->journal_entry_alta_id);
                $contraPrevia = $anterior->lines->first(fn($l) => (float) $l->haber > 0)?->account_id;
                ContraAsiento::de($anterior, "Reversión de alta de activo fijo {$a->codigo}: cambió su costo o su fecha de adquisición", 'MODIFICADO', $a);
            } elseif (! $bloqueado && ! $a->journal_entry_alta_id && $a->purchase_id && ! $iguales['costo']()) {
                $compra = Purchase::where('company_id', $cid)->find($a->purchase_id);
                $this->exigirDentroDeLaCompra($compra, (float) $d['costo'], $a->id);
            }

            // Solo cambia lo que la pantalla mandó; el costo y los plazos ya pasaron por el bloqueo de arriba
            $cambios = array_intersect_key($d, array_flip(['descripcion','departamento','employee_id','serie','observacion','grupo','fecha_adquisicion',
                'fecha_inicio_depreciacion','vida_util_meses']));
            $cambios['nombre'] = trim($d['nombre']);
            $cambios['costo'] = round((float) $d['costo'], 2);
            $cambios['valor_residual'] = round((float) ($d['valor_residual'] ?? 0), 2);
            if (trim((string) ($d['codigo'] ?? '')) !== '') {
                $cambios['codigo'] = trim($d['codigo']);
            }
            $a->update($cambios);
            if ($cambiaAlta) {
                $contra = ActivosFijos::contrapartida($cid, $d['cuenta_contrapartida_id'] ?? $contraPrevia);
                $a->update(['journal_entry_alta_id'=>ActivosFijos::asentarAlta($a->fresh(), $contra)->id]);
            }
            return $a;
        });

        return $this->fichaFresca($a);
    }

    public function destroy(Request $r, FixedAsset $asset) {
        $this->empresaDe($r, $asset);

        DB::transaction(function() use ($asset) {
            $a = FixedAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if ($a->estado === FixedAsset::DADO_DE_BAJA) {
                throw ValidationException::withMessages(['activo'=>['Un activo dado de baja no se elimina: queda como historial.']]);
            }
            if (FixedAssetDepreciation::where('fixed_asset_id', $a->id)->exists()) {
                throw ValidationException::withMessages(['activo'=>['No se puede eliminar: ya tiene depreciación contabilizada. Si ya no lo usas, dalo de baja.']]);
            }
            // El alta se reversa con un contra-asiento (el original queda como rastro)
            if ($a->journal_entry_alta_id && ($alta = JournalEntry::with('lines')->find($a->journal_entry_alta_id))) {
                ContraAsiento::de($alta, "Reversión de alta de activo fijo {$a->codigo}: ficha eliminada", 'ELIMINADO', $a);
            }
            $a->delete();
        });

        return response()->noContent();
    }

    /**
     * Deprecia uno o varios meses: {company_id, anio, mes} o {company_id, desde: 'AAAA-MM', hasta: 'AAAA-MM'}. Con `simular` solo calcula.
     * Cada mes lleva UN asiento para todos los activos, con fecha del último día del mes, y los meses van en orden.
     */
    public function depreciar(Request $r) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'anio'=>['nullable','required_without:desde','integer','between:2000,2100'],
            'mes'=>['nullable','required_without:desde','integer','between:1,12'],
            'desde'=>['nullable','required_without:anio','regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'hasta'=>['nullable','regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'simular'=>['nullable','boolean'],
        ], ['desde.regex'=>'El mes inicial debe tener la forma AAAA-MM.', 'hasta.regex'=>'El mes final debe tener la forma AAAA-MM.']);
        $cid = (int) $d['company_id'];
        $this->exigirUsuarioDeLaEmpresa($r, $cid);
        $simular = $r->boolean('simular');

        $desde = isset($d['desde']) ? ActivosFijos::ymDePeriodo($d['desde']) : ActivosFijos::ym((int) $d['anio'], (int) $d['mes']);
        $hasta = isset($d['hasta']) ? ActivosFijos::ymDePeriodo($d['hasta']) : $desde;
        $hoy = ActivosFijos::ymDe(now());
        if ($desde > $hasta) {
            throw ValidationException::withMessages(['desde'=>['El mes inicial no puede ser posterior al mes final.']]);
        }
        if ($hasta - $desde + 1 > DepreciacionActivos::MAX_MESES) {
            throw ValidationException::withMessages(['desde'=>['Puedes depreciar hasta '.DepreciacionActivos::MAX_MESES.' meses de una vez. Divide el rango.']]);
        }
        if ($hasta > $hoy) {
            throw ValidationException::withMessages(['hasta'=>['No se puede depreciar un mes futuro: el último mes que admite depreciación es '.ActivosFijos::periodo($hoy).'.']]);
        }

        [$plan, $asientos] = DB::transaction(function() use ($cid, $desde, $hasta, $simular) {
            $plan = DepreciacionActivos::planear($cid, $desde, $hasta, null, ! $simular);
            return [$plan, $simular ? [] : DepreciacionActivos::contabilizar($cid, $plan)];
        });

        $meses = array_map(fn($m) => [
            'periodo'=>$m['periodo'], 'anio'=>$m['anio'], 'mes'=>$m['mes'], 'fecha'=>$m['fecha']->toDateString(), 'total'=>$m['total'] / 100,
            'activos'=>array_map(fn($l) => [
                'id'=>$l['activo']->id, 'codigo'=>$l['activo']->codigo, 'nombre'=>$l['activo']->nombre, 'monto'=>$l['monto'] / 100,
                'acumulada_despues'=>$l['acumulada'] / 100, 'valor_en_libros_despues'=>$l['valor_en_libros'] / 100,
            ], $m['lineas']),
            'asiento'=>isset($asientos[$m['ym']]) ? ['id'=>$asientos[$m['ym']]->id, 'numero'=>$asientos[$m['ym']]->numero] : null,
        ], $plan['meses']);
        $depreciados = array_sum(array_map(fn($m) => count($m['lineas']), $plan['meses']));
        $total = array_sum(array_column($plan['meses'], 'total')) / 100;

        return response()->json([
            'simulado'=>$simular, 'desde'=>ActivosFijos::periodo($desde), 'hasta'=>ActivosFijos::periodo($hasta),
            'depreciados'=>$depreciados, 'total'=>$total, 'meses'=>$meses, 'omitidos'=>$plan['omitidos'],
            'pendiente_desde'=>$plan['pendiente_desde'], 'mensaje'=>$this->mensajeDeDepreciar($simular, $depreciados, $total, count($meses), $plan),
        ], $depreciados > 0 && ! $simular ? 201 : 200);
    }

    /** Da de baja un activo: deprecia lo pendiente hasta el mes de la baja y asienta la salida (con venta, pérdida o utilidad). */
    public function baja(Request $r, FixedAsset $asset) {
        $cid = $this->empresaDe($r, $asset);
        $d = $r->validate([
            'fecha'=>['required','date','after_or_equal:'.$asset->fecha_adquisicion->toDateString(),'before_or_equal:'.now()->toDateString()],
            'motivo'=>['required','string','max:250'],
            'valor_venta'=>['nullable','numeric','min:0','max:999999999'],
            'cuenta_cobro'=>['nullable', Rule::in(['bancos','caja'])],
        ], [
            'fecha.after_or_equal'=>'La baja no puede ser anterior a la adquisición del activo ('.$asset->fecha_adquisicion->format('d/m/Y').').',
            'fecha.before_or_equal'=>'La fecha de la baja no puede ser futura.',
            'motivo.required'=>'Escribe el motivo de la baja.',
            'valor_venta.min'=>'El valor de venta no puede ser negativo.',
            'cuenta_cobro.in'=>'La venta se cobra en Bancos o en Caja. Una venta a crédito se registra con una factura.',
        ]);

        $res = DB::transaction(fn() => DepreciacionActivos::darDeBaja(
            $asset, Carbon::parse($d['fecha'])->startOfDay(), trim($d['motivo']),
            isset($d['valor_venta']) ? round((float) $d['valor_venta'], 2) : null, $d['cuenta_cobro'] ?? 'bancos'));

        return [
            'activo'=>$this->fichaFresca($asset),
            'asiento'=>['id'=>$res['asiento']->id, 'numero'=>$res['asiento']->numero],
            'depreciaciones'=>['meses'=>$res['depreciacion_meses'], 'total'=>$res['depreciacion_total']],
            'resultado'=>$res['resultado'],
        ];
    }

    // ------------------------------------------------------------- validación

    private function validar(Request $r, ?FixedAsset $asset = null): array {
        $nuevo = $asset === null;
        $req = $nuevo ? 'required' : 'sometimes';
        $cid = $asset?->company_id ?? $r->input('company_id');
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'codigo'=>['nullable','string','max:30', Rule::unique('fixed_assets','codigo')->where('company_id', $cid)->ignore($asset?->id)],
            'nombre'=>[$req,'string','max:150'],
            'descripcion'=>['nullable','string','max:2000'],
            'grupo'=>[$req, Rule::in(array_keys(FixedAsset::GRUPOS))],
            'departamento'=>['nullable','string','max:100'],
            'employee_id'=>['nullable','integer', Rule::exists('employees','id')->where('company_id', $cid)],
            'fecha_adquisicion'=>[$req,'date'],
            'costo'=>[$req,'numeric','gt:0','max:999999999'],
            'valor_residual'=>['nullable','numeric','min:0','max:999999999'],
            'vida_util_meses'=>[$req,'integer','min:1','max:1200'],
            'metodo'=>['nullable', Rule::in(FixedAsset::METODOS)],
            'fecha_inicio_depreciacion'=>['nullable','date'],
            'serie'=>['nullable','string','max:80'],
            'observacion'=>['nullable','string','max:2000'],
            'purchase_id'=>['nullable','integer', Rule::exists('purchases','id')->where('company_id', $cid)],
            'registrar_alta'=>['nullable','boolean'],
            'cuenta_contrapartida_id'=>['nullable','integer'],
        ], [
            'codigo.unique'=>'Ya existe un activo con el código :input en esta empresa.',
            'nombre.required'=>'Escribe el nombre del activo.', 'grupo.required'=>'Elige el grupo del activo.', 'grupo.in'=>'El grupo del activo no es válido.',
            'fecha_adquisicion.required'=>'Escribe la fecha de adquisición.',
            'costo.required'=>'Escribe el costo del activo.', 'costo.gt'=>'El costo debe ser mayor que cero.',
            'valor_residual.min'=>'El valor residual no puede ser negativo.',
            'vida_util_meses.required'=>'Escribe la vida útil en meses.', 'vida_util_meses.min'=>'La vida útil debe ser de al menos 1 mes.',
            'metodo.in'=>'Por ahora el único método de depreciación es línea recta.',
            'employee_id.exists'=>'El responsable debe ser un empleado de esta empresa.',
            'purchase_id.exists'=>'La compra elegida no existe en esta empresa.',
        ]);
        if ($nuevo) {
            $d['fecha_inicio_depreciacion'] = Carbon::parse($d['fecha_inicio_depreciacion'] ?? $d['fecha_adquisicion'])->startOfMonth()->toDateString();
        } elseif (! empty($d['fecha_inicio_depreciacion'])) {
            $d['fecha_inicio_depreciacion'] = Carbon::parse($d['fecha_inicio_depreciacion'])->startOfMonth()->toDateString();
        } else {
            unset($d['fecha_inicio_depreciacion']);
        }
        return $d;
    }

    /** Reglas que miran varios campos a la vez: el residual no pasa del costo y no se depreciar antes de adquirir. */
    private function validarCruzado(array $d, ?FixedAsset $actual): void {
        $costo = (float) ($d['costo'] ?? $actual?->costo ?? 0);
        $residual = (float) ($d['valor_residual'] ?? 0);
        if (ActivosFijos::centavos($residual) > ActivosFijos::centavos($costo)) {
            throw ValidationException::withMessages(['valor_residual'=>['El valor residual no puede ser mayor que el costo del activo.']]);
        }
        $adquisicion = $d['fecha_adquisicion'] ?? $actual?->fecha_adquisicion?->toDateString();
        $inicio = $d['fecha_inicio_depreciacion'] ?? $actual?->fecha_inicio_depreciacion?->toDateString();
        if ($adquisicion && $inicio && ActivosFijos::ymDe($inicio) < ActivosFijos::ymDe($adquisicion)) {
            throw ValidationException::withMessages(['fecha_inicio_depreciacion'=>['La depreciación no puede empezar antes del mes de adquisición del activo.']]);
        }
    }

    /**
     * La cuenta contra la que se asienta el alta, o null si no hay que asentar nada: la ficha ligada a una compra ya capitalizada (su costo
     * ya está en 1.2.01) o un activo que el usuario declara ya registrado en la contabilidad.
     */
    private function contrapartidaDelAlta(Request $r, array $d, ?Purchase $compra, ?int $exceptoActivo) {
        if ($compra && ActivosFijos::capitalizadoDe($compra) > 0) {
            $this->exigirDentroDeLaCompra($compra, (float) $d['costo'], $exceptoActivo);
            return null;
        }
        if ($compra) {
            $this->exigirDentroDeLaCompra($compra, (float) $d['costo'], $exceptoActivo);
        }
        if (! $r->boolean('registrar_alta', true)) {
            return null;
        }
        if (empty($d['cuenta_contrapartida_id'])) {
            throw ValidationException::withMessages(['cuenta_contrapartida_id'=>[$compra
                ? 'Esta compra no se capitalizó (se importó del XML o tiene otro sustento). Elige la cuenta donde se asentó —Inventario, Compras o Gastos— para reclasificarla a Activos fijos.'
                : 'Elige la cuenta contra la que se registra el alta del activo (Bancos, Caja, Capital...). Si el activo ya está en la contabilidad, desmarca «Registrar el alta».']]);
        }
        return ActivosFijos::contrapartida((int) $d['company_id'], $d['cuenta_contrapartida_id']);
    }

    private function exigirDentroDeLaCompra(?Purchase $compra, float $costo, ?int $exceptoActivo): void {
        if (! $compra) {
            return;
        }
        $disponible = ActivosFijos::disponibleDe($compra, $exceptoActivo);
        if (ActivosFijos::centavos($costo) > ActivosFijos::centavos($disponible)) {
            throw ValidationException::withMessages(['costo'=>['El costo ($'.number_format($costo, 2).') supera lo que queda por repartir de la compra '.$compra->numero.' ($'.number_format(max(0, $disponible), 2).').']]);
        }
    }

    // ------------------------------------------------------------- presentación

    /** La ficha con todo lo que la pantalla muestra: lo acumulado, el valor en libros, los meses que faltan y qué se puede hacer con ella. */
    private function presentar(FixedAsset $a, ?array $hecho): array {
        $acumulada = $hecho['acumulada'] ?? 0;
        $meses = $hecho['meses'] ?? 0;
        $depreciable = ActivosFijos::depreciableCentavos($a);
        $baja = $a->estado === FixedAsset::DADO_DE_BAJA;
        $completo = $acumulada >= $depreciable;
        $inicio = ActivosFijos::ymDe($a->fecha_inicio_depreciacion);
        $proximo = isset($hecho['ultimo']) ? $hecho['ultimo'] + 1 : $inicio;

        return $a->attributesToArray() + [
            'grupo_nombre'=>FixedAsset::GRUPOS[$a->grupo] ?? $a->grupo,
            'responsable'=>$a->employee?->nombres,
            'compra_numero'=>$a->purchase?->numero,
            'depreciacion_acumulada'=>$acumulada / 100,
            'valor_en_libros'=>$baja ? 0 : (ActivosFijos::centavos($a->costo) - $acumulada) / 100,
            'meses_depreciados'=>$meses,
            'meses_restantes'=>$baja || $completo ? 0 : max(0, (int) $a->vida_util_meses - $meses),
            'cuota_mensual'=>ActivosFijos::cuotaCentavos($depreciable, (int) $a->vida_util_meses) / 100,
            'proximo_mes'=>$baja || $completo ? null : ActivosFijos::periodo($proximo),
            'puede_editar_costos'=>! $baja && $meses === 0,
            'puede_eliminar'=>! $baja && $meses === 0,
        ];
    }

    private function fichaFresca(FixedAsset $a): array {
        $a = FixedAsset::with(['employee:id,nombres','purchase:id,numero'])->findOrFail($a->id);
        return $this->presentar($a, ActivosFijos::resumen((int) $a->company_id, [$a->id])[$a->id] ?? null);
    }

    /**
     * El cronograma completo: los meses ya contabilizados (con su asiento) y los que faltan, calculados con la misma regla.
     * Un activo dado de baja solo muestra lo que se depreció.
     */
    private function cronograma(FixedAsset $a, ?array $hecho): array {
        $filas = FixedAssetDepreciation::where('fixed_asset_id', $a->id)->get()->keyBy(fn($f) => ActivosFijos::ym($f->anio, $f->mes));
        $asientos = JournalEntry::whereIn('id', $filas->pluck('journal_entry_id')->filter()->all())->get(['id','numero'])->keyBy('id');
        $depreciable = ActivosFijos::depreciableCentavos($a);
        $vida = (int) $a->vida_util_meses;
        $inicio = ActivosFijos::ymDe($a->fecha_inicio_depreciacion);
        $costo = ActivosFijos::centavos($a->costo);
        $baja = $a->estado === FixedAsset::DADO_DE_BAJA;

        $out = [];
        $anterior = 0;
        for ($n = 1; $n <= $vida; $n++) {
            $ym = $inicio + $n - 1;
            $fila = $filas->get($ym);
            if ($fila) {
                $monto = ActivosFijos::centavos($fila->monto);
                $acumulada = ActivosFijos::centavos($fila->depreciacion_acumulada_despues);
            } elseif ($baja) {
                continue;
            } else {
                $acumulada = max($anterior, ActivosFijos::acumuladaTrasMes($depreciable, $vida, $n));
                $monto = $acumulada - $anterior;
            }
            $anterior = $acumulada;
            $e = $fila && $fila->journal_entry_id ? $asientos->get($fila->journal_entry_id) : null;
            $out[] = [
                'numero_mes'=>$n, 'periodo'=>ActivosFijos::periodo($ym), 'monto'=>$monto / 100, 'acumulada'=>$acumulada / 100,
                'valor_en_libros'=>($costo - $acumulada) / 100, 'estado'=>$fila ? 'contabilizado' : 'pendiente',
                'asiento'=>$e ? ['id'=>$e->id, 'numero'=>$e->numero] : null,
            ];
        }
        return $out;
    }

    private function mensajeDeDepreciar(bool $simular, int $depreciados, float $total, int $meses, array $plan): string {
        $dinero = '$'.number_format($total, 2);
        if ($depreciados > 0) {
            $texto = ($depreciados === 1 ? '1 activo' : "$depreciados depreciaciones")." por $dinero en ".($meses === 1 ? '1 mes' : "$meses meses").'.';
            return $simular ? "Simulación: se depreciaría $texto" : "Se depreció $texto";
        }
        $motivos = array_column($plan['omitidos'], 'motivo');
        if (in_array('pendiente_anterior', $motivos, true)) {
            return 'Hay activos con meses anteriores sin depreciar (desde '.$plan['pendiente_desde'].'). Depreciar ese mes solo no basta: incluye también los meses anteriores.';
        }
        if (in_array('ya_depreciado', $motivos, true)) {
            return 'Ya está depreciado: los activos de ese período ya tienen su depreciación contabilizada.';
        }
        return 'No hay activos por depreciar en ese período.';
    }

    // ------------------------------------------------------------- empresa

    /** La empresa pedida debe ser la del activo (404 si no) y el usuario debe poder verla. */
    private function empresaDe(Request $r, FixedAsset $asset): int {
        $d = $r->validate(['company_id'=>['required','exists:companies,id']]);
        abort_unless((int) $d['company_id'] === (int) $asset->company_id, 404, 'No se encontró el activo en esta empresa.');
        $this->exigirUsuarioDeLaEmpresa($r, (int) $asset->company_id);
        return (int) $asset->company_id;
    }

    private function exigirUsuarioDeLaEmpresa(Request $r, int $empresa): void {
        $u = $r->user();
        abort_if($u && ! $u->esAdmin() && $u->company_id && (int) $u->company_id !== $empresa, 404, 'No se encontró la empresa.');
    }
}
