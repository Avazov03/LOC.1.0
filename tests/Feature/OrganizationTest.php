<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\University;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsInternships;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use BuildsInternships;
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Toshkent shahar sudi',
            'type' => 'Sud',
            'address' => 'Amir Temur 1',
            'contact_name' => 'Karimov',
            'contact_phone' => '+998712000000',
            'contact_position' => 'Kotib',
            'website' => 'https://sud.uz',
            'latitude' => 41.3111,
            'longitude' => 69.2797,
            'radius_meters' => 150,
            ...$overrides,
        ];
    }

    public function test_admin_creates_an_organization_with_a_point_and_audit(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post('/organizations', $this->payload())->assertRedirect('/organizations');

        $organization = Organization::query()->withCoordinates()->sole();
        $this->assertSame($admin->university_id, $organization->university_id);
        $this->assertSame('ACTIVE', $organization->status->value);
        $this->assertSame(['latitude' => 41.3111, 'longitude' => 69.2797], $organization->coordinates());
        $this->assertSame(150, $organization->radius_meters);
        $audit = AuditLog::query()->where('action', 'organization.create')->sole();
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame($admin->university_id, $audit->university_id);
    }

    public function test_radius_and_coordinates_are_validated(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post('/organizations', $this->payload(['radius_meters' => 99]))->assertSessionHasErrors('radius_meters');
        $this->actingAs($admin)->post('/organizations', $this->payload(['radius_meters' => 501]))->assertSessionHasErrors('radius_meters');
        $this->actingAs($admin)->post('/organizations', $this->payload(['latitude' => 91]))->assertSessionHasErrors('latitude');
        $this->actingAs($admin)->post('/organizations', $this->payload(['latitude' => null]))->assertSessionHasErrors('latitude');
        $this->actingAs($admin)->post('/organizations', $this->payload(['website' => 'javascript:alert(1)']))->assertSessionHasErrors('website');

        $this->assertSame(0, Organization::query()->count());
    }

    public function test_update_writes_separate_audits_for_fields_location_and_radius(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->post('/organizations', $this->payload());
        $organization = Organization::query()->sole();

        $this->actingAs($admin)->put("/organizations/{$organization->id}", $this->payload([
            'name' => 'Yangi nom',
            'latitude' => 41.32,
            'radius_meters' => 300,
            'status' => 'INACTIVE',
        ]))->assertSessionHas('success');

        $actions = AuditLog::query()->where('entity_id', $organization->id)->where('entity_type', 'organization')->pluck('action')->all();
        $this->assertEqualsCanonicalizing(['organization.create', 'organization.update', 'organization.location_update', 'organization.radius_update', 'organization.status_change'], $actions);
        $location = AuditLog::query()->where('action', 'organization.location_update')->sole();
        $this->assertSame(41.3111, $location->before['latitude']);
        $this->assertSame(41.32, $location->after['latitude']);
        $this->assertSame('INACTIVE', Organization::query()->find($organization->id)->status->value);
    }

    public function test_unchanged_update_writes_no_audit(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->post('/organizations', $this->payload());
        $organization = Organization::query()->sole();

        $this->actingAs($admin)->put("/organizations/{$organization->id}", $this->payload());

        $this->assertSame(1, AuditLog::query()->where('entity_type', 'organization')->count());
    }

    public function test_organization_is_stored_as_postgis_geography_with_a_gist_index(): void
    {
        if (! $this->isPgsql()) {
            $this->markTestSkipped('PostGIS only. Run composer test:pgsql.');
        }
        $university = University::factory()->create();
        $organization = $this->organization($university);

        $type = DB::selectOne("SELECT format_type(atttypid, atttypmod) AS t FROM pg_attribute WHERE attrelid = 'organizations'::regclass AND attname = 'location'");
        $this->assertSame('geography(Point,4326)', $type->t);
        $this->assertNotNull(DB::selectOne("SELECT 1 FROM pg_indexes WHERE indexname = 'organizations_location_gist' AND indexdef ILIKE '%USING gist%'"));

        // Radius boundary on the stored point: ~99 m and 100 m inside, ~101 m outside a 100 m radius.
        $row = fn (float $meters) => DB::selectOne(
            'SELECT ST_DWithin(location, ST_Project(location, ?::float8, radians(90)), radius_meters) AS inside FROM organizations WHERE id = ?',
            [$meters, $organization->id],
        )->inside;
        $this->assertTrue($row(99));
        // Projection is geodesic; the exact edge lands within float error of 100 m.
        $this->assertTrue($row(99.999));
        $this->assertFalse($row(101));
        $distance = (float) DB::selectOne(
            'SELECT ST_Distance(location, ST_Project(location, 100::float8, radians(90))) AS d FROM organizations WHERE id = ?',
            [$organization->id],
        )->d;
        $this->assertEqualsWithDelta(100.0, $distance, 0.001);
    }

    public function test_database_check_rejects_out_of_range_radius(): void
    {
        if (! $this->isPgsql()) {
            $this->markTestSkipped('PostgreSQL CHECK. Run composer test:pgsql.');
        }
        $university = University::factory()->create();
        $organization = $this->organization($university);

        $this->expectException(QueryException::class);
        DB::table('organizations')->where('id', $organization->id)->update(['radius_meters' => 50]);
    }
}
