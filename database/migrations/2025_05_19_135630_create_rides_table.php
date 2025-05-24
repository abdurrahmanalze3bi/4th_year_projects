<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rides', function (Blueprint $table) {
            // ────────────────────────────────────────
            // Force InnoDB so spatial indexes AND foreign keys will work
            // ────────────────────────────────────────
            $table->engine = 'InnoDB';

            $table->id();

            // Foreign key to users; users must already be InnoDB
            $table->foreignId('driver_id')
                ->constrained('users')
                ->onDelete('cascade');

            // Address Information
            $table->string('pickup_address', 255);
            $table->string('destination_address', 255);

            // ────────────────────────────────────────
            // Spatial Columns: NOT NULL is required for SPATIAL indexes
            // ────────────────────────────────────────
            $table->point('pickup_location');       // NOT NULL by default
            $table->point('destination_location');  // NOT NULL by default

            // Route Data
            $table->unsignedInteger('distance')->comment('Meters');
            $table->unsignedInteger('duration')->comment('Seconds');
            $table->json('route_geometry')->nullable();

            // Ride Details
            $table->dateTime('departure_time');
            $table->unsignedSmallInteger('available_seats');
            $table->decimal('price_per_seat', 8, 2);
            $table->string('vehicle_type', 50);
            $table->text('notes')->nullable();

            // Indexes
            $table->index(['departure_time', 'available_seats']);
            $table->spatialIndex('pickup_location');
            $table->spatialIndex('destination_location');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rides');
    }
};
