<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->foreignId('building_id')
                ->nullable()
                ->after('room_type_id')
                ->constrained('buildings')
                ->restrictOnDelete();
            $table->index('building_id');
        });

        // Data step (idempotent): default property/building, then backfill
        // EVERY existing room (including soft-deleted rows - the query
        // touches the table directly, not the Eloquent scope).
        $propertyId = DB::table('properties')->where('code', 'MAIN')->value('id');
        if ($propertyId === null) {
            $propertyId = DB::table('properties')->insertGetId([
                'name' => 'Main Hotel',
                'code' => 'MAIN',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $buildingId = DB::table('buildings')
            ->where('property_id', $propertyId)
            ->where('code', 'MAIN')
            ->value('id');
        if ($buildingId === null) {
            $buildingId = DB::table('buildings')->insertGetId([
                'property_id' => $propertyId,
                'name' => 'Main Building',
                'code' => 'MAIN',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('rooms')->whereNull('building_id')->update(['building_id' => $buildingId]);

        // Constraints now that every row has a home.
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedBigInteger('building_id')->nullable(false)->change();
        });

        // Legacy guard: drop a global room_number unique ONLY if it still
        // exists. (In this codebase it was already dropped by
        // 2025_02_04_000001_fix_rooms_table_constraints, so this is normally
        // a no-op - but the check keeps the migration safe when run against
        // schemas that still carry the index.)
        $legacyUnique = collect(Schema::getIndexes('rooms'))->contains(
            fn (array $index) => ($index['unique'] ?? false)
                && ! ($index['primary'] ?? false)
                && array_map(fn ($column) => trim(strtolower((string) $column)), (array) ($index['columns'] ?? [])) === ['room_number']
        );

        if ($legacyUnique) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->dropUnique(['room_number']);
            });
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->unique(['building_id', 'room_number']);
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropUnique(['building_id', 'room_number']);
        });

        // SQLite cannot drop a column that is still referenced by a foreign
        // key or an index (native ALTER TABLE ... DROP COLUMN rejects it),
        // and its ALTER TABLE has no DROP CONSTRAINT. So on SQLite the
        // building_id foreign key and index are removed through Laravel's
        // temp-table rebuild (triggered by the no-op change() below) before
        // the column itself is dropped. On MySQL dropConstrainedForeignId()
        // handles the foreign key and the column natively.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('rooms', function (Blueprint $table) {
                $table->dropIndex(['building_id']);
                $table->dropForeign(['building_id']);
                $table->unsignedSmallInteger('floor')->change();
            });
        }

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('building_id');
        });

        // Deliberately no restore of a global unique(room_number): after this
        // feature, different buildings may hold duplicate room numbers, and a
        // blind restore would fail (or wrongly reject existing data) on
        // rollback. Fail-safe rollback per spec §5.
    }
};
