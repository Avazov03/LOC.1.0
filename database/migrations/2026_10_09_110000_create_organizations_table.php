<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $pgsql = DB::getDriverName() === 'pgsql';

        Schema::create('organizations', function (Blueprint $table) use ($pgsql) {
            $table->id();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('type', 120);
            $table->string('address');
            if (! $pgsql) {
                // sqlite test schema keeps WKT text; PostgreSQL uses geography below.
                $table->string('location');
            }
            $table->unsignedSmallInteger('radius_meters')->default(100);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->string('contact_name');
            $table->string('contact_phone', 32);
            $table->string('contact_position')->nullable();
            $table->string('website')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['university_id', 'status']);
        });

        if ($pgsql) {
            DB::statement('ALTER TABLE organizations ADD COLUMN location geography(Point, 4326) NOT NULL');
            DB::statement('CREATE INDEX organizations_location_gist ON organizations USING gist (location)');
            DB::statement('ALTER TABLE organizations ADD CONSTRAINT organizations_radius_range CHECK (radius_meters BETWEEN 100 AND 500)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
