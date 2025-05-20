<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
// database/migrations/xxxx_fix_ride_spatial_columns.php

    public function up()
    {
        Schema::table('rides', function (Blueprint $table) {
            // Drop existing spatial columns
            $table->dropColumn(['pickup_location', 'destination_location']);
        });

        Schema::table('rides', function (Blueprint $table) {
            // Recreate with proper spatial configuration
            $table->geometry('pickup_location')->after('destination_address');
            $table->geometry('destination_location')->after('pickup_location');
        });
    }
    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
