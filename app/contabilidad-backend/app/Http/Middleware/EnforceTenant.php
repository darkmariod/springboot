<?php
namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Product;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Aislamiento entre empresas (T11.6): un usuario de una empresa solo ve y modifica lo de ella.
 *
 * Quién queda confinado: el usuario con `company_id` que no es superadministrador (`User::estaConfinadoAEmpresa`). El superadmin
 * (el operador de la plataforma) y el usuario sin empresa pasan sin que se les toque nada.
 *
 * Para un usuario confinado, en cada petición:
 *   1. `company_id` (en la consulta, en el cuerpo, a cualquier profundidad) tiene que ser el suyo; si no, 403.
 *   2. Si la petición no trae `company_id`, se le pone el suyo. Casi todos los controladores filtran con `when($r->company_id, ...)`:
 *      sin esto, omitirlo devolvía las filas de TODAS las empresas. También hace que `feature:` evalúe el plan de la empresa del
 *      usuario y no se lo salte.
 *   3. Todo modelo ligado en la URL (`/contacts/{contact}`) con `company_id` de otra empresa, o la empresa misma (`/companies/{company}`)
 *      si no es la suya, da 403. La ligadura de modelos corre ANTES de este middleware, así que un id inexistente llega como
 *      «no encontrado»: `bootstrap/app.php` lo responde con este mismo 403 para el usuario confinado, y no se revela si el id existe
 *      en otra empresa.
 *   4. Un campo que apunta a un registro de otra empresa (`contact_id`, `items.*.warehouse_id`, `quote_ids`...) da 403 (ver REFERENCIAS).
 *
 * Lo que este middleware NO puede cerrar (consultas sin filtro en el propio controlador, ids que no siguen estas claves) está
 * descrito en la bitácora de la Fase 11 del documento de la función.
 */
class EnforceTenant
{
    public const MENSAJE = 'No tienes acceso a esa empresa.';

    /**
     * Claves de petición que llevan el id de un registro de una empresa → tabla donde vive (todas tienen `company_id`).
     * Un campo de estos con el id de otra empresa se rechaza aunque `company_id` diga la verdad.
     */
    public const REFERENCIAS = [
        'contact_id' => 'contacts',
        'product_id' => 'products', 'product_from_id' => 'products', 'product_to_id' => 'products', 'child_product_id' => 'products',
        'component_id' => 'products',
        'warehouse_id' => 'warehouses', 'warehouse_origen_id' => 'warehouses', 'warehouse_destino_id' => 'warehouses', 'bodega_id' => 'warehouses',
        'bank_id' => 'banks',
        'invoice_id' => 'invoices', 'factura_referencia_id' => 'invoices',
        'purchase_id' => 'purchases',
        'employee_id' => 'employees',
        'emission_point_id' => 'emission_points',
        'user_id' => 'users',
        'quote_ids' => 'quotes',
        'credit_note_id' => 'credit_notes',
        'card_settlement_id' => 'card_settlements', 'transaction_ids' => 'card_transactions',
        'cuenta_contrapartida_id' => 'accounts', 'cuenta_contable_id' => 'accounts', 'account_id' => 'accounts', 'parent_id' => 'accounts',
        'journal_entry_id' => 'journal_entries', 'journal_entry_alta_id' => 'journal_entries',
        'cash_register_id' => 'cash_registers',
        'physical_count_id' => 'physical_counts',
        'inventory_movement_id' => 'inventory_movements',
    ];

    /**
     * Claves `*_id` que los controladores leen y que NO apuntan a un registro que se pueda ubicar en una empresa con `company_id`
     * (polimórficas, o el id de un movimiento que cada controlador ya compara con su documento). Sirve para que una clave nueva no
     * pase sin que alguien decida a qué lado va (lo comprueba AislamientoEntreEmpresasTest).
     */
    public const SIN_REFERENCIA = [
        'origen_id', 'modelo_id', 'pagable_id', 'documentable_id', 'movimiento_id',
    ];

    /** Cuántos ids se consultan por vez. */
    private const LOTE = 500;

