<?php

namespace App\Http\Controllers\Api\Waiter;

use App\Http\Controllers\Controller;
use App\Http\Resources\MenuItemResource;
use App\Models\MenuItem;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MenuItemController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return MenuItemResource::collection(MenuItem::with('ingredients')->where('active', true)->orderBy('name')->orderBy('id')->paginate(20));
    }

    public function show(MenuItem $menuItem): MenuItemResource
    {
        abort_unless($menuItem->active, 404);

        return new MenuItemResource($menuItem->load('ingredients'));
    }
}
