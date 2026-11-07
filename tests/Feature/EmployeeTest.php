<?php

use App\Models\Employee;
use App\Models\MenuAuditLog;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\VerificationCode;
use App\Services\AdminPinAuthorization;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $creator = User::factory()->create(['user_type' => 'super_admin']);
    $this->restaurantA = Restaurant::create(['name' => 'A', 'created_by_user_id' => $creator->id]);
    $this->restaurantB = Restaurant::create(['name' => 'B', 'created_by_user_id' => $creator->id]);
    $this->adminA = app(RestaurantContext::class)->run($this->restaurantA, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->adminB = app(RestaurantContext::class)->run($this->restaurantB, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->payload = [
        'name' => 'María Pérez', 'username' => 'maria_perez', 'email' => 'maria@delishio.com', 'phone' => '5551234567',
        'role' => 'waiter', 'hourly_rate' => '125.50', 'pin' => '0123',
        'password' => 'EmployeePassword123!', 'password_confirmation' => 'EmployeePassword123!', 'locale' => 'es',
    ];
});

test('administrators manage employee and user records without exposing secrets', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $created = $this->postJson('/api/v1/admin/employees', $this->payload)->assertCreated()
        ->assertJsonPath('data.restaurant_id', $this->restaurantA->id)->assertJsonPath('data.name', 'María Pérez')
        ->assertJsonPath('data.hourly_rate', '125.50')->assertJsonPath('data.station', null)->assertJsonPath('data.active', true)
        ->assertJsonMissingPath('data.pin')->assertJsonMissingPath('data.password')->assertJsonMissingPath('meta.generated_pin');
    $id = $created->json('data.user_id');
    $user = User::findOrFail($id);
    $employee = Employee::findOrFail($id);
    expect($user->user_type)->toBe('employee');
    expect($user->email_verified_at)->not->toBeNull();
    expect(Hash::check($this->payload['password'], $user->password))->toBeTrue();
    expect(Hash::check('0123', $employee->pin))->toBeTrue();
    $url = '/api/v1/admin/employees/'.$id;
    $this->getJson('/api/v1/admin/employees')->assertOk()->assertJsonPath('meta.total', 2)->assertDontSee($employee->pin);
    $this->getJson($url)->assertOk()->assertJsonPath('data.email', 'maria@delishio.com');
    $this->putJson($url, ['name' => 'María Chef', 'role' => 'chef', 'station' => 'bar', 'hourly_rate' => '150.25'])
        ->assertOk()->assertJsonPath('data.station', 'bar')->assertJsonPath('data.name', 'María Chef');
    $this->putJson($url, ['role' => 'waiter', 'phone' => null])->assertOk()->assertJsonPath('data.station', null);
    $this->putJson($url, ['email' => 'maria@delishio.com', 'username' => 'maria_perez'])->assertOk();
    $this->deleteJson($url)->assertNoContent();
    $this->assertSoftDeleted('users', ['id' => $id]);
    $this->assertDatabaseHas('employees', ['user_id' => $id]);
    $this->getJson($url)->assertNotFound();
    $this->putJson($url, ['name' => 'No'])->assertNotFound();
    $this->deleteJson($url)->assertNotFound();
    $this->getJson('/api/v1/admin/employees')->assertJsonPath('meta.total', 1);
});

test('automatically generated PIN is returned only once and stored hashed', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $payload = $this->payload;
    unset($payload['pin']);
    $response = $this->postJson('/api/v1/admin/employees', $payload)->assertCreated();
    $pin = $response->json('meta.generated_pin');
    expect($pin)->toBeString()->toMatch('/^[0-9]{6}$/');
    $employee = Employee::findOrFail($response->json('data.user_id'));
    expect(Hash::check($pin, $employee->pin))->toBeTrue();
    $this->getJson('/api/v1/admin/employees/'.$employee->user_id)->assertOk()->assertJsonMissingPath('meta.generated_pin')->assertJsonMissingPath('data.pin');
    $this->putJson('/api/v1/admin/employees/'.$employee->user_id, ['phone' => '123'])->assertOk()->assertJsonMissingPath('meta.generated_pin');
    expect($employee->fresh()->pin)->toBe($employee->pin);
});

