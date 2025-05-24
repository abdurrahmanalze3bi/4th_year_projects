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
        // When this migration runs, it will create `users` as InnoDB.
        Schema::create('users', function (Blueprint $table) {
            // ────────────────────────────────────────
            // 1) Force the storage engine to InnoDB
            // ────────────────────────────────────────
            $table->engine = 'InnoDB';

            // ────────────────────────────────────────
            // 2) Primary key + required Laravel fields
            // ────────────────────────────────────────
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->nullable();     // nullable for social login
            $table->rememberToken();

            // ────────────────────────────────────────
            // 3) Your custom columns
            // ────────────────────────────────────────
            $table->enum('gender', ['M', 'F'])->nullable();
            $table->enum('address', [
                'دمشق','درعا','القنيطرة','السويداء','ريف دمشق',
                'حمص','حماة','اللاذقية','طرطوس','حلب',
                'ادلب','الحسكة','الرقة','دير الزور'
            ])->nullable();

            $table->string('google_id')->nullable()->unique();
            $table->string('avatar')->nullable();
            $table->tinyInteger('status')->default(1);

            $table->boolean('is_verified_passenger')->default(0);
            $table->boolean('is_verified_driver')->default(0);
            $table->enum('verification_status', ['none','pending','rejected','approved'])
                ->default('none');

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