    public function handle(Request $request, Closure $next)
    {
        $usuario = $request->user();
        if (! $usuario || ! $usuario->estaConfinadoAEmpresa()) {
            return $next($request);
        }
        $propia = (int) $usuario->company_id;

        // Se miran por separado la consulta y el cuerpo: `$request->input()` deja al cuerpo tapar a la consulta, y un controlador
        // que lea `$request->query('company_id')` vería el que el cuerpo escondió.
        $fuentes = [$request->query->all(), $request->request->all(), $request->json()->all()];

        $empresas = [];
        $referencias = [];
        foreach ($fuentes as $fuente) {
            $this->recolectar($fuente, $empresas, $referencias);
        }

        foreach ($empresas as $valor) {
            if ($this->hayValor($valor) && (! is_scalar($valor) || (string) $valor !== (string) $propia)) {
                return $this->negar();
            }
        }

        foreach ($request->route()?->parameters() ?? [] as $parametro) {
            if ($parametro instanceof Model && ($dueno = $this->empresaDe($parametro)) !== null && $dueno !== $propia) {
                return $this->negar();
            }
        }

        foreach ($referencias as $tabla => $ids) {
            if ($this->hayDeOtraEmpresa($tabla, array_keys($ids), $propia)) {
                return $this->negar();
            }
        }

        // Sin company_id, o con uno vacío, se le pone el suyo (el cuerpo y, si la trae, la consulta)
        $request->merge(['company_id' => $propia]);
        if ($request->query->has('company_id')) {
            $request->query->set('company_id', $propia);
        }

        return $next($request);
    }

    private function negar()
    {
        return response()->json(['message' => self::MENSAJE], 403);
    }

    private function hayValor(mixed $valor): bool
    {
        return $valor !== null && $valor !== '' && $valor !== [];
    }

    /**
     * Junta todos los `company_id` que trae la petición y los ids de las claves de REFERENCIAS, a cualquier profundidad.
     *
     * @param  array<int,mixed>  $empresas
     * @param  array<string,array<int,true>>  $referencias  tabla → [id => true]
     */
    private function recolectar(mixed $datos, array &$empresas, array &$referencias): void
    {
        if (! is_array($datos)) {
            return;
        }
        foreach ($datos as $clave => $valor) {
            if ($clave === 'company_id') {
                $empresas[] = $valor;

                continue;
            }
            if (is_string($clave) && isset(self::REFERENCIAS[$clave])) {
                foreach ((array) $valor as $id) {
                    if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                        $referencias[self::REFERENCIAS[$clave]][(int) $id] = true;
                    }
                }
            }
            if (is_array($valor)) {
                $this->recolectar($valor, $empresas, $referencias);
            }
        }
    }

    /** ¿Alguno de esos ids es de un registro de otra empresa? (los registros sin empresa no cuentan) */
    private function hayDeOtraEmpresa(string $tabla, array $ids, int $propia): bool
    {
        foreach (array_chunk($ids, self::LOTE) as $lote) {
            $ajeno = DB::table($tabla)->whereIn('id', $lote)->whereNotNull('company_id')->where('company_id', '!=', $propia)->exists();
            if ($ajeno) {
                return true;
            }
        }

        return false;
    }

    /**
     * La empresa a la que pertenece un modelo ligado por la URL, o null si no se puede saber.
     * La propia empresa se compara por su id. Los hijos de un artículo (listas de precios, componentes, códigos) no llevan
     * `company_id`: son de la empresa del artículo.
     */
    private function empresaDe(Model $modelo): ?int
    {
        if ($modelo instanceof Company) {
            return (int) $modelo->getKey();
        }
        $empresa = $modelo->getAttribute('company_id');
        if ($empresa !== null) {
            return (int) $empresa;
        }
        $articulo = $modelo->getAttribute('product_id');
        if ($articulo !== null) {
            $empresa = Product::whereKey($articulo)->value('company_id');

            return $empresa === null ? null : (int) $empresa;
        }

        return null;
    }
}
