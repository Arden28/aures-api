<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Table;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CloseOldOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'orders:cleanup-old';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Closes all pending/active orders opened on previous days and frees their tables.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting end-of-day order cleanup...');

        // 1. Define the cutoff point: Yesterday's end of day
        $yesterday = now()->subDay()->endOfDay();

        // 2. Identify active order statuses that should NOT cross a day boundary
        $activeStatuses = [
            OrderStatus::PENDING,
            OrderStatus::PREPARING,
            OrderStatus::READY,
            OrderStatus::SERVED,
        ];

        // Use a transaction to ensure database consistency
        DB::transaction(function () use ($yesterday, $activeStatuses) {

            // =========================================================
            // A. CLOSE OLD ORDERS
            // =========================================================

            // Find all active orders that were opened before the end of yesterday
            $oldActiveOrders = Order::whereIn('status', $activeStatuses)
                ->where('opened_at', '<=', $yesterday)
                ->get();

            if ($oldActiveOrders->isEmpty()) {
                $this->info('No old active orders found to close.');
                return;
            }

            $orderCount = $oldActiveOrders->count();
            $this->warn("Found {$orderCount} orders opened before {$yesterday->format('Y-m-d')}. Forcing COMPLETED status.");

            $tableIdsToFree = $oldActiveOrders->pluck('table_id')->filter()->unique();

            // Mark orders as COMPLETED and set the closed_at timestamp
            Order::whereIn('id', $oldActiveOrders->pluck('id'))
                ->update([
                    'status' => OrderStatus::COMPLETED,
                    'closed_at' => now(), // Close the order now
                ]);

            // =========================================================
            // B. FREE ASSOCIATED TABLES
            // =========================================================

            if ($tableIdsToFree->isNotEmpty()) {
                // Free only those tables that were associated with the closed orders
                Table::whereIn('id', $tableIdsToFree)
                    ->update(['status' => 'free']);

                $this->info("Successfully freed {$tableIdsToFree->count()} associated tables.");
            }
        });

        $this->info('Order cleanup finished.');

        return 0;
    }
}
