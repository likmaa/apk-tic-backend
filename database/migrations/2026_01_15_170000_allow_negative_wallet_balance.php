<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Allow negative balance values in wallet_transactions.
     * This is needed because for cash rides, the driver collects the fare
     * and the commission is deducted from their wallet, which may go negative
     * if they haven't topped up yet (creating a debt to the platform).
     */
    public function up(): void
    {
        // Change balance_before and balance_after to SIGNED to allow negative values
        DB::statement('ALTER TABLE `wallet_transactions` MODIFY `balance_before` BIGINT NOT NULL');
        DB::statement('ALTER TABLE `wallet_transactions` MODIFY `balance_after` BIGINT NOT NULL');

        // Also update the wallets table to allow negative balance
        DB::statement('ALTER TABLE `wallets` MODIFY `balance` BIGINT NOT NULL DEFAULT 0');
    }

    public function down(): void
    {
        // Revert to unsigned (only safe if no negative values exist)
        DB::statement('ALTER TABLE `wallet_transactions` MODIFY `balance_before` BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE `wallet_transactions` MODIFY `balance_after` BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE `wallets` MODIFY `balance` BIGINT UNSIGNED NOT NULL DEFAULT 0');
    }
};
