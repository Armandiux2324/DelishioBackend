<?php

use App\Models\Category;
use App\Models\Employee;
use App\Models\Restaurant;
use App\Models\SuperCategory;
use App\Models\TaxClass;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function catalogEmployee(Restaurant $restaurant, string $role = 'admin'): User
{
    $user = User::factory()->create(['user_type' => 'employee']);
    app(RestaurantContext::class)->run($restaurant, fn () => Employee::create([
        'user_id' => $user->id, 'role' => $role, 'hourly_rate' => 100, 'pin' => '123456',
    ]));

    return $user->refresh();
}

function catalogFixture(Restaurant $restaurant): array
{
    return app(RestaurantContext::class)->run($restaurant, function () {
        $tax = TaxClass::factory()->create(['name' => 'Impuesto', 'percentage' => '16.00']);
        $super = SuperCategory::factory()->create(['tax_class_id' => $tax->id, 'name' => 'Comida']);
        $category = Category::factory()->create(['super_category_id' => $super->id, 'name' => 'Entradas']);

        return ['tax-classes' => $tax, 'super-categories' => $super, 'categories' => $category];
    });
}

function catalogPayload(string $resource, array $fixture): array
{
    return match ($resource) {
        'tax-classes' => ['name' => 'Nuevo impuesto', 'percentage' => '8.25'],
        'super-categories' => ['name' => 'Bebidas', 'tax_class_id' => $fixture['tax-classes']->id],
        'categories' => ['name' => 'Sin alcohol', 'super_category_id' => $fixture['super-categories']->id, 'description' => 'Bebidas frías'],
    };
}

beforeEach(function () {
    $creator = User::factory()->create(['user_type' => 'super_admin']);
    $this->restaurantA = Restaurant::create(['name' => 'A', 'created_by_user_id' => $creator->id]);
    $this->restaurantB = Restaurant::create(['name' => 'B', 'created_by_user_id' => $creator->id]);
    $this->adminA = catalogEmployee($this->restaurantA);
    $this->adminB = catalogEmployee($this->restaurantB);
    $this->catalogA = catalogFixture($this->restaurantA);
    $this->catalogB = catalogFixture($this->restaurantB);
});

test('administrators can create read update and soft delete catalog records', function (string $resource) {
    $this->actingAs($this->adminA, 'sanctum');
    $payload = catalogPayload($resource, $this->catalogA);
    $response = $this->postJson('/api/v1/admin/'.$resource, $payload)->assertCreated();
    $id = $response->json('data.id');
    foreach ($payload as $field => $value) {
        $response->assertJsonPath('data.'.$field, $value);
    }
    if ($resource !== 'categories') {
        $response->assertJsonPath('data.restaurant_id', $this->restaurantA->id);
    }
    $this->getJson('/api/v1/admin/'.$resource.'/'.$id)->assertOk()->assertJsonPath('data.name', $payload['name']);
    $this->getJson('/api/v1/admin/'.$resource)->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);
    $this->patchJson('/api/v1/admin/'.$resource.'/'.$id, ['name' => 'Editado'])->assertOk()->assertJsonPath('data.name', 'Editado');
    $this->putJson('/api/v1/admin/'.$resource.'/'.$id, ['name' => 'Otro nombre'])->assertOk();
    $this->deleteJson('/api/v1/admin/'.$resource.'/'.$id)->assertNoContent();
    $table = $this->catalogA[$resource]->getTable();
    $this->assertSoftDeleted($table, ['id' => $id]);
    $this->getJson('/api/v1/admin/'.$resource.'/'.$id)->assertNotFound();
    $this->patchJson('/api/v1/admin/'.$resource.'/'.$id, ['name' => 'No'])->assertNotFound();
    $this->getJson('/api/v1/admin/'.$resource)->assertJsonCount(1, 'data');
})->with(['tax-classes', 'super-categories', 'categories']);

