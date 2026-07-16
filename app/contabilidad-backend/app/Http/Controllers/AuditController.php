<?php
namespace App\Http\Controllers;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditController extends Controller {
    public function index(Request $r) {
        return AuditLog::with('user:id,name')
            ->when($r->company_id, fn($q,$id)=>$q->where('company_id',$id))
            ->when($r->modelo, fn($q,$m)=>$q->where('modelo',$m))
            ->latest()->limit(300)->get();
    }
}