test('employees use password login with email or optional username', function (string $field) {
    $this->actingAs($this->adminA, 'sanctum')->postJson('/api/v1/admin/employees', $this->payload)->assertCreated();
    auth()->forgetGuards();
    $this->postJson('/api/v1/auth/login', ['login' => $this->payload[$field], 'password' => $this->payload['password']])
        ->assertOk()->assertJsonStructure(['access_token'])->assertJsonPath('user.employee.role', 'waiter')->assertJsonMissingPath('user.employee.pin');
    $this->postJson('/api/v1/auth/login', ['login' => $this->payload[$field], 'password' => $this->payload['pin']])->assertUnauthorized();
})->with(['email', 'username']);

test('every employee role and chef station from DBML can be created', function (string $role, ?string $station) {
    $this->actingAs($this->adminA, 'sanctum')->postJson('/api/v1/admin/employees', array_replace($this->payload, ['role' => $role, 'station' => $station]))
        ->assertCreated()->assertJsonPath('data.role', $role)->assertJsonPath('data.station', $station);
})->with([['admin', null], ['waiter', null], ['chef', 'kitchen'], ['chef', 'bar']]);

test('employee validation rejects invalid data or server owned fields on create and update', function (string $field, mixed $value) {
    $this->actingAs($this->adminA, 'sanctum');
    $employee = Employee::factory()->create();
    $this->postJson('/api/v1/admin/employees', array_replace($this->payload, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->putJson('/api/v1/admin/employees/'.$employee->user_id, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['name', ''], ['name', str_repeat('a', 256)], ['email', 'bad'], ['email', 'Upper@delishio.com'],
    ['username', 'Upper'], ['username', 'with space'], ['username', str_repeat('a', 51)], ['locale', 'fr'],
    ['phone', str_repeat('1', 21)], ['role', 'super_admin'], ['role', null], ['station', 'bar'],
    ['hourly_rate', '-0.01'], ['hourly_rate', '1000000'], ['hourly_rate', '1.001'],
    ['pin', null], ['pin', 1234], ['pin', '123'], ['pin', '1234567'], ['pin', '12ab'], ['pin', '$2y$10$example'],
    ['password', 'short'], ['password', 'PasswordWithoutConfirmation'], ['active', 'yes'],
    ['id', null], ['user_id', 999], ['restaurant_id', 999], ['user_type', 'super_admin'],
    ['email_verified_at', null], ['two_factor_enabled', true], ['deleted_at', null], ['created_at', null], ['updated_at', null],
]);

test('required employee fields and chef station are enforced', function () {
    $this->actingAs($this->adminA, 'sanctum')->postJson('/api/v1/admin/employees', [])
        ->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'role', 'hourly_rate', 'password']);
    $this->postJson('/api/v1/admin/employees', array_replace($this->payload, ['role' => 'chef']))->assertUnprocessable()->assertJsonValidationErrors('station');
    $employee = Employee::factory()->create();
    $this->putJson('/api/v1/admin/employees/'.$employee->user_id, ['role' => 'chef'])->assertUnprocessable()->assertJsonValidationErrors('station');
    $this->putJson('/api/v1/admin/employees/'.$employee->user_id, ['role' => 'chef', 'station' => 'kitchen'])->assertOk();
    $this->putJson('/api/v1/admin/employees/'.$employee->user_id, ['phone' => '123'])->assertOk()->assertJsonPath('data.station', 'kitchen');
    $this->putJson('/api/v1/admin/employees/'.$employee->user_id, ['station' => null])->assertUnprocessable()->assertJsonValidationErrors('station');
});

test('email and username remain globally unique including deleted users', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $this->postJson('/api/v1/admin/employees', $this->payload)->assertCreated();
    $this->postJson('/api/v1/admin/employees', $this->payload)->assertUnprocessable()->assertJsonValidationErrors(['email', 'username']);
    $employee = Employee::whereHas('user', fn ($query) => $query->where('email', $this->payload['email']))->firstOrFail();
    $this->deleteJson('/api/v1/admin/employees/'.$employee->user_id)->assertNoContent();
    $this->postJson('/api/v1/admin/employees', $this->payload)->assertUnprocessable()->assertJsonValidationErrors(['email', 'username']);
    $this->postJson('/api/v1/admin/employees', array_replace($this->payload, ['email' => $this->adminB->email, 'username' => null]))
        ->assertUnprocessable()->assertJsonValidationErrors('email');
});

