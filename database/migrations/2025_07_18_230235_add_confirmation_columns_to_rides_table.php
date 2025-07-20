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
            $table->timestamp('driver_confirmed_at')->nullable()->after('finished_at');
            $table->boolean('passengers_confirmed')->default(false)->after('driver_confirmed_at');
        });
    }
};
