<?php

use App\Models\Category;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\Promotion;
use App\Models\PromotionCategory;
use App\Models\PromotionMenuItem;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $creator = User::factory()->create(['user_type' => 'super_admin']);
    $this->restaurantA = Restaurant::create(['name' => 'A', 'created_by_user_id' => $creator->id]);
    $this->restaurantB = Restaurant::create(['name' => 'B', 'created_by_user_id' => $creator->id]);
    $this->adminA = app(RestaurantContext::class)->run($this->restaurantA, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->adminB = app(RestaurantContext::class)->run($this->restaurantB, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->actingAs($this->adminA, 'sanctum');
    $this->categoryA = Category::factory()->create();
    $this->categoryA2 = Category::factory()->create();
    $this->menuA = MenuItem::factory()->create(['category_id' => $this->categoryA->id]);
    $this->menuA2 = MenuItem::factory()->create(['category_id' => $this->categoryA->id]);
    $this->actingAs($this->adminB, 'sanctum');
    $this->categoryB = Category::factory()->create();
    $this->menuB = MenuItem::factory()->create(['category_id' => $this->categoryB->id]);
    $this->promotionB = Promotion::factory()->create();
    $this->promotionB->menuItems()->attach($this->menuB->id);
    $this->promotionB->categories()->attach($this->categoryB->id);
    $this->actingAs($this->adminA, 'sanctum');
    $this->payload = [
        'name' => 'Tarde Delishio', 'condition_type' => 'min_amount', 'condition_value' => '100.00',
        'discount_type' => 'percentage', 'discount_value' => '10.50', 'days_of_week' => 'Mon,Tue,Wed',
        'time_start' => '17:00', 'time_end' => '20:30', 'valid_from' => '2026-11-01', 'valid_to' => '2026-12-31',
        'menu_item_ids' => [$this->menuA->id], 'category_ids' => [$this->categoryA->id],
    ];
});

test('administrators manage promotions with exact values and target associations', function () {
    $response = $this->postJson('/api/v1/admin/promotions', $this->payload)->assertCreated()
        ->assertJsonPath('data.restaurant_id', $this->restaurantA->id)->assertJsonPath('data.discount_value', '10.50')
        ->assertJsonPath('data.condition_value', '100.00')->assertJsonPath('data.active', true)
        ->assertJsonPath('data.time_start', '17:00:00')->assertJsonPath('data.valid_from', '2026-11-01')
        ->assertJsonPath('data.menu_item_ids', [$this->menuA->id])->assertJsonPath('data.category_ids', [$this->categoryA->id]);
    $id = $response->json('data.id');
    $url = '/api/v1/admin/promotions/'.$id;
    $this->getJson($url)->assertOk();
    $this->getJson('/api/v1/admin/promotions')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 1);
    $this->putJson($url, ['name' => 'Editada', 'menu_item_ids' => [$this->menuA2->id], 'category_ids' => [$this->categoryA2->id]])
        ->assertOk()->assertJsonPath('data.name', 'Editada')->assertJsonPath('data.menu_item_ids', [$this->menuA2->id]);
    $this->assertDatabaseMissing('promotion_menu_items', ['promotion_id' => $id, 'menu_item_id' => $this->menuA->id]);
    $this->assertDatabaseHas('promotion_categories', ['promotion_id' => $id, 'category_id' => $this->categoryA2->id]);
    $this->putJson($url, ['active' => false])->assertOk()->assertJsonPath('data.active', false)->assertJsonPath('data.category_ids', [$this->categoryA2->id]);
    $this->putJson($url, ['menu_item_ids' => [], 'category_ids' => [], 'days_of_week' => null, 'time_start' => null, 'time_end' => null])
        ->assertOk()->assertJsonCount(0, 'data.menu_item_ids')->assertJsonCount(0, 'data.category_ids')->assertJsonPath('data.days_of_week', null);
    $this->deleteJson($url)->assertNoContent();
    $this->assertSoftDeleted('promotions', ['id' => $id]);
    $this->getJson($url)->assertNotFound();
    $this->putJson($url, ['name' => 'No'])->assertNotFound();
    $this->getJson('/api/v1/admin/promotions')->assertJsonCount(0, 'data');
});

test('all condition and discount types from DBML can be represented', function (string $condition, ?string $value, string $discount) {
    $this->postJson('/api/v1/admin/promotions', array_replace($this->payload, [
        'condition_type' => $condition, 'condition_value' => $value, 'discount_type' => $discount,
    ]))->assertCreated()->assertJsonPath('data.condition_type', $condition)->assertJsonPath('data.condition_value', $value)->assertJsonPath('data.discount_type', $discount);
})->with([['min_amount', '100.00'], ['product_quantity', '2.00'], ['category_discount', null]])->with(['percentage', 'fixed_amount']);

