<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * STEP 06 adds AuthorizesRequests now that a real Policy (RoomTypePolicy)
 * needs the $this->authorize() shortcut - genuine Laravel 11's base
 * controller ships empty, per STEP 02's note that this trait would be
 * added "if/when a controller needs it".
 */
abstract class Controller
{
    use AuthorizesRequests;
}
