<?php

use App\Models\Order;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Allow Kitchen Staff to listen to their restaurant
Broadcast::channel('restaurant.{id}.kitchen', function ($user, $id) {
    // 🔍 DEBUG: Check storage/logs/laravel.log after running this
    Log::info("📡 WebSocket Auth Attempt:", [
        'user_id' => $user->id,
        'user_email' => $user->email,
        'user_restaurant_id' => $user->restaurant_id,
        'target_restaurant_id' => $id,
        'role' => $user->role ?? 'no-role',
    ]);

    // 1. Check Restaurant ID match
    $matchesRestaurant = (int) $user->restaurant_id === (int) $id;

    // 2. Check Role (Adjust these roles to match your database exactly)
    // $hasRole = in_array($user->role, ['owner', 'manager', 'kitchen', 'waiter', 'cashier']);

    return $matchesRestaurant;
});

// Allow listening to specific orders
Broadcast::channel('order.{orderId}', function ($user, $orderId) {
    $order = Order::find($orderId);
    return $order && (int) $user->restaurant_id === (int) $order->restaurant_id;
});

Broadcast::channel('restaurant.{restaurantId}.waiters', function ($user, int $restaurantId) {
    return (int) $user->restaurant_id === $restaurantId;
});

Broadcast::channel('restaurant.{restaurantId}.cashier', function ($user, int $restaurantId) {
    return (int) $user->restaurant_id === $restaurantId;
});

Broadcast::channel('table.{tableId}', function ($user, int $tableId) {
    // For now, just check same restaurant; you can refine using Table model if needed.
    // Example with model:
    // $table = \App\Models\Table::find($tableId);
    // return $table && $table->restaurant_id === $user->restaurant_id;
    return true; // or proper logic depending on your auth story for clients
});

// Broadcast::channel('order.{orderId}', function ($user, int $orderId) {
//     // You can restrict to users of same restaurant:
//     // $order = \App\Models\Order::find($orderId);
//     // return $order && $order->restaurant_id === $user->restaurant_id;
//     return true;
// });
