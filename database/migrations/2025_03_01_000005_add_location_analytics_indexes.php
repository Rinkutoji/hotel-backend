<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Composite indexes backing the property/building-filtered occupancy
     * snapshots and availability checks (Phase 5). Those queries join
     * rooms.building_id + rooms.status and buildings.property_id +
     * buildings.status; the composites keep them index-only, following the
     * pattern of 2025_02_20_000002_add_analytics_indexes.php.
     *
     * up() creates ONLY missing required indexes: each is guarded by an
     * equivalent-index check (same ordered column set) so re-running the
     * migration never duplicates an index that already exists.
     *
     * down() drops ONLY the indexes this migration creates, by their
     * conventional names (rooms_building_id_status_index,
     * buildings_property_id_status_index) and only when present. An
     * equivalent index under any other name was NOT created here and is
     * left alone.
     */
    public function up(): void
    {
        if (! $this->indexExists('rooms', ['building_id', 'status'])) {
            Schema::table('rooms', function (Blueprint $table) {
                $table->index(['building_id', 'status']);
            });
        }

        if (! $this->indexExists('buildings', ['property_id', 'status'])) {
            Schema::table('buildings', function (Blueprint $table) {
                $table->index(['property_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        foreach ([
            'rooms' => 'rooms_building_id_status_index',
            'buildings' => 'buildings_property_id_status_index',
        ] as $table => $name) {
            if (collect(Schema::getIndexes($table))->contains(fn (array $index) => ($index['name'] ?? null) === $name)) {
                Schema::table($table, function (Blueprint $table) use ($name) {
                    $table->dropIndex($name);
                });
            }
        }
    }

    /**
     * Whether $table already has an index over exactly the given ordered
     * column set. Column names are normalized (trim/lowercase) because
     * SQLite may return them with case/whitespace differences from MySQL.
     */
    private function indexExists(string $table, array $columns): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $index) => array_map(
                fn ($column) => trim(strtolower((string) $column)),
                (array) ($index['columns'] ?? [])
            ) === $columns
        );
    }
};
