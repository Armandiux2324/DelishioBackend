<?php

use App\Models\Employee;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    Route::middleware(['api', 'auth:sanctum', 'account.active', 'role:admin,waiter,chef'])
        ->get('/api/test/restaurants/{restaurant}', fn (Restaurant $restaurant) => ['id' => $restaurant->id]);
});

function restaurantEmployee(string $role = 'admin'): User
{
    $restaurant = Restaurant::create([
        'name' => 'Restaurante',
        'created_by_user_id' => User::factory()->create(['user_type' => 'super_admin'])->id,
    ]);
    $user = User::factory()->create(['user_type' => 'employee']);
    Employee::create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
        'role' => $role,
        'hourly_rate' => 100,
        'pin' => '012345',
    ]);

    return $user;
}

test('login accepts existing email payloads and a unique username', function (array $credentials) {
    $user = User::factory()->create(['email' => 'client@example.com', 'username' => 'client']);
    $response = $this->postJson('/api/v1/auth/login', $credentials + ['password' => 'password']);
    $response->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonStructure(['access_token']);
    expect($user->tokens()->count())->toBe(1);
})->with([
    'legacy email' => [['email' => 'client@example.com']],
    'email login' => [['login' => 'client@example.com']],
    'username login' => [['login' => 'client']],
]);

test('login rejects invalid input and credentials', function () {
    $this->postJson('/api/v1/auth/login', [])->assertUnprocessable()->assertJsonValidationErrors(['email', 'login', 'password']);
    $user = User::factory()->create();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
    expect($user->tokens()->count())->toBe(0);
});

test('registration ignores privilege escalation and persists verification', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Cliente', 'username' => 'cliente', 'email' => 'client@example.com', 'phone' => '1234567890',
        'password' => 'password123', 'password_confirmation' => 'password123',
        'user_type' => 'super_admin', 'email_verified_at' => now(), 'two_factor_enabled' => true,
    ])->assertCreated();
    $user = User::where('email', 'client@example.com')->firstOrFail();
    expect($user->user_type)->toBe('customer');
    expect($user->email_verified_at)->toBeNull();
    expect($user->two_factor_enabled)->toBeFalse();
    expect($user->customer->qr_code)->not->toBeEmpty();
    $this->postJson('/api/v1/auth/login', ['login' => 'cliente', 'password' => 'password123'])->assertForbidden();
    $code = $user->verificationCodes()->firstOrFail()->code;
    $this->postJson('/api/v1/auth/verify-email', ['email' => $user->email, 'code' => $code])->assertOk();
    expect($user->fresh()->email_verified_at)->not->toBeNull();
    $this->postJson('/api/v1/auth/verify-email', ['email' => $user->email, 'code' => $code])->assertBadRequest();
});

test('username must be unique and cannot collide with an email address', function (string $username) {
    User::factory()->create(['username' => 'taken']);
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Cliente', 'username' => $username, 'email' => 'new@example.com', 'phone' => '123',
        'password' => 'password123', 'password_confirmation' => 'password123',
    ])->assertUnprocessable()->assertJsonValidationErrors('username');
})->with(['taken', 'other@example.com', 'UPPERCASE']);

test('inaccessible accounts cannot login or use an existing token', function (string $condition) {
    $user = restaurantEmployee();
    $token = $user->createToken('test')->plainTextToken;
    match ($condition) {
        'inactive' => $user->forceFill(['active' => false])->save(),
        'unverified' => $user->forceFill(['email_verified_at' => null])->save(),
        'suspended' => $user->employee->restaurant->update(['status' => 'suspended']),
        'deleted restaurant' => $user->employee->restaurant->delete(),
        'missing employee' => $user->employee->delete(),
    };
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/user')->assertForbidden();
    expect($user->tokens()->count())->toBe(1);
})->with(['inactive', 'unverified', 'suspended', 'deleted restaurant', 'missing employee']);

test('employee roles can access only their own restaurant', function (string $role) {
    $user = restaurantEmployee($role);
    $other = restaurantEmployee()->employee->restaurant;
    $this->withToken($user->createToken('test')->plainTextToken);
    $this->getJson('/api/test/restaurants/'.$user->employee->restaurant_id)->assertOk();
    $this->getJson('/api/test/restaurants/'.$other->id)->assertForbidden();
    $this->getJson('/api/v1/auth/super-admin/restaurants')->assertForbidden();
})->with(['admin', 'waiter', 'chef']);

