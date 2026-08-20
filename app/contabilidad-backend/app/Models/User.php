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
    public function puedeUsarPunto(?int $emissionPointId): bool {
        if (! $this->emission_point_id) return true;
        return $this->emission_point_id === $emissionPointId;
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
