<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('restaurant.{restaurantId}.kitchen', function ($user, int $restaurantId) {
    return (int) $user->restaurant_id === $restaurantId;
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

Broadcast::channel('order.{orderId}', function ($user, int $orderId) {
    // You can restrict to users of same restaurant:
    // $order = \App\Models\Order::find($orderId);
    // return $order && $order->restaurant_id === $user->restaurant_id;
    return true;
});
