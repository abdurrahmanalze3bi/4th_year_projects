<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('message');
            $table->string('type')->default('general'); // general, message, system, welcome, etc.
            $table->json('data')->nullable(); // additional data as JSON
            $table->unsignedBigInteger('user_id')->nullable(); // if for specific user
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['type', 'sent_at']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
