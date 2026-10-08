<?php
namespace App\Http\Controllers;
use App\Models\Company;
use App\Models\PendingImport;
use App\Services\SriXmlDownloader;
use App\Services\StorePurchaseFromXml;
use App\Support\SriTxtComprobantes;
use Illuminate\Http\Request;

class PendingImportController extends Controller {
    public function index(Request $r) {
        return PendingImport::where('company_id', $r->company_id)->latest()->get();
    }
    /**
     * Sube el TXT de comprobantes recibidos del SRI. Cada línea con clave de acceso queda en la lista:
     * las facturas (01) como "pendiente" para traer su XML; los demás comprobantes (retenciones, notas de crédito
     * y de débito, guías, liquidaciones) como "omitido" con el motivo, no como error. Ver App\Support\SriTxtComprobantes.
     */
    public function uploadTxt(Request $r) {
        $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'txt'=>['required','file','max:8192'],
        ]);
        $lectura = SriTxtComprobantes::leer(file_get_contents($r->file('txt')->getRealPath()));
        $insertadas = 0; $omitidas = 0; $repetidas = 0;
        foreach ($lectura['filas'] as $f) {
            $p = PendingImport::firstOrCreate(
                ['clave_acceso'=>$f['clave_acceso']],
                ['company_id'=>$r->company_id,
                 'ruc_emisor'=>$f['ruc_emisor'],
                 'razon_social'=>$f['razon_social'],
                 'fecha'=>self::fechaDeClave($f['clave_acceso']),
                 'estado'=>$f['estado'],
                 'error'=>$f['motivo']]);
            if (! $p->wasRecentlyCreated) $repetidas++;
            elseif ($f['estado'] === 'omitido') $omitidas++;
            else $insertadas++;
        }
        return ['insertadas'=>$insertadas, 'omitidas'=>$omitidas, 'repetidas'=>$repetidas, 'sin_clave'=>$lectura['sin_clave']];
    }
    public function process(Request $r, SriXmlDownloader $dl, StorePurchaseFromXml $store) {
        $r->validate(['company_id'=>['required','exists:companies,id']]);
        $company = Company::findOrFail($r->company_id);
        $ok = 0; $errores = 0;
        foreach (PendingImport::where('company_id', $company->id)->where('estado', 'pendiente')->get() as $p) {
            try {
                $xml = $dl->download($p->clave_acceso, (int) $company->ambiente);
                if (! $xml) {
                    $p->update(['estado'=>'error',
                        'error'=>'El SRI ya no entrega este XML (pasó el mes) o no está autorizado.']);
                    $errores++; continue;
                }
                $store->handle($company, $xml);
                $p->update(['estado'=>'procesada', 'error'=>null]);
                $ok++;
            } catch (\Throwable $e) {
                $p->update(['estado'=>'error', 'error'=>substr($e->getMessage(), 0, 250)]);
                $errores++;
            }
        }
        return ['procesadas'=>$ok, 'errores'=>$errores];
    }
    // Procesar UNA factura pendiente (como en KVS: se elige de la lista y se procesa)
    public function processOne(\App\Models\PendingImport $pending, SriXmlDownloader $dl, StorePurchaseFromXml $store)
    {
        $company = \App\Models\Company::findOrFail($pending->company_id);
        try {
            $xml = $dl->download($pending->clave_acceso, (int) $company->ambiente);
            if (! $xml) {
                $pending->update(["estado" => "error",
                    "error" => "El SRI ya no entrega este XML (pasó el mes) o no está autorizado."]);
                return response()->json($pending->fresh(), 422);
            }
            $purchase = $store->handle($company, $xml);
            $pending->update(["estado" => "procesada", "error" => null]);
            return ["pending" => $pending->fresh(), "purchase" => $purchase->load("contact:id,razon_social")];
        } catch (\Throwable $e) {
            $pending->update(["estado" => "error", "error" => substr($e->getMessage(), 0, 250)]);
            return response()->json($pending->fresh(), 422);
        }
    }
    private static function fechaDeClave(string $clave): ?string {
        $d = substr($clave,0,2); $m = substr($clave,2,2); $y = substr($clave,4,4);
        return checkdate((int)$m, (int)$d, (int)$y) ? "$y-$m-$d" : null;
    }
}
