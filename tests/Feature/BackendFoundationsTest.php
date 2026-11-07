<?php

use App\Mail\SendVerificationCode;
use App\Models\Employee;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\RestaurantContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function foundationEmployee(?Restaurant $restaurant = null): User
{
    $restaurant ??= Restaurant::create([
        'name' => 'Restaurante',
        'created_by_user_id' => User::factory()->create(['user_type' => 'super_admin'])->id,
    ]);
    $user = User::factory()->create(['user_type' => 'employee']);
    app(RestaurantContext::class)->run($restaurant, fn () => Employee::create([
        'user_id' => $user->id, 'role' => 'admin', 'hourly_rate' => 100, 'pin' => '123456',
    ]));

    return $user->refresh();
}

beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum', 'account.active', 'role:admin'])->prefix('api/v1/admin/_test')->group(function () {
        Route::get('/employees', fn () => Employee::orderBy('user_id')->get());
        Route::get('/employees/{employee}', fn (Employee $employee) => $employee);
        Route::patch('/employees/{employee}', function (Request $request, Employee $employee) {
            $employee->update($request->only(['phone', 'restaurant_id']));

            return $employee;
        });
        Route::delete('/employees/{employee}', function (Employee $employee) {
            $employee->delete();

            return response()->noContent();
        });
        Route::post('/employees', fn (Request $request) => Employee::create($request->all()));
    });
});

test('admin routes without a restaurant parameter isolate reads and writes', function () {
    $admin = foundationEmployee();
    $other = foundationEmployee();
    $this->withToken($admin->createToken('test')->plainTextToken);
    $this->getJson('/api/v1/admin/_test/employees')->assertOk()->assertJsonCount(1)->assertJsonPath('0.user_id', $admin->id);
    $this->getJson('/api/v1/admin/_test/employees/'.$other->id)->assertNotFound();
    $this->patchJson('/api/v1/admin/_test/employees/'.$other->id, ['phone' => 'changed'])->assertNotFound();
    $this->deleteJson('/api/v1/admin/_test/employees/'.$other->id)->assertNotFound();
    $this->patchJson('/api/v1/admin/_test/employees/'.$admin->id, ['phone' => '123'])->assertOk()->assertJsonPath('phone', '123');
    $this->assertDatabaseHas('employees', ['user_id' => $other->id, 'phone' => null]);
});

test('restaurant id is filled automatically and cannot be supplied from another restaurant', function () {
    $admin = foundationEmployee();
    $other = foundationEmployee();
    $new = User::factory()->create(['user_type' => 'employee']);
    $this->withToken($admin->createToken('test')->plainTextToken);
    $payload = ['user_id' => $new->id, 'role' => 'waiter', 'hourly_rate' => 100, 'pin' => '123456'];
    $this->postJson('/api/v1/admin/_test/employees', $payload + ['restaurant_id' => $other->employee->restaurant_id])->assertForbidden();
    $this->postJson('/api/v1/admin/_test/employees', $payload)->assertCreated()->assertJsonPath('restaurant_id', $admin->employee->restaurant_id);
    $this->patchJson('/api/v1/admin/_test/employees/'.$admin->id, ['restaurant_id' => $other->employee->restaurant_id])->assertForbidden();
    $this->assertDatabaseHas('employees', ['user_id' => $admin->id, 'restaurant_id' => $admin->employee->restaurant_id]);
});

test('tenant records deny access without employee or explicit context', function (string $type) {
    foundationEmployee();
    if ($type !== 'guest') {
        $this->actingAs(User::factory()->create(['user_type' => $type]), 'sanctum');
    }
    expect(fn () => Employee::count())->toThrow(HttpException::class);
})->with(['guest', 'customer', 'super_admin']);

test('explicit context restores after success nested calls and exceptions', function () {
    $first = foundationEmployee()->employee->restaurant;
    $second = foundationEmployee()->employee->restaurant;
    $context = app(RestaurantContext::class);
    $context->run($first, function () use ($context, $first, $second) {
        expect(Employee::pluck('restaurant_id')->all())->toBe([$first->id]);
        $context->run($second, fn () => expect(Employee::pluck('restaurant_id')->all())->toBe([$second->id]));
        expect($context->id())->toBe($first->id);
        expect(fn () => $context->run($second, fn () => throw new RuntimeException('test')))->toThrow(RuntimeException::class);
        expect($context->id())->toBe($first->id);
    });
    expect(fn () => $context->id())->toThrow(HttpException::class);
});

test('tenant scope qualifies joined queries and grouped or filters', function () {
    $admin = foundationEmployee();
    foundationEmployee();
    $this->actingAs($admin, 'sanctum');
    expect(Employee::join('restaurants', 'restaurants.id', '=', 'employees.restaurant_id')->count())->toBe(1);
    expect(Employee::where('role', 'admin')->orWhere('role', 'chef')->count())->toBe(1);
});