test('catalog lists and bindings isolate restaurants for all operations', function (string $resource) {
    $this->actingAs($this->adminA, 'sanctum');
    $foreign = $this->catalogB[$resource];
    $before = $foreign->getAttributes();
    $url = '/api/v1/admin/'.$resource;
    $this->getJson($url.'?restaurant_id='.$this->restaurantB->id)->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->catalogA[$resource]->id);
    $this->getJson($url.'/'.$foreign->id)->assertNotFound();
    $this->patchJson($url.'/'.$foreign->id, ['name' => 'Ataque'])->assertNotFound();
    $this->deleteJson($url.'/'.$foreign->id)->assertNotFound();
    $this->assertDatabaseHas($foreign->getTable(), $before);
    $this->actingAs($this->adminB, 'sanctum');
    $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $foreign->id);
})->with(['tax-classes', 'super-categories', 'categories']);

test('catalog endpoints require authentication', function (string $resource) {
    $url = '/api/v1/admin/'.$resource;
    $this->getJson($url)->assertUnauthorized();
    $this->postJson($url, catalogPayload($resource, $this->catalogA))->assertUnauthorized();
    $this->getJson($url.'/'.$this->catalogA[$resource]->id)->assertUnauthorized();
    $this->patchJson($url.'/'.$this->catalogA[$resource]->id, ['name' => 'No'])->assertUnauthorized();
    $this->deleteJson($url.'/'.$this->catalogA[$resource]->id)->assertUnauthorized();
})->with(['tax-classes', 'super-categories', 'categories']);

test('non administrator roles cannot access any catalog operation', function (string $resource, string $role) {
    $user = in_array($role, ['waiter', 'chef'], true)
        ? catalogEmployee($this->restaurantA, $role)
        : User::factory()->create(['user_type' => $role]);
    $this->actingAs($user, 'sanctum');
    $url = '/api/v1/admin/'.$resource;
    $this->getJson($url)->assertForbidden();
    $this->postJson($url, catalogPayload($resource, $this->catalogA))->assertForbidden();
    $this->getJson($url.'/'.$this->catalogA[$resource]->id)->assertForbidden();
    $this->patchJson($url.'/'.$this->catalogA[$resource]->id, ['name' => 'No'])->assertForbidden();
    $this->deleteJson($url.'/'.$this->catalogA[$resource]->id)->assertForbidden();
})->with(['tax-classes', 'super-categories', 'categories'])->with(['waiter', 'chef', 'customer', 'super_admin']);

test('suspended restaurants cannot use catalog endpoints with existing tokens', function (string $resource) {
    $token = $this->adminA->createToken('test')->plainTextToken;
    $this->restaurantA->update(['status' => 'suspended']);
    $url = '/api/v1/admin/'.$resource;
    $this->withToken($token)->getJson($url)->assertForbidden();
    $this->postJson($url, catalogPayload($resource, $this->catalogA))->assertForbidden();
    $this->deleteJson($url.'/'.$this->catalogA[$resource]->id)->assertForbidden();
})->with(['tax-classes', 'super-categories', 'categories']);

test('catalog creates require the fields defined in the model', function (string $resource) {
    $required = match ($resource) {
        'tax-classes' => ['name', 'percentage'],
        'super-categories' => ['name', 'tax_class_id'],
        'categories' => ['name', 'super_category_id'],
    };
    $this->actingAs($this->adminA, 'sanctum');
    $this->postJson('/api/v1/admin/'.$resource, [])->assertUnprocessable()->assertJsonValidationErrors($required);
})->with(['tax-classes', 'super-categories', 'categories']);

