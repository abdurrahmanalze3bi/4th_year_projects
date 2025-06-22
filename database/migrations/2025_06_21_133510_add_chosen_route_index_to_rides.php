<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->unsignedSmallInteger('chosen_route_index')
                ->default(0)
                ->after('route_geometry')
                ->comment('Index of selected route from alternatives');
        });
    }

    public function down()
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('chosen_route_index');
        });
    }
    /**
     * Reverse the migrations.
     */

};
