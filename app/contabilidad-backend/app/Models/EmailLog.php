<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Un intento de enviar un comprobante por correo (enviado, error u omitido). El documento es el de negocio
 * (factura, nota de crédito, nota de débito o la fila principal de la retención).
 */
class EmailLog extends Model
{
    public const ENVIADO = 'enviado';
    public const ERROR = 'error';
    public const OMITIDO = 'omitido';

    protected $fillable = ['company_id', 'documentable_type', 'documentable_id', 'tipo_comprobante', 'destinatarios', 'asunto',
        'estado', 'motivo', 'automatico', 'adjuntos', 'user_id', 'sent_at'];

    protected $casts = ['destinatarios' => 'array', 'adjuntos' => 'array', 'automatico' => 'boolean', 'sent_at' => 'datetime'];

    public function documentable() { return $this->morphTo(); }
    public function user() { return $this->belongsTo(User::class); }

    /** ¿Ya salió por correo, de forma automática, este documento? Con eso el envío automático nunca se repite. */
    public static function yaSeEnvioAutomaticamente(Model $documento): bool
    {
        return static::where('documentable_type', $documento->getMorphClass())
            ->where('documentable_id', $documento->getKey())
            ->where('automatico', true)->where('estado', self::ENVIADO)->exists();
    }

    /**
     * Estado del correo de varios documentos con UNA sola consulta (para las listas: sin consultas por fila).
     * Devuelve, por cada documento, ['estado' => enviado|error|sin_enviar, 'enviado_en', 'destinatarios', 'motivo'] con la clave "tipo:id".
     * Si alguna vez salió, cuenta como enviado; si no, manda el último intento (un error se ve, un omitido es "sin enviar").
     *
     * @param  Collection<int, Model>  $documentos
     * @return array<string, array{estado:string,enviado_en:?string,destinatarios:array,motivo:?string}>
     */
    public static function resumenDe(Collection $documentos): array
    {
        $documentos = $documentos->filter()->values();
        if ($documentos->isEmpty()) {
            return [];
        }

        $porTipo = $documentos->groupBy(fn (Model $d) => $d->getMorphClass())->map(fn ($g) => $g->map->getKey()->all());
        $filas = static::query()->where(function ($q) use ($porTipo) {
            foreach ($porTipo as $tipo => $ids) {
                $q->orWhere(fn ($w) => $w->where('documentable_type', $tipo)->whereIn('documentable_id', $ids));
            }
        })->orderBy('id')->get(['id', 'documentable_type', 'documentable_id', 'estado', 'destinatarios', 'motivo', 'sent_at'])
            ->groupBy(fn (self $l) => $l->documentable_type.':'.$l->documentable_id);

        $resumen = [];
        foreach ($documentos as $d) {
            $intentos = $filas->get($d->getMorphClass().':'.$d->getKey(), collect());
            $enviado = $intentos->last(fn (self $l) => $l->estado === self::ENVIADO);
            $ultimo = $intentos->last();
            $estado = $enviado ? 'enviado' : ($ultimo?->estado === self::ERROR ? 'error' : 'sin_enviar');
            $fila = $enviado ?? $ultimo;
            $resumen[$d->getMorphClass().':'.$d->getKey()] = [
                'estado' => $estado,
                'enviado_en' => $enviado?->sent_at?->toIso8601String(),
                'destinatarios' => $estado === 'sin_enviar' ? [] : ($fila?->destinatarios ?? []),
                'motivo' => $estado === 'error' ? $ultimo->motivo : null,
            ];
        }

        return $resumen;
    }
}
