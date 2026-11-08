<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function show(Request $request): User
    {
        $user = $request->user();

        return $user->user_type === 'customer' ? $user->loadMissing('customer') : $user;
    }

    public function update(UpdateProfileRequest $request): User
    {
        try {
            return DB::transaction(function () use ($request) {
                $id = $request->user()->id;
                // Match the employee administration lock order when both rows can change.
                $employee = $request->user()->user_type === 'employee'
                    ? Employee::whereKey($id)->lockForUpdate()->firstOrFail() : null;
                $user = User::whereKey($id)->lockForUpdate()->firstOrFail();
                if ($employee !== null) {
                    $user->setRelation('employee', $employee);
                }
                $user->ensureCanAccess();
                $user->update($request->safe()->only(['name', 'username', 'locale']));

                $phone = $request->safe()->only(['phone']);
                if ($phone !== []) {
                    if ($employee !== null) {
                        $employee->update($phone);
                    } else {
                        $user->customer()->updateOrCreate([], $phone);
                    }
                }

                return $user->user_type === 'customer' ? $user->load('customer') : $user;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! $request->has('username')) {
                throw $exception;
            }

            throw ValidationException::withMessages(['username' => trans('validation.unique', ['attribute' => 'username'])]);
        }
    }
}
