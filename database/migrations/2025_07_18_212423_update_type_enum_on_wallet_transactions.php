<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            // First, add the new column
            $table->enum('type_new', [
                'credit',
                'debit',
                'admin_credit',
                'ride_creation_fee',
                'ride_booking_payment'
            ])->after('user_id');
        });

        // Copy data from old column to new column
        DB::table('wallet_transactions')->update([
            'type_new' => DB::raw('type')
        ]);

        Schema::table('wallet_transactions', function (Blueprint $table) {
            // Drop the old column
            $table->dropColumn('type');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            // Rename the new column to the original name
            $table->renameColumn('type_new', 'type');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            // Add the old column back
            $table->enum('type_old', [
                'credit',
                'debit',
                'admin_credit',
                'ride_creation_fee'
            ])->after('user_id');
        });

        // Copy data back
        DB::table('wallet_transactions')->update([
            'type_old' => DB::raw('type')
        ]);

        Schema::table('wallet_transactions', function (Blueprint $table) {
            // Drop the current column
            $table->dropColumn('type');
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            // Rename back
            $table->renameColumn('type_old', 'type');
        });
    }
};