test('restaurant endpoints reject guests and customers', function () {
    $this->getJson('/api/v1/auth/super-admin/restaurants')->assertUnauthorized();
    $this->withToken(User::factory()->create()->createToken('test')->plainTextToken)
        ->getJson('/api/v1/auth/super-admin/restaurants')->assertForbidden();
});

test('super admin can manage restaurants and create a verified administrator with a hashed pin', function () {
    $super = User::factory()->create(['user_type' => 'super_admin']);
    $this->withToken($super->createToken('test')->plainTextToken);
    $payload = [
        'name' => 'Delishio', 'address' => 'Calle 1', 'phone' => '1234567890',
        'admin_name' => 'Admin', 'admin_email' => 'admin@example.com',
        'admin_password' => 'password123', 'admin_password_confirmation' => 'password123',
    ];
    $id = $this->postJson('/api/v1/auth/super-admin/restaurants', $payload)->assertCreated()->json('data.id');
    $admin = User::where('email', 'admin@example.com')->firstOrFail();
    expect($admin->email_verified_at)->not->toBeNull();
    expect(Hash::isHashed($admin->employee->pin))->toBeTrue();
    expect($admin->employee->toArray())->not->toHaveKey('pin');
    $this->getJson('/api/v1/auth/super-admin/restaurants')->assertOk()->assertJsonCount(1, 'data');
    $this->patchJson('/api/v1/auth/super-admin/restaurants/'.$id, ['name' => 'Nuevo'])->assertOk()->assertJsonPath('data.name', 'Nuevo');
    $this->postJson('/api/v1/auth/super-admin/restaurants/'.$id.'/suspend')->assertOk()->assertJsonPath('data.status', 'suspended');
    $this->postJson('/api/v1/auth/super-admin/restaurants/'.$id.'/activate')->assertOk()->assertJsonPath('data.status', 'active');
    $this->deleteJson('/api/v1/auth/super-admin/restaurants/'.$id)->assertOk();
    $this->assertSoftDeleted('restaurants', ['id' => $id]);
});

test('super admin must complete two factor authentication and cannot replay it', function () {
    $user = User::factory()->create(['user_type' => 'super_admin', 'two_factor_enabled' => false]);
    $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password']);
    $login->assertOk()->assertJsonPath('requires_2fa', true)->assertJsonMissingPath('access_token');
    expect($user->tokens()->count())->toBe(0);
    $payload = ['ticket' => $login->json('ticket'), 'code' => $user->verificationCodes()->firstOrFail()->code];
    $this->postJson('/api/v1/auth/login/verify-2fa', $payload)->assertOk()->assertJsonStructure(['access_token']);
    $this->postJson('/api/v1/auth/login/verify-2fa', $payload)->assertUnauthorized();
    expect($user->tokens()->count())->toBe(1);
});

test('two factor challenges recheck account and restaurant access', function (string $condition) {
    $user = restaurantEmployee();
    $user->forceFill(['two_factor_enabled' => true])->save();
    $ticket = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('ticket');
    $code = $user->verificationCodes()->firstOrFail()->code;
    if ($condition === 'inactive') {
        $user->forceFill(['active' => false])->save();
    } else {
        $user->employee->restaurant->update(['status' => 'suspended']);
    }
    $this->postJson('/api/v1/auth/login/verify-2fa', compact('ticket', 'code'))->assertForbidden();
    expect($user->tokens()->count())->toBe(0);
})->with(['inactive', 'suspended']);

test('pre-upgrade two factor tickets require a new login', function () {
    $user = User::factory()->create(['user_type' => 'super_admin']);
    Cache::put('2fa-ticket:old-ticket', $user->id, now()->addMinutes(10));
    $this->postJson('/api/v1/auth/login/verify-2fa', ['ticket' => 'old-ticket', 'code' => '123456'])->assertUnauthorized();
    expect($user->tokens()->count())->toBe(0);
});

test('old tickets cannot consume a new two factor code', function () {
    $user = User::factory()->create(['user_type' => 'super_admin']);
    $credentials = ['email' => $user->email, 'password' => 'password'];
    $oldTicket = $this->postJson('/api/v1/auth/login', $credentials)->json('ticket');
    $newTicket = $this->postJson('/api/v1/auth/login', $credentials)->json('ticket');
    $code = $user->verificationCodes()->latest('id')->firstOrFail()->code;
    $this->postJson('/api/v1/auth/login/verify-2fa', ['ticket' => $oldTicket, 'code' => $code])->assertBadRequest();
    $this->postJson('/api/v1/auth/login/verify-2fa', ['ticket' => $newTicket, 'code' => $code])->assertOk();
});