test('minimal promotions support unrestricted schedules and inclusive same day validity', function () {
    $this->postJson('/api/v1/admin/promotions', [
        'name' => 'Todo el día', 'condition_type' => 'min_amount', 'condition_value' => '0.01',
        'discount_type' => 'percentage', 'discount_value' => '100', 'valid_from' => '2026-11-01', 'valid_to' => '2026-11-01',
    ])->assertCreated()->assertJsonPath('data.discount_value', '100.00')->assertJsonPath('data.time_start', null)
        ->assertJsonPath('data.days_of_week', null)->assertJsonCount(0, 'data.menu_item_ids');
    $this->postJson('/api/v1/admin/promotions', array_replace($this->payload, ['time_start' => '22:00:00', 'time_end' => '02:00:00']))
        ->assertCreated()->assertJsonPath('data.time_end', '02:00:00');
});

test('mandatory promotion fields are enforced', function () {
    $this->postJson('/api/v1/admin/promotions', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'condition_type', 'discount_type', 'discount_value', 'valid_from', 'valid_to']);
    $payload = $this->payload;
    unset($payload['condition_value']);
    $this->postJson('/api/v1/admin/promotions', $payload)->assertUnprocessable()->assertJsonValidationErrors('condition_value');
});

test('promotion validation rejects invalid scalar values and server identifiers', function (string $field, mixed $value) {
    $promotion = Promotion::factory()->create();
    $this->postJson('/api/v1/admin/promotions', array_replace($this->payload, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->putJson('/api/v1/admin/promotions/'.$promotion->id, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    ['name', ''], ['name', str_repeat('a', 256)], ['condition_type', 'other'], ['condition_type', null],
    ['condition_value', '0'], ['condition_value', '-1'], ['condition_value', '1.001'], ['condition_value', '100000000'],
    ['discount_type', 'other'], ['discount_value', '0'], ['discount_value', '-1'], ['discount_value', '100.01'],
    ['discount_value', '1.001'], ['discount_value', '100000000'], ['active', null], ['active', 'yes'],
    ['days_of_week', 'Monday'], ['days_of_week', 'Mon,Mon'], ['days_of_week', 'Mon,'], ['days_of_week', 'mon'],
    ['time_start', '24:00'], ['time_end', '12:60'], ['valid_from', '2026-02-30'], ['valid_to', '11/30/2026'],
    ['valid_to', null], ['menu_item_ids', null], ['category_ids', 'bad'], ['menu_item_ids', ['x' => 1]],
    ['id', null], ['restaurant_id', 999], ['deleted_at', null], ['created_at', null], ['updated_at', null],
]);

test('numeric precision and condition specific limits are validated', function () {
    $this->postJson('/api/v1/admin/promotions', array_replace($this->payload, ['condition_type' => 'product_quantity', 'condition_value' => '1.50']))
        ->assertUnprocessable()->assertJsonValidationErrors('condition_value');
    $this->postJson('/api/v1/admin/promotions', array_replace($this->payload, ['condition_type' => 'category_discount', 'condition_value' => null, 'category_ids' => []]))
        ->assertUnprocessable()->assertJsonValidationErrors('category_ids');
    $this->postJson('/api/v1/admin/promotions', array_replace($this->payload, ['condition_type' => 'category_discount']))
        ->assertUnprocessable()->assertJsonValidationErrors('condition_value');
    $this->postJson('/api/v1/admin/promotions', array_replace($this->payload, ['condition_value' => '99999999.99', 'discount_type' => 'fixed_amount', 'discount_value' => '99999999.99']))
        ->assertCreated()->assertJsonPath('data.discount_value', '99999999.99');
});

test('partial edits validate the final discount condition dates and time window', function () {
    $id = $this->postJson('/api/v1/admin/promotions', $this->payload)->assertCreated()->json('data.id');
    $url = '/api/v1/admin/promotions/'.$id;
    $this->putJson($url, ['valid_from' => '2027-01-01'])->assertUnprocessable()->assertJsonValidationErrors('valid_to');
    $this->putJson($url, ['time_end' => null])->assertUnprocessable()->assertJsonValidationErrors('time_end');
    $this->putJson($url, ['time_start' => '20:30'])->assertUnprocessable()->assertJsonValidationErrors('time_end');
    $this->putJson($url, ['discount_type' => 'fixed_amount', 'discount_value' => '500'])->assertOk();
    $this->putJson($url, ['discount_type' => 'percentage'])->assertUnprocessable()->assertJsonValidationErrors('discount_value');
    $this->putJson($url, ['condition_type' => 'category_discount'])->assertOk()->assertJsonPath('data.condition_value', null);
    $this->putJson($url, ['category_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('category_ids');
    $this->putJson($url, ['condition_type' => 'product_quantity'])->assertUnprocessable()->assertJsonValidationErrors('condition_value');
    $this->putJson($url, ['condition_type' => 'product_quantity', 'condition_value' => '2'])->assertOk();
});

test('promotion bindings and lists isolate restaurants for every operation', function () {
    $id = $this->postJson('/api/v1/admin/promotions', $this->payload)->assertCreated()->json('data.id');
    $url = '/api/v1/admin/promotions';
    $this->getJson($url.'?restaurant_id='.$this->restaurantB->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    $this->getJson($url.'/'.$this->promotionB->id)->assertNotFound();
    $this->putJson($url.'/'.$this->promotionB->id, ['name' => 'Ataque', 'menu_item_ids' => []])->assertNotFound();
    $this->deleteJson($url.'/'.$this->promotionB->id)->assertNotFound();
    $this->actingAs($this->adminB, 'sanctum')->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->promotionB->id);
});

test('foreign deleted missing and duplicate target associations are rejected', function (string $kind) {
    $field = $kind === 'menu' ? 'menu_item_ids' : 'category_ids';
    $foreign = $kind === 'menu' ? $this->menuB : $this->categoryB;
    $local = $kind === 'menu' ? $this->menuA : $this->categoryA2;
    $this->postJson('/api/v1/admin/promotions', array_replace($this->payload, [$field => [$foreign->id]]))->assertUnprocessable()->assertJsonValidationErrors($field.'.0');
    $this->postJson('/api/v1/admin/promotions', array_replace($this->payload, [$field => [999999]]))->assertUnprocessable()->assertJsonValidationErrors($field.'.0');
    $this->postJson('/api/v1/admin/promotions', array_replace($this->payload, [$field => [$local->id, $local->id]]))->assertUnprocessable()->assertJsonValidationErrors($field.'.0');
    $local->delete();
    $this->postJson('/api/v1/admin/promotions', array_replace($this->payload, [$field => [$local->id]]))->assertUnprocessable()->assertJsonValidationErrors($field.'.0');
})->with(['menu', 'category']);

test('target attachment failures roll back promotion and all links on create and update', function (string $operation) {
    $promotion = null;
    if ($operation === 'update') {
        $id = $this->postJson('/api/v1/admin/promotions', $this->payload)->assertCreated()->json('data.id');
        $promotion = Promotion::findOrFail($id);
    }
    $dispatcher = PromotionCategory::getEventDispatcher();
    PromotionCategory::setEventDispatcher(clone $dispatcher);
    PromotionCategory::creating(fn () => throw new RuntimeException('Target attach failed'));
    try {
        if ($promotion) {
            $this->putJson('/api/v1/admin/promotions/'.$promotion->id, [
                'name' => 'No guardar', 'menu_item_ids' => [$this->menuA2->id], 'category_ids' => [$this->categoryA2->id],
            ])->assertServerError();
        } else {
            $this->postJson('/api/v1/admin/promotions', $this->payload)->assertServerError();
        }
    } finally {
        PromotionCategory::setEventDispatcher($dispatcher);
    }
    if ($promotion) {
        $this->assertDatabaseHas('promotions', ['id' => $promotion->id, 'name' => 'Tarde Delishio']);
        expect($promotion->menuItems()->pluck('menu_items.id')->all())->toBe([$this->menuA->id]);
        expect($promotion->categories()->pluck('categories.id')->all())->toBe([$this->categoryA->id]);
    } else {
        expect(Promotion::count())->toBe(0);
        expect(PromotionMenuItem::count())->toBe(0);
        expect(PromotionCategory::count())->toBe(0);
    }
})->with(['create', 'update']);

test('catalog targets cannot be deleted until their promotion is removed', function () {
    $id = $this->postJson('/api/v1/admin/promotions', array_replace($this->payload, ['category_ids' => [$this->categoryA2->id]]))->assertCreated()->json('data.id');
    $this->deleteJson('/api/v1/admin/menu-items/'.$this->menuA->id)->assertConflict();
    $this->deleteJson('/api/v1/admin/categories/'.$this->categoryA2->id)->assertConflict();
    $this->putJson('/api/v1/admin/promotions/'.$id, ['active' => false])->assertOk();
    $this->deleteJson('/api/v1/admin/menu-items/'.$this->menuA->id)->assertConflict();
    $this->deleteJson('/api/v1/admin/promotions/'.$id)->assertNoContent();
    $this->deleteJson('/api/v1/admin/menu-items/'.$this->menuA->id)->assertNoContent();
    $this->deleteJson('/api/v1/admin/categories/'.$this->categoryA2->id)->assertNoContent();
    $this->assertDatabaseHas('promotion_menu_items', ['promotion_id' => $id, 'menu_item_id' => $this->menuA->id]);
});

test('promotion models and pivot writes enforce tenant ownership', function () {
    $id = $this->postJson('/api/v1/admin/promotions', $this->payload)->assertCreated()->json('data.id');
    $promotion = Promotion::findOrFail($id);
    expect(PromotionMenuItem::count())->toBe(1);
    expect(PromotionCategory::count())->toBe(1);
    expect(fn () => $promotion->menuItems()->attach($this->menuB->id))->toThrow(HttpException::class);
    expect(fn () => $promotion->categories()->attach($this->categoryB->id))->toThrow(HttpException::class);
    expect(fn () => $this->promotionB->menuItems()->sync([]))->toThrow(HttpException::class);
    expect(fn () => $this->promotionB->categories()->sync([]))->toThrow(HttpException::class);
    expect(fn () => $this->promotionB->update(['name' => 'No']))->toThrow(HttpException::class);
    expect(fn () => $this->promotionB->delete())->toThrow(HttpException::class);
    $pivot = $promotion->menuItems()->firstOrFail()->pivot;
    expect(fn () => $pivot->update(['menu_item_id' => $this->menuB->id]))->toThrow(HttpException::class);
    auth()->forgetGuards();
    expect(fn () => Promotion::count())->toThrow(HttpException::class);
    expect(fn () => PromotionMenuItem::count())->toThrow(HttpException::class);
    expect(fn () => PromotionCategory::count())->toThrow(HttpException::class);
});

test('effective discount state is rechecked after acquiring the row lock', function () {
    $promotion = Promotion::factory()->create(['discount_type' => 'fixed_amount', 'discount_value' => '10.00']);
    $dispatcher = Promotion::getEventDispatcher();
    Promotion::setEventDispatcher(clone $dispatcher);
    $changed = false;
    Promotion::retrieved(function (Promotion $bound) use ($promotion, &$changed) {
        if ($bound->id === $promotion->id && ! $changed) {
            $changed = true;
            DB::table('promotions')->where('id', $promotion->id)->update(['discount_value' => '500.00']);
        }
    });
    try {
        $this->putJson('/api/v1/admin/promotions/'.$promotion->id, ['discount_type' => 'percentage'])
            ->assertUnprocessable()->assertJsonValidationErrors('discount_value');
    } finally {
        Promotion::setEventDispatcher($dispatcher);
    }
    $this->assertDatabaseHas('promotions', ['id' => $promotion->id, 'discount_type' => 'fixed_amount', 'discount_value' => 500]);
});

test('promotion operations require authentication and administrator role', function (string $role) {
    $promotion = Promotion::factory()->create();
    if ($role === 'guest') {
        auth()->forgetGuards();
    } else {
        $user = in_array($role, ['waiter', 'chef'])
            ? Employee::factory()->create(['role' => $role, 'station' => $role === 'chef' ? 'bar' : null])->user
            : User::factory()->create(['user_type' => $role]);
        $this->actingAs($user, 'sanctum');
    }
    $status = $role === 'guest' ? 401 : 403;
    $url = '/api/v1/admin/promotions';
    $this->getJson($url)->assertStatus($status);
    $this->postJson($url, $this->payload)->assertStatus($status);
    $this->getJson($url.'/'.$promotion->id)->assertStatus($status);
    $this->putJson($url.'/'.$promotion->id, ['name' => 'No'])->assertStatus($status);
    $this->deleteJson($url.'/'.$promotion->id)->assertStatus($status);
})->with(['guest', 'waiter', 'chef', 'customer', 'super_admin']);

test('suspended restaurants cannot manage promotions with existing tokens', function () {
    $token = $this->adminA->createToken('test')->plainTextToken;
    auth()->forgetGuards();
    $this->restaurantA->update(['status' => 'suspended']);
    $this->withToken($token)->getJson('/api/v1/admin/promotions')->assertForbidden();
    $this->postJson('/api/v1/admin/promotions', $this->payload)->assertForbidden();
});
