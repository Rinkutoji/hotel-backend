<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\Employee\IndexRequest;
use App\Http\Requests\Employee\StoreRequest;
use App\Http\Requests\Employee\UpdateRequest;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Staff (employee) account management - admin-only. role:admin route
 * middleware blocks the group before this class runs, and
 * $this->authorize() re-checks the same rule via EmployeePolicy - the
 * two-layer convention.
 *
 * Deactivation is a status update (status:inactive); AuthController::login
 * already rejects inactive employees, so a deactivated account can no
 * longer sign in. DELETE is a soft delete (SoftDeletes on Employee).
 *
 * No Service: simple CRUD against the Employee model directly - see
 * app/Services/README.md.
 */
class EmployeeController extends Controller
{
    private const DEFAULT_PER_PAGE = 15;

    private const MAX_PER_PAGE = 50;

    public function index(IndexRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Employee::class);

        $filters = $request->validated();

        $query = Employee::query()->with('property');

        if (! empty($filters['search'])) {
            $query->where(function ($sub) use ($filters) {
                $sub->where('name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('email', 'like', '%'.$filters['search'].'%');
            });
        }

        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        $sort = $filters['sort'] ?? 'created_at';
        $direction = $filters['direction'] ?? 'desc';

        $perPage = (int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE);
        $perPage = max(1, min($perPage, self::MAX_PER_PAGE));

        return ApiResponse::paginated($query->orderBy($sort, $direction)->paginate($perPage));
    }

    public function show(Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        return ApiResponse::success($employee->load('property'));
    }

    public function store(StoreRequest $request): JsonResponse
    {
        $this->authorize('create', Employee::class);

        // Status defaults to 'active' server-side when omitted (mirrors
        // RoomService's "status is never trusted from client input for
        // the initial state" approach) and an explicit null is dropped
        // rather than wiping the DB default.
        $data = array_filter($request->validated(), fn ($value) => $value !== null);
        $data['status'] ??= 'active';

        return ApiResponse::success(Employee::create($data), 'Employee created.', 201);
    }

    public function update(UpdateRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('update', $employee);

        // Only keys actually sent are applied - password stays untouched
        // when omitted, and an optional field explicitly set to null is
        // left as-is rather than corrupting the row.
        $data = array_filter($request->validated(), fn ($value) => $value !== null);

        // Role change away from receptionist must release the property
        // assignment. The client cannot clear it in the payload
        // (prohibited_unless blocks property_id for admin/manager), so
        // the controller normalizes the row.
        if (($data['role'] ?? $employee->role) !== 'receptionist') {
            $data['property_id'] = null;
        }

        $employee->update($data);

        return ApiResponse::success($employee->fresh(), 'Employee updated.');
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $this->authorize('delete', $employee);

        $employee->delete();

        return ApiResponse::success(null, 'Employee deleted.');
    }
}