test('two factor failures persist and expire without issuing tokens', function (string $condition) {
    $user = User::factory()->create(['user_type' => 'super_admin']);
    $ticket = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('ticket');
    $verification = $user->verificationCodes()->firstOrFail();
    if ($condition === 'expired') {
        $this->travel(11)->minutes();
        $this->postJson('/api/v1/auth/login/verify-2fa', ['ticket' => $ticket, 'code' => $verification->code])->assertUnauthorized();
    } else {
        $wrong = $verification->code === '000000' ? '111111' : '000000';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/v1/auth/login/verify-2fa', ['ticket' => $ticket, 'code' => $wrong])->assertBadRequest();
        }
        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/login/verify-2fa', ['ticket' => $ticket, 'code' => $verification->code])->assertBadRequest();
        expect($verification->fresh()->used)->toBeTrue();
    }
    expect($user->tokens()->count())->toBe(0);
})->with(['expired', 'attempts exhausted']);

test('verification codes expire and stop accepting attempts after five failures', function () {
    $user = User::factory()->create();
    $verification = VerificationCode::generate($user->id, 'password_reset');
    $wrong = $verification->code === '000000' ? '111111' : '000000';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        expect(VerificationCode::consume($user->id, 'password_reset', $wrong))->toBe('Código inválido');
    }
    expect(VerificationCode::consume($user->id, 'password_reset', $verification->code))->toBe('Código inválido');
    $verification = VerificationCode::generate($user->id, 'password_reset');
    $this->travel(11)->minutes();
    expect(VerificationCode::consume($user->id, 'password_reset', $verification->code))->toBe('Código expirado');
});

test('enabling and disabling two factor authentication persists and requires the password', function () {
    $user = User::factory()->create();
    $this->withToken($user->createToken('test')->plainTextToken);
    $this->postJson('/api/v1/auth/2fa/enable', ['password' => 'wrong'])->assertUnprocessable();
    expect($user->fresh()->two_factor_enabled)->toBeFalse();
    $this->postJson('/api/v1/auth/2fa/enable', ['password' => 'password'])->assertOk();
    expect($user->fresh()->two_factor_enabled)->toBeTrue();
    $this->postJson('/api/v1/auth/2fa/disable', ['password' => 'password'])->assertOk();
    expect($user->fresh()->two_factor_enabled)->toBeFalse();
});

test('super admin cannot disable mandatory two factor authentication', function () {
    $user = User::factory()->create(['user_type' => 'super_admin']);
    $this->withToken($user->createToken('test')->plainTextToken)
        ->postJson('/api/v1/auth/2fa/disable', ['password' => 'password'])->assertUnprocessable();
});

test('password reset revokes tokens and pending login challenges', function () {
    $user = User::factory()->create(['user_type' => 'super_admin']);
    $user->createToken('existing');
    $ticket = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])->json('ticket');
    $loginCode = $user->verificationCodes()->firstOrFail()->code;
    $code = VerificationCode::generate($user->id, 'password_reset')->code;
    $payload = ['email' => $user->email, 'code' => $code, 'password' => 'new-password123', 'password_confirmation' => 'new-password123'];
    $this->postJson('/api/v1/auth/password/reset', $payload)->assertOk();
    expect(Hash::check('new-password123', $user->fresh()->password))->toBeTrue();
    expect($user->tokens()->count())->toBe(0);
    $this->postJson('/api/v1/auth/password/reset', $payload)->assertBadRequest();
    $this->postJson('/api/v1/auth/login/verify-2fa', ['ticket' => $ticket, 'code' => $loginCode])->assertBadRequest();
});

test('logout revokes only the current token even when the account is blocked', function () {
    $user = User::factory()->create(['active' => false]);
    $token = $user->createToken('current');
    $other = $user->createToken('other');
    $this->withToken($token->plainTextToken)->postJson('/api/v1/auth/logout')->assertOk();
    $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
    $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);
});

test('pin migration hashes existing pins and preserves existing hashes', function () {
    $user = restaurantEmployee();
    $existingHash = $user->employee->pin;
    $other = restaurantEmployee();
    DB::table('employees')->where('user_id', $other->id)->update(['pin' => '012345']);
    $migration = require database_path('migrations/2026_11_03_055151_protect_employee_pins.php');
    $migration->up();
    expect(Hash::check('012345', $other->employee()->firstOrFail()->pin))->toBeTrue();
    expect($user->employee()->firstOrFail()->pin)->toBe($existingHash);
});
