<?php
namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    /** Listado con los filtros que usa un auditor: quién, cuándo, qué y sobre qué módulo. */
    public function index(Request $r)
    {
        $q = $this->filtrar($r);

        return $q->latest()->paginate((int) $r->input('por_pagina', 50));
    }

    /** Valores para llenar los combos de la pantalla. */
    public function opciones(Request $r)
    {
        $base = AuditLog::when($r->company_id, fn ($q, $id) => $q->where('company_id', $id));

        return [
            'modelos'  => (clone $base)->distinct()->orderBy('modelo')->pluck('modelo'),
            'acciones' => (clone $base)->distinct()->orderBy('accion')->pluck('accion'),
            'usuarios' => \App\Models\User::orderBy('name')->get(['id', 'name']),
        ];
    }

    /** Descarga en CSV: el auditor se lleva el papel. */
    public function exportar(Request $r)
    {
        $filas = $this->filtrar($r)->latest()->limit(5000)->get();
        $nombre = 'auditoria_'.now()->format('Y-m-d_His').'.csv';

        return response()->streamDownload(function () use ($filas) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));   // BOM: tildes correctas en Excel
            fputcsv($out, ['Fecha y hora', 'Usuario', 'Acción', 'Módulo', 'Registro', 'Detalle', 'Cambios', 'IP']);
            foreach ($filas as $f) {
                fputcsv($out, [
                    $f->created_at?->format('d/m/Y H:i:s'),
                    $f->user?->name ?? 'Sistema',
                    $f->accion,
                    $f->modelo,
                    $f->modelo_id,
                    $f->descripcion,
                    $f->cambios ? json_encode($f->cambios, JSON_UNESCAPED_UNICODE) : '',
                    $f->ip,
                ]);
            }
            fclose($out);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filtrar(Request $r)
    {
        return AuditLog::with('user:id,name')
            ->when($r->company_id, fn ($q, $id) => $q->where('company_id', $id))
            ->when($r->modelo,  fn ($q, $m) => $q->where('modelo', $m))
            ->when($r->accion,  fn ($q, $a) => $q->where('accion', $a))
            ->when($r->user_id, fn ($q, $u) => $q->where('user_id', $u))
            ->when($r->desde,   fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($r->hasta,   fn ($q, $h) => $q->whereDate('created_at', '<=', $h))
            ->when($r->buscar,  fn ($q, $b) => $q->where(fn ($w) => $w
                ->where('descripcion', 'like', "%{$b}%")
                ->orWhere('ip', 'like', "%{$b}%")));
    }
}
