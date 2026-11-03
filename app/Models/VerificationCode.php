<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['user_id', 'code', 'type', 'expires_at', 'attempts', 'used'])]
class VerificationCode extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used' => 'boolean',
        ];
    }

    private const MAX_ATTEMPTS = 5;

    /**
     * Genera un código de 6 dígitos para el usuario y tipo dados, invalidando
     * cualquier código previo del mismo tipo que siguiera vigente.
     */
    public static function generate(int $userId, string $type, int $minutesValid = 10): self
    {
        return DB::transaction(function () use ($userId, $type, $minutesValid) {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            static::where('user_id', $userId)->where('type', $type)->where('used', false)->update(['used' => true]);

            return static::create([
                'user_id' => $userId,
                'code' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
                'type' => $type,
                'expires_at' => now()->addMinutes($minutesValid),
            ]);
        });
    }

    /**
     * Consume el código una sola vez; serializa generación y consumo por usuario.
     * Cada fallo cuenta como intento y al llegar al máximo el código se invalida.
     */
    public static function consume(int $userId, string $type, string $code, ?int $verificationId = null): self|string
    {
        return DB::transaction(function () use ($userId, $type, $code, $verificationId) {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $verification = static::where('user_id', $userId)->where('type', $type)->where('used', false)
                ->when($verificationId !== null, fn ($query) => $query->whereKey($verificationId))
                ->latest('id')->lockForUpdate()->first();

            if (! $verification || $verification->attempts >= self::MAX_ATTEMPTS) {
                return 'Código inválido';
            }

            if ($verification->expires_at->isPast()) {
                return 'Código expirado';
            }

            if (! hash_equals((string) $verification->code, $code)) {
                $verification->increment('attempts');

                if ($verification->attempts >= self::MAX_ATTEMPTS) {
                    $verification->update(['used' => true]);
                }

                return 'Código inválido';
            }

            $verification->update(['used' => true]);

            return $verification;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
