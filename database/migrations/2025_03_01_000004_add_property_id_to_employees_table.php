<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('property_id')
                ->nullable()
                ->after('department_id')
                ->constrained('properties')
                ->restrictOnDelete();
            $table->index('property_id');
        });

        // Backfill ONLY receptionists to the MAIN property ("a receptionist
        // is never unrestricted"). Admin/manager stay NULL (= all
        // properties). The MAIN property is guaranteed to exist whenever
        // rooms existed (Task 3's data step); the null guard covers fresh
        // databases. This migration only READS properties - it never
        // creates one.
        $mainId = DB::table('properties')->where('code', 'MAIN')->value('id');
        if ($mainId !== null) {
            DB::table('employees')
                ->where('role', 'receptionist')
                ->whereNull('property_id')
                ->update(['property_id' => $mainId]);
        }
    }

    public function down(): void
    {
        // SQLite cannot drop a column that is still referenced by a foreign
        // key or an index (native ALTER TABLE ... DROP COLUMN rejects it),
        // and its ALTER TABLE has no DROP CONSTRAINT. So on SQLite the
        // property_id foreign key and index are removed through Laravel's
        // temp-table rebuild (triggered by the no-op change() below) before
        // the column itself is dropped. On MySQL, dropConstrainedForeignId()
        // below drops the FK first and then the column (dropping the column
        // removes its index with it); dropping the index first would fail
        // there with InnoDB error 1553 while the FK still uses it.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropIndex(['property_id']);
                $table->dropForeign(['property_id']);
                $table->string('name')->change();
            });
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('property_id');
        });

        // Do NOT restore or create anything else. Employee records are
        // untouched - no rows are deleted or modified on rollback.
    }
};
