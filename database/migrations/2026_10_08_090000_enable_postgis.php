<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        // The postgis/postgis image installs postgis_topology and postgis_tiger_geocoder on top of postgis.
        // Those belong to the server setup, not to this app, so the extension stays while anything depends on it.
        $dependents = DB::table('pg_extension')->whereIn('extname', ['postgis_topology', 'postgis_tiger_geocoder', 'postgis_raster'])->exists();
        if (! $dependents) {
            DB::statement('DROP EXTENSION IF EXISTS postgis');
        }
    }
};
