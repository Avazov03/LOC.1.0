<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set when the phone came from Telegram's "share my number" button; cleared when staff edit the phone.
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->timestampTz('phone_verified_at')->nullable();
        });

        // University-wide days off: an expected work day that falls on one is not counted ABSENT.
        Schema::create('university_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('university_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('name', 120);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->unique(['university_id', 'date']);
        });

        // TOTP second factor for staff. The secret and the recovery code hashes are encrypted at rest.
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestampTz('two_factor_confirmed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
        Schema::dropIfExists('university_holidays');
        Schema::table('student_profiles', function (Blueprint $table) {
            $table->dropColumn('phone_verified_at');
        });
    }
};