test('automatic tenant context changes with the authenticated employee', function () {
    $first = foundationEmployee();
    $second = foundationEmployee();
    $this->actingAs($first, 'sanctum');
    expect(Employee::pluck('user_id')->all())->toBe([$first->id]);
    $this->actingAs($second, 'sanctum');
    expect(Employee::pluck('user_id')->all())->toBe([$second->id]);
});

test('bulk deletion cannot delete a different restaurants employees', function () {
    $admin = foundationEmployee();
    $other = foundationEmployee();
    $this->actingAs($admin, 'sanctum');
    expect(Employee::where('user_id', $other->id)->delete())->toBe(0);
    $this->assertDatabaseHas('employees', ['user_id' => $other->id]);
});

test('unscoped foreign model instances cannot be saved or deleted', function () {
    $admin = foundationEmployee();
    $other = foundationEmployee();
    $foreign = $other->employee;
    $this->actingAs($admin, 'sanctum');
    expect(fn () => $foreign->update(['phone' => 'changed']))->toThrow(HttpException::class);
    expect(fn () => $foreign->delete())->toThrow(HttpException::class);
    $this->assertDatabaseHas('employees', ['user_id' => $other->id, 'phone' => null]);
});

test('bulk updates are scoped and cannot move tenant records', function () {
    $admin = foundationEmployee();
    $other = foundationEmployee();
    $this->actingAs($admin, 'sanctum');
    expect(Employee::query()->update(['phone' => '123']))->toBe(1);
    expect(fn () => Employee::query()->update(['restaurant_id' => $other->employee->restaurant_id]))->toThrow(LogicException::class);
    expect(fn () => Employee::query()->upsert([['user_id' => $other->id]], ['user_id']))->toThrow(LogicException::class);
    $this->assertDatabaseHas('employees', ['user_id' => $other->id, 'phone' => null]);
});

test('cors permits only explicitly configured pwa origins including preflight', function () {
    config(['cors.allowed_origins' => ['https://admin.delishio.test', 'https://cliente.delishio.test']]);
    $this->withHeaders(['Origin' => 'https://admin.delishio.test'])->getJson('/api/v1/user')
        ->assertUnauthorized()->assertHeader('Access-Control-Allow-Origin', 'https://admin.delishio.test');
    $this->withHeaders(['Origin' => 'https://untrusted.test'])->getJson('/api/v1/user')->assertHeaderMissing('Access-Control-Allow-Origin');
    $this->withHeaders([
        'Origin' => 'https://cliente.delishio.test', 'Access-Control-Request-Method' => 'POST',
        'Access-Control-Request-Headers' => 'authorization,content-type',
    ])->options('/api/v1/auth/login')->assertNoContent()->assertHeader('Access-Control-Allow-Origin', 'https://cliente.delishio.test');
    config(['cors.allowed_origins' => []]);
    $this->withHeaders(['Origin' => 'https://admin.delishio.test'])->getJson('/api/v1/user')->assertHeaderMissing('Access-Control-Allow-Origin');
});

test('super admin seed creates a verified account without changing credentials on rerun', function () {
    config(['superadmin' => ['name' => 'Platform Admin', 'email' => 'super@example.com', 'password' => 'test-secret-12345', 'locale' => 'es']]);
    $this->seed(DatabaseSeeder::class);
    $user = User::where('email', 'super@example.com')->firstOrFail();
    expect($user->user_type)->toBe('super_admin');
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->two_factor_enabled)->toBeTrue();
    expect(Hash::check('test-secret-12345', $user->password))->toBeTrue();
    $hash = $user->password;
    config(['superadmin.password' => 'different-secret-123']);
    $this->seed(SuperAdminSeeder::class);
    expect($user->fresh()->password)->toBe($hash);
    expect(User::count())->toBe(1);
});

test('super admin seed fails without credentials and never promotes existing accounts', function () {
    config(['superadmin' => ['name' => '', 'email' => '', 'password' => '', 'locale' => 'en']]);
    expect(fn () => $this->seed(SuperAdminSeeder::class))->toThrow(ValidationException::class);
    expect(User::count())->toBe(0);
    $customer = User::factory()->create();
    config(['superadmin' => ['name' => 'Admin', 'email' => $customer->email, 'password' => 'test-secret-12345', 'locale' => 'en']]);
    expect(fn () => $this->seed(SuperAdminSeeder::class))->toThrow(RuntimeException::class);
    expect($customer->fresh()->user_type)->toBe('customer');
});

test('super admin seed never resurrects a deleted account', function () {
    $user = User::factory()->create(['user_type' => 'super_admin']);
    $user->delete();
    config(['superadmin' => ['name' => 'Admin', 'email' => $user->email, 'password' => 'test-secret-12345', 'locale' => 'en']]);
    expect(fn () => $this->seed(SuperAdminSeeder::class))->toThrow(RuntimeException::class);
    $this->assertSoftDeleted('users', ['id' => $user->id]);
});

