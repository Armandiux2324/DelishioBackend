<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'username',
    'email',
    'password',
    'user_type',
    'locale'])]
#[Hidden(['password', 'remember_token'])]

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_enabled' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function ensureCanAccess(): void
    {
        abort_unless($this->active, 403, 'Cuenta inactiva');
        abort_unless($this->email_verified_at, 403, 'Verifica tu correo electrónico');

        if ($this->user_type === 'employee') {
            $employee = $this->employee;
            abort_unless($employee && in_array($employee->role, ['admin', 'waiter', 'chef'], true), 403, 'Empleado no disponible');
            abort_unless($employee->restaurant?->status === 'active', 403, 'Restaurante no disponible');
        } else {
            abort_unless(in_array($this->user_type, ['super_admin', 'customer'], true), 403, 'Tipo de usuario inválido');
        }
    }

    public function employee(): HasOne
    {
        return $this->hasOne(Employee::class, 'user_id');
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class, 'user_id');
    }

    public function verificationCodes(): HasMany
    {
        return $this->hasMany(VerificationCode::class);
    }
}
