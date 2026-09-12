<?php

namespace App\Policies;

use App\Models\Customer;

/**
 * Auto-discovered by Laravel's standard App\Policies\{Model}Policy
 * convention - no manual registration needed.
 *
 * A customer may only view/update their own profile record. Not yet
 * wired into a route via $this->authorize() - there's no standalone
 * profile-management endpoint yet ("/customer/me" is inherently
 * self-referential and needs no ownership check). Written now because
 * the ownership rule itself is simple, stable, and independent of
 * anything not yet built - it applies unchanged once STEP 09 (Customer
 * Management) adds the routes that actually need it.
 */
class CustomerPolicy
{
    public function view(Customer $viewer, Customer $target): bool
    {
        return $viewer->id === $target->id;
    }

    public function update(Customer $viewer, Customer $target): bool
    {
        return $viewer->id === $target->id;
    }
}
