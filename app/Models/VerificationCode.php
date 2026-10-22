<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        static::where('user_id', $userId)->where('type', $type)->where('used', false)->update(['used' => true]);

        return static::create([
            'user_id' => $userId,
            'code' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'type' => $type,
            'expires_at' => now()->addMinutes($minutesValid),
        ]);
    }

    /**
     * Devuelve el código vigente si coincide; si no, un mensaje de error.
     * Cada fallo cuenta como intento y al llegar al máximo el código se invalida.
     */
    public static function check(int $userId, string $type, string $code): self|string
    {
        $verification = static::where('user_id', $userId)->where('type', $type)->where('used', false)->latest()->first();

        if (!$verification) {
            return 'Código inválido';
        }

        if ($verification->expires_at->isPast()) {
            return 'Código expirado';
        }

        if (!hash_equals((string) $verification->code, $code)) {
            $verification->increment('attempts');

            if ($verification->attempts >= self::MAX_ATTEMPTS) {
                $verification->update(['used' => true]);
            }

            return 'Código inválido';
        }

        return $verification;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}