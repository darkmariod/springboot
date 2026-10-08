<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Contact;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\JournalEntryLine;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Acceso al plan de cuentas central (config/cuentas.php).
 *
 * Los asientos piden la cuenta por CONCEPTO ('caja', 'cxc', 'cxp'...) y no por
 * código, así un código nunca queda repetido con otro significado.
 */
final class Cuentas
{
    /** Cuentas por cobrar: la normal y la de partes relacionadas (se suman en el chequeo y en cualquier saldo). */
    public const CXC = ['cxc', 'cxc_relacionadas'];

    /** Cuentas por pagar: la normal y la de partes relacionadas. */
    public const CXP = ['cxp', 'cxp_relacionadas'];

    /** @return array{codigo:string,nombre:string,tipo:string} */
    public static function get(string $concepto): array
    {
        $cuenta = config('cuentas.cuentas.'.$concepto);
        if (! is_array($cuenta)) {
            throw new InvalidArgumentException("Concepto contable desconocido: $concepto");
        }

        return $cuenta;
    }

    public static function codigo(string $concepto): string
    {
        return self::get($concepto)['codigo'];
    }

    /** Busca por código (p. ej. la contrapartida que recibe RegistrarPagos). Null si no es del plan central. */
    public static function porCodigo(string $codigo): ?array
    {
        foreach (config('cuentas.cuentas', []) as $cuenta) {
            if ($cuenta['codigo'] === $codigo) {
                return $cuenta;
            }
        }

        return null;
    }

    /** Línea lista para SimpleEntry::make(). */
    public static function linea(string $concepto, float|int|string $debe, float|int|string $haber, ?string $ref = null): array
    {
        return self::get($concepto) + ['debe' => $debe, 'haber' => $haber, 'ref' => $ref];
    }

    /** La cuenta del concepto para esa empresa; si todavía no existe, se crea con el nombre y tipo del plan central. */
    public static function cuenta(int $companyId, string $concepto): Account
    {
        $c = self::get($concepto);

        return Account::firstOrCreate(
            ['company_id' => $companyId, 'codigo' => $c['codigo']],
            ['nombre' => $c['nombre'], 'tipo' => $c['tipo']]
        );
    }

    /** Crea (sin duplicar) los encabezados y todas las cuentas del plan central para una empresa. */
    public static function sembrar(int $companyId): void
    {
        foreach (config('cuentas.grupos', []) as [$codigo, $nombre, $tipo]) {
            Account::firstOrCreate(['company_id' => $companyId, 'codigo' => $codigo], ['nombre' => $nombre, 'tipo' => $tipo]);
        }
        foreach (config('cuentas.cuentas', []) as $c) {
            Account::firstOrCreate(['company_id' => $companyId, 'codigo' => $c['codigo']], ['nombre' => $c['nombre'], 'tipo' => $c['tipo']]);
        }
    }

    /** Concepto de cuenta por cobrar que le toca HOY a un cliente: 'cxc_relacionadas' si es parte relacionada, si no 'cxc'. */
    public static function cxcPara(?Contact $contact): string
    {
        return $contact?->parte_relacionada ? 'cxc_relacionadas' : 'cxc';
    }

    /** Concepto de cuenta por pagar que le toca HOY a un proveedor. */
    public static function cxpPara(?Contact $contact): string
    {
        return $contact?->parte_relacionada ? 'cxp_relacionadas' : 'cxp';
    }

    /**
     * Cuenta por cobrar de un documento (factura, nota de crédito): la MISMA en que quedó asentado, aunque el
     * cliente haya cambiado de condición después. Así un cobro, un cruce o la aplicación de una nota cierran
     * la cuenta donde nació el saldo y nada se reprocesa hacia atrás. Si todavía no tiene asiento (o no toca
     * cuentas por cobrar), una nota sigue a su factura y lo demás a la condición actual del cliente.
     */
    public static function cxcDe(Model $documento): string
    {
        if ($previo = self::conceptoAsentado($documento, self::CXC)) {
            return $previo;
        }
        if ($documento instanceof CreditNote && $documento->invoice_id && ($factura = Invoice::find($documento->invoice_id))) {
            return self::cxcDe($factura);
        }

        return self::cxcPara($documento->contact_id ? Contact::find($documento->contact_id) : null);
    }

    /** Cuenta por pagar de un documento (compra): la misma en que quedó asentada. */
    public static function cxpDe(Model $documento): string
    {
        return self::conceptoAsentado($documento, self::CXP)
            ?? self::cxpPara($documento->contact_id ? Contact::find($documento->contact_id) : null);
    }

    /** Primer concepto de la lista que aparece en el asiento que generó el documento (el de su nacimiento). */
    private static function conceptoAsentado(Model $documento, array $conceptos): ?string
    {
        $porCodigo = [];
        foreach ($conceptos as $concepto) {
            $porCodigo[self::codigo($concepto)] = $concepto;
        }

        $codigo = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entries.company_id', $documento->company_id)
            ->where('journal_entries.origen_type', $documento->getMorphClass())
            ->where('journal_entries.origen_id', $documento->getKey())
            ->where('accounts.company_id', $documento->company_id)
            ->whereIn('accounts.codigo', array_keys($porCodigo))
            ->orderBy('journal_entries.id')->orderBy('journal_entry_lines.id')
            ->value('accounts.codigo');

        return $codigo ? $porCodigo[$codigo] : null;
    }
}
