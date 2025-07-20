<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // database/migrations/xxxx_add_finished_at_to_rides_table.php
    public function up()
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->timestamp('finished_at')->nullable()->after('departure_time');
        });
    }

    public function down()
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('finished_at');
        });
    }
};
