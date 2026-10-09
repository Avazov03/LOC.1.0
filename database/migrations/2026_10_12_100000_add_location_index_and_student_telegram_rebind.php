<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lookup of identical coordinates sent earlier (reused or shared location points).
        Schema::table('attendance_events', function (Blueprint $table) {
            $table->index(['latitude', 'longitude'], 'attendance_events_coordinates');
        });

        // One-time "/start r_<token>" link that moves a student to a new Telegram account.
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->char('telegram_rebind_hash', 64)->nullable()->unique();
            $table->timestampTz('telegram_rebind_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropUnique(['telegram_rebind_hash']);
            $table->dropColumn(['telegram_rebind_hash', 'telegram_rebind_expires_at']);
        });
        Schema::table('attendance_events', function (Blueprint $table) {
            $table->dropIndex('attendance_events_coordinates');
        });
    }
};
