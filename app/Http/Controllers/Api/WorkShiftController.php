<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cash\WorkShiftRequest;
use App\Http\Resources\WorkShiftResource;
use App\Models\Employee;
use App\Models\WorkShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class WorkShiftController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = WorkShift::orderByDesc('clock_in')->orderByDesc('id');
        if ($request->user()->employee->role !== 'admin') {
            $query->where('employee_id', $request->user()->id);
        }

        return WorkShiftResource::collection($query->paginate(20));
    }

    public function show(Request $request, WorkShift $workShift): WorkShiftResource
    {
        abort_unless($request->user()->employee->role === 'admin' || $workShift->employee_id === $request->user()->id, 404);

        return new WorkShiftResource($workShift);
    }

    public function store(WorkShiftRequest $request): JsonResponse
    {
        return DB::transaction(function () use ($request) {
            $employee = Employee::active()->whereKey($request->user()->id)->lockForUpdate()->first();
            abort_unless($employee, 403);
            abort_if(WorkShift::where('employee_id', $employee->user_id)->whereNull('clock_out')->exists(), 409, __('Ya tienes un turno abierto.'));
            $shift = WorkShift::create(['employee_id' => $employee->user_id, 'clock_in' => now()]);

            return (new WorkShiftResource($shift))->response()->setStatusCode(201);
        });
    }

    public function clockOut(WorkShiftRequest $request, WorkShift $workShift): WorkShiftResource
    {
        abort_unless($workShift->employee_id === $request->user()->id, 404);

        return DB::transaction(function () use ($request, $workShift) {
            abort_unless(Employee::active()->whereKey($request->user()->id)->lockForUpdate()->first(), 403);
            $shift = WorkShift::whereKey($workShift->id)->lockForUpdate()->firstOrFail();
            abort_if($shift->clock_out !== null, 409, __('El turno ya está cerrado.'));
            $shift->update(['clock_out' => now()]);

            return new WorkShiftResource($shift);
        });
    }
}
