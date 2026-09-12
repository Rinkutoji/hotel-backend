<?php

namespace App\Providers;

use App\Models\Employee;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Refund authorization (Architecture Baseline §10, Role
        // Permission Matrix): Admin and Manager only. A Gate, not a
        // model-bound Policy - there is no Payment model yet (STEP 11),
        // and "who is allowed to refund at all" is a role-based
        // capability check independent of any specific payment record.
        // The actual refund business logic is deferred to STEP 11; this
        // only prepares the authorization rule.
        Gate::define('process-refund', function ($user) {
            return $user instanceof Employee
                && in_array($user->role, ['admin', 'manager'], true);
        });
    }
}
