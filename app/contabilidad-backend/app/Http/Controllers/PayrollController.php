<?php
namespace App\Http\Controllers;
use App\Models\Company;
use App\Models\Employee;
use App\Models\Payroll;
use App\Services\PayrollCalculator;
use App\Services\SimpleEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollController extends Controller {
    public function index(Request $r) {
        return Payroll::when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->orderByDesc('anio')->orderByDesc('mes')->get();
    }
    public function show(Payroll $payroll) {
        return $payroll->load('lines.employee:id,nombres,cedula,cargo');
    }
    public function generate(Request $r, PayrollCalculator $calc) {
        $d = $r->validate([
            'company_id'=>['required','exists:companies,id'],
            'anio'=>['required','integer','min:2020'],
            'mes'=>['required','integer','min:1','max:12'],
            'extras'=>['sometimes','array'],
        ]);
        $company = Company::findOrFail($d['company_id']);
        $sbu = (float)($company->sbu ?? 470);

        return DB::transaction(function() use ($d, $company, $sbu, $calc) {
            $payroll = Payroll::updateOrCreate(
                ['company_id'=>$company->id, 'anio'=>$d['anio'], 'mes'=>$d['mes']],
                ['estado'=>'abierto']);
            $payroll->lines()->delete();

            $ingresos = $egresos = $neto = $provisiones = 0;
            foreach (Employee::where('company_id',$company->id)->where('activo',true)->get() as $e) {
                $linea = $calc->forEmployee($e, $sbu, $d['extras'][$e->id] ?? []);
                $payroll->lines()->create($linea);
                $ingresos += $linea['sueldo'] + $linea['horas_extra'] + $linea['comisiones'];
                $egresos  += $linea['aporte_personal'] + $linea['prestamos'] + $linea['anticipos'];
                $neto     += $linea['neto'];
                $provisiones += $linea['aporte_patronal'] + $linea['decimo_tercero']
                    + $linea['decimo_cuarto'] + $linea['fondos_reserva'] + $linea['vacaciones'];
            }
            $payroll->update([
                'total_ingresos'=>round($ingresos,2), 'total_egresos'=>round($egresos,2),
                'total_neto'=>round($neto,2), 'total_provisiones'=>round($provisiones,2),
            ]);
            return $payroll->load('lines.employee:id,nombres,cedula,cargo');
        });
    }
    public function close(Payroll $payroll) {
        return DB::transaction(function() use ($payroll) {
            $l = $payroll->lines;
            $sueldos = round($l->sum('sueldo') + $l->sum('horas_extra') + $l->sum('comisiones'), 2);
            $aportePersonal = round($l->sum('aporte_personal'), 2);
            $descuentos = round($l->sum('prestamos') + $l->sum('anticipos'), 2);
            $patronal = round($l->sum('aporte_patronal'), 2);
            $provisiones = round($l->sum('decimo_tercero') + $l->sum('decimo_cuarto')
                + $l->sum('fondos_reserva') + $l->sum('vacaciones'), 2);
            $neto = round($l->sum('neto'), 2);

            SimpleEntry::make($payroll->company_id, "Rol de pagos {$payroll->mes}/{$payroll->anio}", [
                ['codigo'=>'5.2.01','nombre'=>'Sueldos y salarios','tipo'=>'gasto',
                 'debe'=>$sueldos,'haber'=>0,'ref'=>'ROL'],
                ['codigo'=>'5.2.02','nombre'=>'Aporte patronal IESS','tipo'=>'gasto',
                 'debe'=>$patronal,'haber'=>0,'ref'=>'ROL'],
                ['codigo'=>'5.2.03','nombre'=>'Beneficios sociales','tipo'=>'gasto',
                 'debe'=>$provisiones,'haber'=>0,'ref'=>'ROL'],
                ['codigo'=>'2.1.04','nombre'=>'IESS por pagar','tipo'=>'pasivo',
                 'debe'=>0,'haber'=>round($aportePersonal + $patronal, 2),'ref'=>'ROL'],
                ['codigo'=>'2.1.05','nombre'=>'Beneficios sociales por pagar','tipo'=>'pasivo',
                 'debe'=>0,'haber'=>$provisiones,'ref'=>'ROL'],
                ['codigo'=>'2.1.06','nombre'=>'Descuentos a empleados','tipo'=>'pasivo',
                 'debe'=>0,'haber'=>$descuentos,'ref'=>'ROL'],
                ['codigo'=>'2.1.07','nombre'=>'Sueldos por pagar','tipo'=>'pasivo',
                 'debe'=>0,'haber'=>$neto,'ref'=>'ROL'],
            ], $payroll);

            $payroll->update(['estado'=>'cerrado']);
            return ['ok'=>true, 'mensaje'=>'Rol cerrado y contabilizado.'];
        });
    }
    public function liquidacion(Request $r, Employee $employee, PayrollCalculator $calc) {
        $d = $r->validate(['fecha_salida'=>['required','date']]);
        $company = Company::findOrFail($employee->company_id);
        return $calc->liquidacion($employee, (float)($company->sbu ?? 470), $d['fecha_salida']);
    }
}
