<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| STEP 07 schema fixes - see docs/STEP-07-ROOM-PLAN.md §0
|--------------------------------------------------------------------------
|
| A. room_number: same issue as room_types.name in STEP 06 - a plain
|    database unique constraint doesn't account for soft-deletes.
|    Dropped here; uniqueness-among-active is enforced at the validation
|    layer instead (app/Http/Requests/Room/{Store,Update}Request.php).
|
| B. floor: STEP 07 requires required+integer; STEP 03 left it
|    nullable+string as a flagged default assumption. Using drop-then-add
|    rather than ->change() deliberately, to avoid a doctrine/dbal
|    dependency this project doesn't otherwise need.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropUnique(['room_number']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('floor');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedSmallInteger('floor')->after('room_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('floor');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->string('floor')->nullable()->after('room_type_id');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->unique('room_number');
        });
    }
};
