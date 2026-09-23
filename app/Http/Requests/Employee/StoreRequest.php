<?php

namespace App\Http\Requests\Employee;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class StoreRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Matches the DB-level unique index (includes soft-deleted
                // rows): accounts are deactivated via status, not recreated
                // under a deleted email.
                Rule::unique('employees', 'email'),
            ],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', 'string', 'in:admin,manager,receptionist'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            // Receptionists MUST be pinned to an existing, non-soft-deleted
            // property; admins/managers MUST NOT receive one from the client.
            // required_if is implicit (runs even when the key is absent);
            // prohibited_unless blocks client-assigned properties otherwise.
            'property_id' => [
                'nullable', 'integer',
                Rule::exists('properties', 'id')->whereNull('deleted_at'),
                'required_if:role,receptionist',
                'prohibited_unless:role,receptionist',
            ],
        ];
    }
}
