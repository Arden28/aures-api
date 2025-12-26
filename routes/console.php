<?php

use App\Console\Commands\CloseOldOrders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('orders:cleanup-old', function () {
    $this->call(CloseOldOrders::class);
})->purpose('Closes all pending/active orders opened on previous days and frees their tables.');

// Run every day at 4:00 AM
Schedule::command('orders:cleanup-old')->dailyAt('04:00');
