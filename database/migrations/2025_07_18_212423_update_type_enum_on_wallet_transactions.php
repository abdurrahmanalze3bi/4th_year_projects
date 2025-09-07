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
            // First, add the new column with ALL the transaction types used in your code
            $table->enum('type_new', [
                // Basic transaction types
                'credit',
                'debit',
                'admin_credit',

                // Ride creation and booking
                'ride_creation_fee',
                'ride_booking_payment',
                'ride_booking_received',

                // Refund types (no bookings)
                'no_booking_refund',
                'ride_fee_refund',
                'full_creation_fee_refund',

                // Driver cancellation refunds
                'driver_cancellation_refunds',
                'driver_cancellation_refund',
                'driver_self_cancellation_refund',
                'ride_creation_fee_refund',

                // Time-based cancellation refunds
                'time_based_refund',
                'cancellation_fee_earnings',
                'cancellation_no_refund',
                'cancellation_processing',

                // Ride completion
                'ride_completion_payment',
                'ride_completion_earnings',
                'ride_payout',
                'ride_earnings',

                // Partial refunds
                'partial_seat_refund'
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
            // Add the old column back with original types
            $table->enum('type_old', [
                'credit',
                'debit',
                'admin_credit',
                'ride_creation_fee'
            ])->after('user_id');
        });

        // Copy data back (only for types that existed before)
        DB::table('wallet_transactions')
            ->whereIn('type', ['credit', 'debit', 'admin_credit', 'ride_creation_fee'])
            ->update(['type_old' => DB::raw('type')]);

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
