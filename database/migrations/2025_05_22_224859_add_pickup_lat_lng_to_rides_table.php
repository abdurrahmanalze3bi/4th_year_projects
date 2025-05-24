<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->decimal('pickup_lat', 10, 8)->after('pickup_address')->nullable();
            $table->decimal('pickup_lng', 11, 8)->after('pickup_lat')->nullable();
            $table->decimal('destination_lat', 10, 8)->after('destination_address')->nullable();
            $table->decimal('destination_lng', 11, 8)->after('destination_lat')->nullable();
        });

        // Correctly populate lat/lng using spatial functions
        DB::statement(<<<SQL
            UPDATE rides
            SET
                pickup_lat = ST_Y(pickup_location),
                pickup_lng = ST_X(pickup_location),
                destination_lat = ST_Y(destination_location),
                destination_lng = ST_X(destination_location)
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn(['pickup_lat', 'pickup_lng', 'destination_lat', 'destination_lng']);
        });
    }
};
