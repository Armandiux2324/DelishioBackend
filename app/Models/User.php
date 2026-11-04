<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
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

class User extends Authenticatable implements HasLocalePreference
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
        abort_unless($this->active, 403, __('Cuenta inactiva'));
        abort_unless($this->email_verified_at, 403, __('Verifica tu correo electrónico'));

        if ($this->user_type === 'employee') {
            $employee = $this->employee;
            abort_unless($employee && in_array($employee->role, ['admin', 'waiter', 'chef'], true), 403, __('Empleado no disponible'));
            abort_unless($employee->restaurant?->status === 'active', 403, __('Restaurante no disponible'));
        } else {
            abort_unless(in_array($this->user_type, ['super_admin', 'customer'], true), 403, __('Tipo de usuario inválido'));
        }
    }

    public function employee(): HasOne
    {
        // La pertenencia usada para autenticar se busca solo por este user_id, antes de resolver el tenant.
        return $this->hasOne(Employee::class, 'user_id')->withoutGlobalScope('restaurant');
    }

    public function preferredLocale(): string
    {
        return in_array($this->locale, ['en', 'es'], true) ? $this->locale : config('app.locale');
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