test('authenticated validation uses stored locale instead of the browser preference', function (string $locale, string $message) {
    $user = User::factory()->create(['locale' => $locale]);
    $this->withToken($user->createToken('test')->plainTextToken)->withHeader('Accept-Language', $locale === 'es' ? 'en' : 'es')
        ->postJson('/api/v1/auth/2fa/enable', [])->assertUnprocessable()->assertJsonPath('errors.password.0', $message);
    expect(app()->getLocale())->toBe(config('app.locale'));
})->with([
    ['es', 'El campo contraseña es obligatorio.'],
    ['en', 'The password field is required.'],
]);

test('guest validation supports spanish and locale resets between requests', function () {
    $this->withHeader('Accept-Language', 'es-MX,es;q=0.9')->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()->assertJsonPath('errors.password.0', 'El campo contraseña es obligatorio.');
    $this->withHeader('Accept-Language', 'en')->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()->assertJsonPath('errors.password.0', 'The password field is required.');
});

test('all otp flows queue mail in the recipients locale', function (string $endpoint, string $type) {
    Mail::fake();
    $user = User::factory()->unverified()->create(['locale' => 'es']);
    if ($type === 'login_2fa') {
        $user->forceFill(['email_verified_at' => now(), 'two_factor_enabled' => true])->save();
    }
    $this->withHeader('Accept-Language', 'en')->postJson('/api/v1/auth/'.$endpoint, ['email' => $user->email, 'password' => 'password'])->assertOk();
    Mail::assertQueued(SendVerificationCode::class, fn ($mail) => $mail->locale === 'es' && $mail->hasTo($user->email));
    expect($user->verificationCodes()->where('type', $type)->count())->toBe(1);
})->with([
    ['verify-email/resend', 'email_verification'], ['password/forgot', 'password_reset'], ['login', 'login_2fa'],
]);

test('otp mail renders translated subject and body even outside its originating request', function (string $locale, string $subject, string $body) {
    $user = User::factory()->create(['locale' => $locale]);
    config(['mail.default' => 'array', 'queue.default' => 'sync']);
    app()->setLocale($locale === 'es' ? 'en' : 'es');
    Mail::to($user)->send(new SendVerificationCode('012345', 'Verifica tu cuenta'));
    $message = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    expect($message->getSubject())->toBe($subject);
    expect($message->getHtmlBody())->toContain($body)->toContain('012345');
    expect(DB::table('jobs')->count())->toBe(0);
})->with([
    ['es', 'Verifica tu cuenta', 'Tu código de verificación es:'],
    ['en', 'Verify your account', 'Your verification code is:'],
]);

test('signup stores the selected locale and rejects unsupported languages', function () {
    Mail::fake();
    $payload = ['name' => 'Cliente', 'email' => 'new@example.com', 'phone' => '123', 'password' => 'password123', 'password_confirmation' => 'password123'];
    $this->postJson('/api/v1/auth/register', $payload + ['locale' => 'fr'])->assertUnprocessable()->assertJsonValidationErrors('locale');
    $this->postJson('/api/v1/auth/register', $payload + ['locale' => 'es'])->assertCreated();
    expect(User::where('email', 'new@example.com')->firstOrFail()->locale)->toBe('es');
    Mail::assertQueued(SendVerificationCode::class, fn ($mail) => $mail->locale === 'es');
});

test('queued otp keeps its recipients language when processed by a worker', function () {
    $user = User::factory()->create(['locale' => 'es']);
    config(['mail.default' => 'array', 'queue.default' => 'database']);
    Mail::to($user)->send(new SendVerificationCode('123456', 'Código de inicio de sesión'));
    $payload = json_decode(DB::table('jobs')->first()->payload, true);
    $job = unserialize($payload['data']['command']);
    app()->setLocale('en');
    app()->call([$job, 'handle']);
    $message = Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    expect($message->getSubject())->toBe('Código de inicio de sesión');
    expect($message->getHtmlBody())->toContain('Tu código de verificación es:');
    expect(app()->getLocale())->toBe('en');
});

test('development env template has one sync queue setting', function () {
    $template = file_get_contents(base_path('.env.example'));
    preg_match_all('/^QUEUE_CONNECTION=(.+)$/m', $template, $matches);
    expect(array_map('trim', $matches[1]))->toBe(['sync']);
});

test('CRUD update endpoints reject PATCH', function (string $path) {
    $this->patchJson('/api/v1/'.$path.'/1')->assertMethodNotAllowed();
})->with([
    'admin/promotions',
    'admin/employees',
    'admin/menu-items',
    'admin/inventory-items',
    'admin/tax-classes',
    'admin/super-categories',
    'admin/categories',
    'auth/super-admin/restaurants',
]);
