<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| STEP 06 schema fix - see docs/STEP-06-ROOM-TYPE-PLAN.md §0
|--------------------------------------------------------------------------
|
| The original STEP 03 migration gave room_types.name a plain database
| unique constraint, which does not account for soft-deletes (a
| soft-deleted row stays physically present, so its name still blocks
| reuse at the database level even though STEP 06 requires uniqueness
| only among ACTIVE room types). A composite UNIQUE(name, deleted_at)
| does not fix this either - MySQL treats NULL as distinct from NULL in
| unique indexes, so it would stop enforcing uniqueness among active
| rows entirely (all have deleted_at = NULL), which is the case that
| matters most.
|
| Fix: drop the unconditional database constraint. Uniqueness-among-
| active is enforced at the validation layer instead
| (Rule::unique('room_types','name')->whereNull('deleted_at') in
| app/Http/Requests/RoomType/{Store,Update}Request.php). This trades an
| unconditional DB guarantee for a small, well-understood race-condition
| window on an infrequent, Admin/Manager-only write action - see the
| plan doc for the full reasoning.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });
    }

    public function down(): void
    {
        Schema::table('room_types', function (Blueprint $table) {
            $table->unique('name');
        });
    }
};
