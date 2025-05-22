<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('password')->nullable(); // Password is nullable for social login
            $table->enum('gender', ['M', 'F'])->nullable();
            $table->enum('address', [
                'دمشق', 'درعا', 'القنيطرة', 'السويداء', 'ريف دمشق',
                'حمص', 'حماة', 'اللاذقية', 'طرطوس', 'حلب',
                'ادلب', 'الحسكة', 'الرقة', 'دير الزور'
            ])->nullable();

            $table->string('google_id')->nullable()->unique(); // For Google login
            $table->string('avatar')->nullable(); // Avatar from Google or uploaded
            $table->tinyInteger('status')->default(1); // Active/inactive

            // Verification fields
            $table->boolean('is_verified_passenger')->default(0);
            $table->boolean('is_verified_driver')->default(0);
            $table->enum('verification_status', ['none', 'pending', 'rejected', 'approved'])->default('none');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
