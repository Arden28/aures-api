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
     */
    protected $signature = 'orders:cleanup-old';

    /**
     * The console command description.
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

        // 2. Identify active order statuses
        $activeStatuses = [
            OrderStatus::PENDING,
            OrderStatus::PREPARING,
            OrderStatus::READY,
            OrderStatus::SERVED,
        ];

        DB::transaction(function () use ($yesterday, $activeStatuses) {

            // A. Fetch orders that need closing
            // We use lockForUpdate() to prevent conflicts if the cron overlaps or a user tries to pay at the exact same second.
            $oldActiveOrders = Order::whereIn('status', $activeStatuses)
                ->where('opened_at', '<=', $yesterday)
                ->lockForUpdate()
                ->get();

            if ($oldActiveOrders->isEmpty()) {
                $this->info('No old active orders found to close.');
                return;
            }

            $count = $oldActiveOrders->count();
            $this->warn("Found {$count} stale orders. Closing them now...");

            // B. Loop and Update (Required for statusHistory)
            foreach ($oldActiveOrders as $order) {
                // 1. Set Status
                $order->status = OrderStatus::COMPLETED;
                $order->closed_at = now();

                // 2. Record History
                // passing 'null' as user_id to indicate "System/Auto-Cleanup"
                $order->recordStatusChange(OrderStatus::COMPLETED, null);

                // 3. Save (Persists both status and history)
                $order->save();
            }

            // C. Free Associated Tables
            $tableIds = $oldActiveOrders->pluck('table_id')->filter()->unique();

            if ($tableIds->isNotEmpty()) {
                Table::whereIn('id', $tableIds)
                    ->update(['status' => 'free']);

                $this->info("Freed {$tableIds->count()} associated tables.");
            }
        });

        $this->info('Order cleanup finished successfully.');

        return 0;
    }
}
