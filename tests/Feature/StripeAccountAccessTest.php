<?php

use App\Models\Employee;
use App\Models\Restaurant;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->super = User::factory()->create(['user_type' => 'super_admin'])->refresh();
    $this->a = Restaurant::create(['name' => 'A', 'created_by_user_id' => $this->super->id]);
    $this->b = Restaurant::create(['name' => 'B', 'created_by_user_id' => $this->super->id, 'stripe_account_id' => 'acct_B']);
});

test('only the super administrator assigns a unique nullable Stripe account', function () {
    $this->actingAs($this->super, 'sanctum');
    $url = '/api/v1/auth/super-admin/restaurants/'.$this->a->id;
    $this->patchJson($url, ['stripe_account_id' => 'acct_A'])->assertOk()->assertJsonPath('data.stripe_account_id', 'acct_A');
    $this->patchJson($url, ['stripe_account_id' => 'acct_A'])->assertOk();
    $this->patchJson($url, ['stripe_account_id' => 'acct_B'])->assertUnprocessable()->assertJsonValidationErrors('stripe_account_id');
    $this->patchJson($url, ['stripe_account_id' => null])->assertOk()->assertJsonPath('data.stripe_account_id', null);
    $this->b->delete();
    $this->patchJson($url, ['stripe_account_id' => 'acct_B'])->assertUnprocessable();
});

test('Stripe account identifiers are validated', function ($account) {
    $this->actingAs($this->super, 'sanctum')->patchJson('/api/v1/auth/super-admin/restaurants/'.$this->a->id,
        ['stripe_account_id' => $account])->assertUnprocessable()->assertJsonValidationErrors('stripe_account_id');
})->with(['sk_test_secret', 'acct_bad-id', 'acct_', 123, 'acct_'.str_repeat('a', 256)]);

test('restaurant administrators cannot read or change Stripe configuration across tenants', function () {
    $admin = app(RestaurantContext::class)->run($this->a, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->actingAs($admin, 'sanctum');
    $this->patchJson('/api/v1/admin/restaurant/configuration', ['stripe_account_id' => 'acct_B'])->assertUnprocessable();
    $this->patchJson('/api/v1/auth/super-admin/restaurants/'.$this->b->id, ['stripe_account_id' => null])->assertForbidden();
    $this->getJson('/api/v1/admin/restaurant/configuration')->assertOk()->assertJsonMissingPath('data.stripe_account_id');
    $this->getJson('/api/v1/user')->assertOk()->assertJsonMissingPath('profile.restaurant.stripe_account_id');
    expect($this->b->fresh()->stripe_account_id)->toBe('acct_B');
});
