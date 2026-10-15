<?php
namespace App\Http\Middleware;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;

/**
 * Exige que el plan de la empresa incluya una función: `feature:cartera`.
 *
 * Opción `salvo_interna` (`feature:facturacion_sri,salvo_interna`): las notas de crédito y de débito
 * internas no pasan por el SRI, así que solo las del SRI (`tipo` distinto de `interna`) exigen la función.
 * El plan vencido bloquea igual en los dos casos.
 */
class EnsureFeature {
    public function handle(Request $request, Closure $next, string $feature, string ...$opciones) {
        $companyId = $request->input('company_id') ?? $request->query('company_id');
        $company = $companyId ? Company::find($companyId) : null;
        if (! $company) return $next($request);

        if ($company->planVencido()) {
            abort(402, 'El plan de esta empresa venció. Renueva para seguir usando el sistema.');
        }
        if (in_array('salvo_interna', $opciones, true) && $request->input('tipo') === 'interna') {
            return $next($request);
        }
        if (! $company->tieneFeature($feature)) {
            abort(402, "Tu plan {$company->plan} no incluye este módulo. Actualiza el plan para activarlo.");
        }
        return $next($request);
    }
}
