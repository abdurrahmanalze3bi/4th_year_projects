<?php
// database/migrations/2025_07_12_152308_create_wallets_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->engine = 'InnoDB'; // Ensure InnoDB engine
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('wallet_number', 16)->unique();
            $table->decimal('balance', 10, 2)->default(0);
            $table->string('phone_number')->unique();
            $table->timestamps();

            // Add foreign key constraint to users table
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        // Now add the wallet_id column to users table and create the reverse foreign key
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('wallet_id')->nullable()->after('id');
            $table->foreign('wallet_id')
                ->references('id')
                ->on('wallets')
                ->onDelete('set null');
        });
    }

    public function down()
    {
        // Drop the foreign key and column from users table first
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['wallet_id']);
            $table->dropColumn('wallet_id');
        });

        // Then drop the wallets table
        Schema::dropIfExists('wallets');
    }
};