test('employee endpoints isolate restaurants and cannot reassign user identities', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $url = '/api/v1/admin/employees';
    $this->getJson($url.'?restaurant_id='.$this->restaurantB->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $this->adminA->id);
    $this->getJson($url.'/'.$this->adminB->id)->assertNotFound();
    $this->putJson($url.'/'.$this->adminB->id, ['password' => 'Compromised123!', 'password_confirmation' => 'Compromised123!'])->assertNotFound();
    $this->deleteJson($url.'/'.$this->adminB->id)->assertNotFound();
    $this->postJson($url, $this->payload + ['restaurant_id' => $this->restaurantB->id])->assertUnprocessable()->assertJsonValidationErrors('restaurant_id');
    $this->putJson($url.'/'.$this->adminA->id, ['user_id' => $this->adminB->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    expect(Hash::check('password', $this->adminB->fresh()->password))->toBeTrue();
    $this->actingAs($this->adminB, 'sanctum')->getJson($url)->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $this->adminB->id);
});

test('the last active admin cannot be removed demoted or deactivated', function () {
    $this->actingAs($this->adminA, 'sanctum');
    Employee::factory()->create(['role' => 'admin', 'user_id' => User::factory()->state(['user_type' => 'employee', 'active' => false])]);
    Employee::factory()->create(['role' => 'admin', 'user_id' => User::factory()->unverified()->state(['user_type' => 'employee'])]);
    $invalidAdmin = Employee::factory()->create(['role' => 'admin']);
    $invalidAdmin->user->update(['user_type' => 'customer']);
    $url = '/api/v1/admin/employees/'.$this->adminA->id;
    $this->deleteJson($url)->assertConflict();
    $this->putJson($url, ['role' => 'waiter'])->assertConflict();
    $this->putJson($url, ['active' => false])->assertConflict();
    $other = Employee::factory()->create(['role' => 'admin']);
    $this->putJson('/api/v1/admin/employees/'.$other->user_id, ['role' => 'waiter'])->assertOk();
    $replacement = Employee::factory()->create(['role' => 'admin']);
    $this->deleteJson($url)->assertNoContent();
    $this->assertDatabaseHas('employees', ['user_id' => $replacement->user_id, 'role' => 'admin']);
});

test('credential or privilege changes revoke sessions and pending OTPs', function (array $change) {
    $this->actingAs($this->adminA, 'sanctum');
    $employee = Employee::factory()->create();
    $user = $employee->user;
    $user->createToken('old');
    $code = VerificationCode::generate($user->id, 'login_2fa');
    $this->putJson('/api/v1/admin/employees/'.$employee->user_id, $change)->assertOk();
    expect($user->tokens()->count())->toBe(0);
    expect($code->fresh()->used)->toBeTrue();
})->with([
    [['password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!']],
    [['pin' => '0000']], [['email' => 'changed@delishio.com']], [['username' => 'changed']],
    [['active' => false]], [['role' => 'chef', 'station' => 'bar']],
]);

test('editing phone and rate preserves credentials and sessions', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $employee = Employee::factory()->create();
    $employee->user->createToken('keep');
    $this->putJson('/api/v1/admin/employees/'.$employee->user_id, ['phone' => '123', 'hourly_rate' => '999999.99'])
        ->assertOk()->assertJsonPath('data.hourly_rate', '999999.99');
    expect($employee->user->tokens()->count())->toBe(1);
});

test('deleted employees lose login and tokens but menu audit references remain', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $employee = Employee::factory()->create(['role' => 'admin']);
    $this->actingAs($employee->user, 'sanctum');
    $menu = MenuItem::factory()->create();
    $employee->user->createToken('revoke');
    $this->actingAs($this->adminA, 'sanctum')->deleteJson('/api/v1/admin/employees/'.$employee->user_id)->assertNoContent();
    expect(MenuAuditLog::where('menu_item_id', $menu->id)->firstOrFail()->employee_id)->toBe($employee->user_id);
    expect($employee->user->tokens()->count())->toBe(0);
    auth()->forgetGuards();
    $this->postJson('/api/v1/auth/login', ['login' => $employee->user->email, 'password' => 'password'])->assertUnauthorized();
});

test('employee insert failure rolls back the newly created user', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $dispatcher = Employee::getEventDispatcher();
    Employee::setEventDispatcher(clone $dispatcher);
    Employee::creating(fn () => throw new RuntimeException('Employee insert failed'));
    try {
        $this->postJson('/api/v1/admin/employees', $this->payload)->assertServerError();
    } finally {
        Employee::setEventDispatcher($dispatcher);
    }
    $this->assertDatabaseMissing('users', ['email' => $this->payload['email']]);
    expect(Employee::count())->toBe(1);
});

