<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base class for every request-validation class in the project.
 *
 * Convention (Architecture Baseline §8): FormRequests validate SHAPE only.
 * The "is this user allowed to do this" question is answered downstream by
 * Policies, not here - so authorize() defaults to true across the board.
 *
 * ValidationException thrown by failedValidation() is caught globally in
 * bootstrap/app.php and rendered through the standard error envelope - no
 * per-request override needed for that.
 */
abstract class ApiFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
