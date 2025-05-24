<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('profile_photo')->nullable();
            $table->string('full_name')->nullable();
            $table->string('address')->nullable();
            $table->enum('gender', ['M', 'F'])->nullable();
            $table->integer('number_of_rides')->default(0);
            $table->text('description')->nullable();
            $table->string('car_pic')->nullable();
            $table->string('type_of_car')->nullable();
            $table->string('color_of_car')->nullable();
            $table->integer('number_of_seats')->nullable();
            $table->text('comments')->nullable();

            $table->string('face_id_pic')->nullable();
            $table->string('back_id_pic')->nullable();
            $table->string('driving_license_pic')->nullable();
            $table->string('mechanic_card_pic')->nullable();
            $table->boolean('radio')->nullable()->default(null);
            $table->boolean('smoking')->nullable()->default(null);
            $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