test('catalog validation rejects bad values on both create and update', function (string $resource, string $field, mixed $value) {
    $this->actingAs($this->adminA, 'sanctum');
    $model = $this->catalogA[$resource];
    $before = $model->getAttributes();
    $payload = array_replace(catalogPayload($resource, $this->catalogA), [$field => $value]);
    $this->postJson('/api/v1/admin/'.$resource, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->patchJson('/api/v1/admin/'.$resource.'/'.$model->id, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseHas($model->getTable(), $before);
})->with([
    ['tax-classes', 'name', null], ['tax-classes', 'name', ''], ['tax-classes', 'name', str_repeat('a', 256)],
    ['super-categories', 'name', null], ['super-categories', 'name', ''], ['super-categories', 'name', str_repeat('a', 256)],
    ['categories', 'name', null], ['categories', 'name', ''], ['categories', 'name', str_repeat('a', 256)],
    ['tax-classes', 'percentage', null], ['tax-classes', 'percentage', -1], ['tax-classes', 'percentage', '1000'],
    ['tax-classes', 'percentage', '1.001'], ['tax-classes', 'percentage', 'invalid'],
    ['super-categories', 'tax_class_id', null], ['super-categories', 'tax_class_id', 999999], ['super-categories', 'tax_class_id', 1.5],
    ['categories', 'super_category_id', null], ['categories', 'super_category_id', 999999], ['categories', 'super_category_id', 'invalid'],
    ['categories', 'description', ['invalid']], ['categories', 'description', str_repeat('é', 32768)],
    ['tax-classes', 'restaurant_id', 123], ['super-categories', 'restaurant_id', null], ['categories', 'restaurant_id', 123],
    ['tax-classes', 'id', 123], ['super-categories', 'id', null], ['categories', 'id', 123],
    ['tax-classes', 'deleted_at', null], ['super-categories', 'deleted_at', '2026-01-01'], ['categories', 'deleted_at', null],
]);

test('cross restaurant parent ids are rejected on create and update', function (string $resource, string $field, string $parentResource) {
    $this->actingAs($this->adminA, 'sanctum');
    $before = $this->catalogA[$resource]->getAttributes();
    $payload = array_replace(catalogPayload($resource, $this->catalogA), [$field => $this->catalogB[$parentResource]->id]);
    $this->postJson('/api/v1/admin/'.$resource, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->patchJson('/api/v1/admin/'.$resource.'/'.$this->catalogA[$resource]->id, [$field => $this->catalogB[$parentResource]->id])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseHas($this->catalogA[$resource]->getTable(), $before);
})->with([
    ['super-categories', 'tax_class_id', 'tax-classes'], ['categories', 'super_category_id', 'super-categories'],
]);

test('deleted parents cannot be assigned to catalog records', function (string $resource, string $field) {
    $parent = app(RestaurantContext::class)->run($this->restaurantA, function () use ($resource) {
        $parent = $resource === 'super-categories' ? TaxClass::factory()->create() : SuperCategory::factory()->create();
        $parent->delete();

        return $parent;
    });
    $this->actingAs($this->adminA, 'sanctum');
    $payload = array_replace(catalogPayload($resource, $this->catalogA), [$field => $parent->id]);
    $this->postJson('/api/v1/admin/'.$resource, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->patchJson('/api/v1/admin/'.$resource.'/'.$this->catalogA[$resource]->id, [$field => $parent->id])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([['super-categories', 'tax_class_id'], ['categories', 'super_category_id']]);

test('parents with active children return conflict and can be deleted in child first order', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $tax = $this->catalogA['tax-classes'];
    $super = $this->catalogA['super-categories'];
    $category = $this->catalogA['categories'];
    $this->deleteJson('/api/v1/admin/tax-classes/'.$tax->id)->assertConflict();
    $this->deleteJson('/api/v1/admin/super-categories/'.$super->id)->assertConflict();
    $this->assertNotSoftDeleted('tax_classes', ['id' => $tax->id]);
    $this->assertNotSoftDeleted('super_categories', ['id' => $super->id]);
    $this->deleteJson('/api/v1/admin/categories/'.$category->id)->assertNoContent();
    $this->deleteJson('/api/v1/admin/super-categories/'.$super->id)->assertNoContent();
    $this->deleteJson('/api/v1/admin/tax-classes/'.$tax->id)->assertNoContent();
    $this->assertDatabaseHas('categories', ['id' => $category->id, 'super_category_id' => $super->id]);
    $this->assertDatabaseHas('super_categories', ['id' => $super->id, 'tax_class_id' => $tax->id]);
});

test('category may move only to another active super category of the same restaurant', function () {
    $target = app(RestaurantContext::class)->run($this->restaurantA, fn () => SuperCategory::factory()->create());
    $this->actingAs($this->adminA, 'sanctum');
    $this->patchJson('/api/v1/admin/categories/'.$this->catalogA['categories']->id, ['super_category_id' => $target->id])
        ->assertOk()->assertJsonPath('data.super_category_id', $target->id);
});

test('super category may change to another active tax class of the same restaurant', function () {
    $target = app(RestaurantContext::class)->run($this->restaurantA, fn () => TaxClass::factory()->create());
    $this->actingAs($this->adminA, 'sanctum');
    $this->patchJson('/api/v1/admin/super-categories/'.$this->catalogA['super-categories']->id, ['tax_class_id' => $target->id])
        ->assertOk()->assertJsonPath('data.tax_class_id', $target->id);
    $this->deleteJson('/api/v1/admin/tax-classes/'.$this->catalogA['tax-classes']->id)->assertNoContent();
});

test('model guards reject cross restaurant parents outside HTTP validation', function () {
    $this->actingAs($this->adminA, 'sanctum');
    expect(fn () => SuperCategory::create(['name' => 'No', 'tax_class_id' => $this->catalogB['tax-classes']->id]))->toThrow(HttpException::class);
    expect(fn () => Category::create(['name' => 'No', 'super_category_id' => $this->catalogB['super-categories']->id]))->toThrow(HttpException::class);
    expect(fn () => $this->catalogB['categories']->update(['name' => 'No']))->toThrow(HttpException::class);
    expect(fn () => $this->catalogB['categories']->delete())->toThrow(HttpException::class);
});

test('indirect scope also protects joins or filters and bulk deletes', function () {
    $this->actingAs($this->adminA, 'sanctum');
    expect(Category::join('super_categories', 'super_categories.id', '=', 'categories.super_category_id')->count())->toBe(1);
    expect(Category::where('name', 'Entradas')->orWhere('name', 'Otro')->count())->toBe(1);
    expect(Category::whereKey($this->catalogB['categories']->id)->delete())->toBe(0);
    $this->assertNotSoftDeleted('categories', ['id' => $this->catalogB['categories']->id]);
});

test('catalog validation uses the administrators stored locale', function () {
    $this->adminA->update(['locale' => 'es']);
    $this->actingAs($this->adminA->refresh(), 'sanctum');
    $this->patchJson('/api/v1/admin/tax-classes/'.$this->catalogA['tax-classes']->id, ['percentage' => -1])
        ->assertUnprocessable()->assertJsonPath('errors.percentage.0', 'El campo porcentaje debe ser al menos 0.');
});

test('all catalog models fail closed without a restaurant context', function (string $model) {
    expect(fn () => $model::count())->toThrow(HttpException::class);
})->with([TaxClass::class, SuperCategory::class, Category::class]);

test('tax precision matches the DBML and permits zero tax', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $id = $this->catalogA['tax-classes']->id;
    $this->patchJson('/api/v1/admin/tax-classes/'.$id, ['percentage' => 0])->assertOk()->assertJsonPath('data.percentage', '0.00');
    $this->patchJson('/api/v1/admin/tax-classes/'.$id, ['percentage' => '999.99'])->assertOk()->assertJsonPath('data.percentage', '999.99');
});

test('category description is optional and can be cleared', function () {
    $this->actingAs($this->adminA, 'sanctum');
    $id = $this->catalogA['categories']->id;
    $this->patchJson('/api/v1/admin/categories/'.$id, ['description' => 'Texto'])->assertOk();
    $this->patchJson('/api/v1/admin/categories/'.$id, ['description' => null])->assertOk()->assertJsonPath('data.description', null);
});

test('catalog list pagination remains tenant scoped', function (string $resource) {
    $model = $this->catalogA[$resource]::class;
    app(RestaurantContext::class)->run($this->restaurantA, fn () => $model::factory()->count(21)->create());
    $this->actingAs($this->adminA, 'sanctum');
    $this->getJson('/api/v1/admin/'.$resource)->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 22);
    $this->getJson('/api/v1/admin/'.$resource.'?page=2')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 22);
})->with(['tax-classes', 'super-categories', 'categories']);

test('category schema preserves indirect restaurant ownership', function () {
    expect(Schema::hasColumn('categories', 'restaurant_id'))->toBeFalse();
});
