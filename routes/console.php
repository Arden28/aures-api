<?php

use App\Console\Commands\CloseOldOrders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('orders:cleanup-old', function () {
    $this->call(CloseOldOrders::class);
})->purpose('Closes all pending/active orders opened on previous days and frees their tables.');
