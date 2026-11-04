<?php

use App\Models\Employee;
use App\Models\Restaurant;
use App\Models\User;
use App\Models\WorkShift;
use App\Support\RestaurantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $creator = User::factory()->create(['user_type' => 'super_admin']);
    $this->restaurantA = Restaurant::create(['name' => 'A', 'created_by_user_id' => $creator->id]);
    $this->restaurantB = Restaurant::create(['name' => 'B', 'created_by_user_id' => $creator->id]);
    foreach (['admin', 'waiter', 'chef'] as $role) {
        $this->$role = app(RestaurantContext::class)->run($this->restaurantA, fn () => Employee::factory()->create([
            'role' => $role, 'station' => $role === 'chef' ? 'kitchen' : null,
        ]))->user;
    }
    $this->other = app(RestaurantContext::class)->run($this->restaurantB, fn () => Employee::factory()->create())->user;
});

test('all employee roles clock themselves in and out using server times', function (string $role) {
    $this->travelTo(now()->startOfSecond());
    $user = $this->$role;
    $this->actingAs($user, 'sanctum');
    $id = $this->postJson('/api/v1/work-shifts')->assertCreated()->assertJsonPath('data.employee_id', $user->id)->assertJsonPath('data.clock_out', null)->json('data.id');
    $this->assertDatabaseHas('work_shifts', ['id' => $id, 'clock_in' => now()->format('Y-m-d H:i:s')]);
    $this->postJson('/api/v1/work-shifts')->assertConflict();
    $this->travel(8)->hours();
    $this->postJson('/api/v1/work-shifts/'.$id.'/clock-out')->assertOk()->assertJsonPath('data.id', $id);
    $this->assertDatabaseHas('work_shifts', ['id' => $id, 'clock_out' => now()->format('Y-m-d H:i:s')]);
    $this->travel(1)->minutes();
    $this->postJson('/api/v1/work-shifts/'.$id.'/clock-out')->assertConflict();
    $this->postJson('/api/v1/work-shifts')->assertCreated();
})->with(['admin', 'waiter', 'chef']);

test('workers see only their shifts admins see their restaurant and nobody clocks out another employee', function () {
    $this->actingAs($this->waiter, 'sanctum');
    $own = $this->postJson('/api/v1/work-shifts')->assertCreated()->json('data.id');
    $this->actingAs($this->chef, 'sanctum');
    $chef = $this->postJson('/api/v1/work-shifts')->assertCreated()->json('data.id');
    $this->actingAs($this->other, 'sanctum');
    $foreign = $this->postJson('/api/v1/work-shifts')->assertCreated()->json('data.id');
    $this->actingAs($this->waiter, 'sanctum')->getJson('/api/v1/work-shifts?employee_id='.$this->chef->id.'&restaurant_id='.$this->restaurantB->id)
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $own);
    $this->getJson('/api/v1/work-shifts/'.$chef)->assertNotFound();
    $this->postJson('/api/v1/work-shifts/'.$chef.'/clock-out')->assertNotFound();
    $this->getJson('/api/v1/work-shifts/'.$foreign)->assertNotFound();
    $this->postJson('/api/v1/work-shifts/'.$foreign.'/clock-out')->assertNotFound();
    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/work-shifts')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/work-shifts/'.$chef)->assertOk();
    $this->getJson('/api/v1/work-shifts/'.$foreign)->assertNotFound();
    $this->postJson('/api/v1/work-shifts/'.$chef.'/clock-out')->assertNotFound();
});

test('shift identity and timestamps cannot be supplied by the client', function (string $field) {
    $this->actingAs($this->waiter, 'sanctum');
    $id = $this->postJson('/api/v1/work-shifts')->assertCreated()->json('data.id');
    $this->postJson('/api/v1/work-shifts', [$field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->postJson('/api/v1/work-shifts/'.$id.'/clock-out', [$field => null])->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(['id', 'employee_id', 'restaurant_id', 'clock_in', 'clock_out', 'created_at', 'updated_at']);

test('shift close rolls back if saving fails after the update', function () {
    $this->actingAs($this->waiter, 'sanctum');
    $id = $this->postJson('/api/v1/work-shifts')->assertCreated()->json('data.id');
    $dispatcher = WorkShift::getEventDispatcher();
    WorkShift::setEventDispatcher(clone $dispatcher);
    WorkShift::updated(fn () => throw new RuntimeException('Shift write failed'));
    try {
        $this->postJson('/api/v1/work-shifts/'.$id.'/clock-out')->assertServerError();
    } finally {
        WorkShift::setEventDispatcher($dispatcher);
    }
    $this->assertDatabaseHas('work_shifts', ['id' => $id, 'clock_out' => null]);
});

test('shift history survives employee deletion and cannot be rewritten', function () {
    $this->actingAs($this->waiter, 'sanctum');
    $id = $this->postJson('/api/v1/work-shifts')->assertCreated()->json('data.id');
    $shift = WorkShift::findOrFail($id);
    expect(fn () => $shift->update(['employee_id' => $this->other->id]))->toThrow(HttpException::class);
    $shift->refresh();
    expect(fn () => $shift->update(['clock_in' => now()->subDay()]))->toThrow(HttpException::class);
    $shift->refresh();
    $this->postJson('/api/v1/work-shifts/'.$id.'/clock-out')->assertOk();
    $this->waiter->delete();
    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/work-shifts/'.$id)->assertOk();
    expect(fn () => $shift->delete())->toThrow(HttpException::class);
    $this->actingAs($this->other, 'sanctum');
    expect(fn () => $shift->refresh()->update(['clock_out' => now()]))->toThrow(HttpException::class);
    auth()->forgetGuards();
    expect(fn () => WorkShift::count())->toThrow(HttpException::class);
});

test('non employees cannot access shift operations', function (string $type) {
    $this->actingAs($this->admin, 'sanctum');
    $id = $this->postJson('/api/v1/work-shifts')->assertCreated()->json('data.id');
    if ($type === 'guest') {
        auth()->forgetGuards();
    } else {
        $this->actingAs(User::factory()->create(['user_type' => $type]), 'sanctum');
    }
    $status = $type === 'guest' ? 401 : 403;
    $this->getJson('/api/v1/work-shifts')->assertStatus($status);
    $this->getJson('/api/v1/work-shifts/'.$id)->assertStatus($status);
    $this->postJson('/api/v1/work-shifts')->assertStatus($status);
    $this->postJson('/api/v1/work-shifts/'.$id.'/clock-out')->assertStatus($status);
})->with(['guest', 'customer', 'super_admin']);

test('suspended restaurants cannot clock in using existing tokens', function () {
    $token = $this->waiter->createToken('test')->plainTextToken;
    $this->restaurantA->update(['status' => 'suspended']);
    $this->withToken($token)->postJson('/api/v1/work-shifts')->assertForbidden();
});

test('shift endpoints provide no generic update or delete operations', function () {
    $this->actingAs($this->waiter, 'sanctum');
    $id = $this->postJson('/api/v1/work-shifts')->assertCreated()->json('data.id');
    $this->patchJson('/api/v1/work-shifts/'.$id, ['clock_out' => now()->toISOString()])->assertMethodNotAllowed();
    $this->deleteJson('/api/v1/work-shifts/'.$id)->assertMethodNotAllowed();
});
