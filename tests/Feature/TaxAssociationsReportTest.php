<?php

use App\Models\Category;
use App\Models\Employee;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Models\SuperCategory;
use App\Models\TaxClass;
use App\Models\User;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function taxAssociationItem(TaxClass $taxClass, array $attributes = []): MenuItem
{
    $superCategory = SuperCategory::factory()->for($taxClass)->create(['name' => 'Alimentos']);
    $category = Category::factory()->for($superCategory)->create(['name' => 'Platillos']);

    return MenuItem::factory()->for($category)->create($attributes);
}

beforeEach(function () {
    $this->restaurant = Restaurant::create([
        'name' => 'Restaurant A',
        'created_by_user_id' => User::factory()->create(['user_type' => 'super_admin'])->id,
    ]);
    $this->admin = app(RestaurantContext::class)->run($this->restaurant, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->url = '/api/v1/admin/reports/tax-associations';
});

test('the report shows the current tax association for active and inactive menu items', function () {
    $this->actingAs($this->admin, 'sanctum');
    $taxClass = TaxClass::factory()->create(['name' => 'General', 'percentage' => '16.25']);
    $zeroTax = TaxClass::factory()->create(['name' => 'Tasa cero', 'percentage' => '0.00']);
    $item = taxAssociationItem($taxClass, ['name' => 'A Platillo']);
    $inactive = taxAssociationItem($zeroTax, ['name' => 'B Platillo', 'active' => false]);
    MenuItem::factory()->for($item->category)->create(['name' => 'Eliminado'])->delete();

    $this->getJson($this->url)->assertOk()->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.menu_item_id', $item->id)
        ->assertJsonPath('data.0.menu_item_name', 'A Platillo')
        ->assertJsonPath('data.0.category.name', 'Platillos')
        ->assertJsonPath('data.0.super_category.name', 'Alimentos')
        ->assertJsonPath('data.0.tax_class', ['id' => $taxClass->id, 'name' => 'General', 'percentage' => '16.25'])
        ->assertJsonPath('data.1.menu_item_id', $inactive->id)
        ->assertJsonPath('data.1.active', false)
        ->assertJsonPath('data.1.tax_class.percentage', '0.00');

    $taxClass->update(['percentage' => '8.75']);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.0.tax_class.percentage', '8.75');
});

test('administrators only see menu and tax data from their restaurant', function () {
    $other = Restaurant::create(['name' => 'Restaurant B', 'created_by_user_id' => $this->restaurant->created_by_user_id]);
    $otherAdmin = app(RestaurantContext::class)->run($other, fn () => Employee::factory()->create(['role' => 'admin']))->user;
    $this->actingAs($otherAdmin, 'sanctum');
    $otherTax = TaxClass::factory()->create(['percentage' => '25.00']);
    $foreign = taxAssociationItem($otherTax);

    $this->actingAs($this->admin, 'sanctum');
    $localTax = TaxClass::factory()->create(['percentage' => '10.00']);
    $local = taxAssociationItem($localTax);
    $invalidLink = taxAssociationItem($localTax);
    DB::table('super_categories')->where('id', $invalidLink->category->super_category_id)
        ->update(['tax_class_id' => $otherTax->id]);

    $this->getJson($this->url)->assertOk()->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.menu_item_id', $local->id)
        ->assertJsonPath('data.0.tax_class.id', $localTax->id);
    $this->getJson($this->url.'?restaurant_id='.$other->id)
        ->assertUnprocessable()->assertJsonValidationErrors('restaurant_id');
    $this->actingAs($otherAdmin, 'sanctum')->getJson($this->url)->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.menu_item_id', $foreign->id)
        ->assertJsonPath('data.0.tax_class.id', $otherTax->id);
});

test('the report requires a restaurant administrator and validates page numbers', function () {
    $this->getJson($this->url)->assertUnauthorized();
    $waiter = app(RestaurantContext::class)->run($this->restaurant, fn () => Employee::factory()->create())->user;
    $this->actingAs($waiter, 'sanctum')->getJson($this->url)->assertForbidden();
    $this->actingAs($this->admin, 'sanctum')->getJson($this->url.'?page=0')
        ->assertUnprocessable()->assertJsonValidationErrors('page');
});
