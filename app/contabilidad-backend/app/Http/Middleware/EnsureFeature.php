<?php
namespace App\Http\Middleware;
use App\Models\Company;
use Closure;
use Illuminate\Http\Request;

class EnsureFeature {
    public function handle(Request $request, Closure $next, string $feature) {
        $companyId = $request->input('company_id') ?? $request->query('company_id');
        $company = $companyId ? Company::find($companyId) : null;
        if (! $company) return $next($request);

        if ($company->planVencido()) {
            abort(402, 'El plan de esta empresa venció. Renová para seguir usando el sistema.');
        }
        if (! $company->tieneFeature($feature)) {
            abort(402, "Tu plan {$company->plan} no incluye este módulo. Actualizá el plan para activarlo.");
        }
        return $next($request);
    }
}
