<?php

namespace App\Models;
use App\Models\Concerns\Auditable;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'company_id', 'rol', 'emission_point_id', 'activo'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use Auditable;
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function emissionPoint() { return $this->belongsTo(EmissionPoint::class); }
    public function esAdmin(): bool { return $this->rol === 'admin'; }
    /**
     * Operador de la plataforma: ve todas las empresas, las crea y cambia su plan (T11.6). `es_superadmin` NO está en $fillable a
     * propósito: ninguna petición lo puede fijar; solo la migración, el seeder y los comandos de consola.
     */
    public function esSuperadmin(): bool { return (bool) $this->es_superadmin; }
    /**
     * Un usuario con empresa que no es superadministrador solo ve y modifica la suya. Sin `company_id` (convención de las
     * pruebas y de cuentas antiguas) queda sin restricción: crear usuarios ya no puede producir uno así.
     */
    public function estaConfinadoAEmpresa(): bool { return $this->company_id !== null && ! $this->esSuperadmin(); }
    /** Solo un `false` explícito desactiva: un `activo` nulo (fábricas de prueba) cuenta como activo. En la base nunca es nulo. */
    public function estaDesactivado(): bool { return $this->activo === false; }
    public function puedeUsarPunto(?int $emissionPointId): bool {
        if (! $this->emission_point_id) return true;
        return $this->emission_point_id === $emissionPointId;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // La API devuelve true/false (no 1/0): la casilla "Usuario activo" de la pantalla solo reconoce booleanos
            'activo' => 'boolean',
            'es_superadmin' => 'boolean',
        ];
    }
}