test('employee update failure rolls back both tables', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $employee = Employee::factory()->create();
    $originalName = $employee->user->name;
    $dispatcher = Employee::getEventDispatcher();
    Employee::setEventDispatcher(clone $dispatcher);
    Employee::updating(fn () => throw new RuntimeException('Employee update failed'));
    try {
        $this->putJson('/api/v1/admin/employees/'.$employee->user_id, ['name' => 'No guardar', 'hourly_rate' => '50'])->assertServerError();
    } finally {
        Employee::setEventDispatcher($dispatcher);
    }
    $this->assertDatabaseHas('users', ['id' => $employee->user_id, 'name' => $originalName]);
    expect($employee->fresh()->hourly_rate)->toBe('100.00');
});

test('employee endpoints refuse guests and all non admin roles', function (string $role) {
    if ($role !== 'guest') {
        $user = in_array($role, ['waiter', 'chef'])
            ? app(RestaurantContext::class)->run($this->restaurantA, fn () => Employee::factory()->create(['role' => $role, 'station' => $role === 'chef' ? 'bar' : null]))->user
            : User::factory()->create(['user_type' => $role]);
        $this->actingAs($user, 'sanctum');
    }
    $status = $role === 'guest' ? 401 : 403;
    $url = '/api/v1/admin/employees';
    $this->getJson($url)->assertStatus($status);
    $this->postJson($url, $this->payload)->assertStatus($status);
    $this->getJson($url.'/'.$this->adminA->id)->assertStatus($status);
    $this->putJson($url.'/'.$this->adminA->id, ['phone' => '123'])->assertStatus($status);
    $this->deleteJson($url.'/'.$this->adminA->id)->assertStatus($status);
})->with(['guest', 'waiter', 'chef', 'customer', 'super_admin']);

test('suspended restaurants cannot manage employees with existing tokens', function () {
    $token = $this->adminA->createToken('test')->plainTextToken;
    $this->restaurantA->update(['status' => 'suspended']);
    $this->withToken($token)->getJson('/api/v1/admin/employees')->assertForbidden();
    $this->postJson('/api/v1/admin/employees', $this->payload)->assertForbidden();
});

test('employee model cannot be rebound to another user or a non employee account', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $employee = Employee::factory()->create();
    expect(fn () => $employee->update(['user_id' => $this->adminB->id]))->toThrow(HttpException::class);
    expect(fn () => Employee::create(['user_id' => User::factory()->create(['user_type' => 'super_admin'])->id, 'role' => 'admin', 'pin' => '1234', 'hourly_rate' => 0]))->toThrow(HttpException::class);
});

test('updated passwords PINs and revoked tokens take effect in authentication', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $employee = Employee::factory()->create(['role' => 'admin']);
    $user = $employee->user;
    $token = $user->createToken('old')->plainTextToken;
    $this->putJson('/api/v1/admin/employees/'.$employee->user_id, [
        'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!', 'pin' => '0000',
    ])->assertOk();
    $service = app(AdminPinAuthorization::class);
    expect(fn () => $service->authorize($user->id, '123456', 'order.discount'))->toThrow(ValidationException::class);
    expect($service->authorize($user->id, '0000', 'order.discount')->user_id)->toBe($user->id);
    auth()->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/user')->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'password'])->assertUnauthorized();
    $this->postJson('/api/v1/auth/login', ['login' => $user->email, 'password' => 'NewPassword123!'])->assertOk()->assertJsonStructure(['access_token']);
});

test('chef station is rechecked against locked data when the bound employee becomes stale', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $employee = Employee::factory()->create(['role' => 'chef', 'station' => 'bar']);
    $dispatcher = Employee::getEventDispatcher();
    Employee::setEventDispatcher(clone $dispatcher);
    $changed = false;
    Employee::retrieved(function (Employee $bound) use ($employee, &$changed) {
        if ($bound->user_id === $employee->user_id && ! $changed) {
            $changed = true;
            DB::table('employees')->where('user_id', $employee->user_id)->update(['role' => 'waiter', 'station' => null]);
        }
    });
    try {
        $this->putJson('/api/v1/admin/employees/'.$employee->user_id, ['role' => 'chef'])
            ->assertUnprocessable()->assertJsonValidationErrors('station');
    } finally {
        Employee::setEventDispatcher($dispatcher);
    }
    $this->assertDatabaseHas('employees', ['user_id' => $employee->user_id, 'role' => 'waiter', 'station' => null]);
});
