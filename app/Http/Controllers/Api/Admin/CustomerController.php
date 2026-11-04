<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CustomerResource::collection($this->customers()->orderBy('user_id')->paginate(20));
    }

    public function show(string $customer): CustomerResource
    {
        return new CustomerResource($this->customers()->whereKey($customer)->firstOrFail());
    }

    private function customers(): Builder
    {
        return Customer::with(['user', 'loyaltyBalances'])->whereHas('loyaltyBalances')
            ->whereHas('user', fn ($query) => $query->where('user_type', 'customer'));
    }
}
