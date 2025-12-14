<?php

namespace Database\Seeders;

use App\Enums\FloorPlanStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\TableStatus;
use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Client;
use App\Models\FloorPlan;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Review;
use App\Models\Table;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoContentSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $pick = fn(array $arr) => $arr[array_rand($arr)];
            $num  = fn(int $min, int $max) => random_int($min, $max);
            $float = fn(float $min, float $max) => round($min + mt_rand() / mt_getrandmax() * ($max - $min), 2);

            $now = Carbon::now();

            /* ----------------------------------------------------------
             | 1. Restaurants (Europe / Euro Context)
             |-----------------------------------------------------------*/
            $restaurantsData = [
                [
                    'name'              => 'Aures Bistro',
                    'slug'              => 'aures-bistro',
                    'currency'          => 'EUR',
                    'timezone'          => 'Europe/Paris',
                    'tax_rate'          => 0.10, // VAT
                    'service_charge_rate' => 0.05,
                    'settings'          => [
                        'ticket_prefix'  => 'LPB-',
                        'enable_tips'    => true,
                        'kds_sound'      => true,
                        'order_timeout'  => 900, // seconds
                    ],
                ],
                [
                    'name'              => 'Gusto Italiano',
                    'slug'              => 'gusto-italiano',
                    'currency'          => 'EUR',
                    'timezone'          => 'Europe/Rome',
                    'tax_rate'          => 0.10,
                    'service_charge_rate' => 0.00, // Coperto usually per head, but simple % here
                    'settings'          => [
                        'ticket_prefix'  => 'GST-',
                        'enable_tips'    => true,
                        'kds_sound'      => false,
                        'order_timeout'  => 1200,
                    ],
                ],
            ];

            foreach ($restaurantsData as $rData) {
                /** @var Restaurant $restaurant */
                $restaurant = Restaurant::create($rData);

                /* ------------------------------------------------------
                 | 2. Users (Owner, Manager, Waiters, Kitchen, Cashier)
                 |-------------------------------------------------------*/
                $usersByRole = [];

                $owner = User::create([
                    'restaurant_id' => $restaurant->id,
                    'name'          => 'Owner ' . $restaurant->name,
                    'email'         => $restaurant->slug . '+owner@example.com',
                    'password'      => 'Password!234',
                    'role'          => UserRole::OWNER,
                ]);
                $usersByRole[UserRole::OWNER->value][] = $owner;

                $manager = User::create([
                    'restaurant_id' => $restaurant->id,
                    'name'          => 'Manager ' . $restaurant->name,
                    'email'         => $restaurant->slug . '+manager@example.com',
                    'password'      => 'Password!234',
                    'role'          => UserRole::MANAGER,
                ]);
                $usersByRole[UserRole::MANAGER->value][] = $manager;

                // 3 Waiters
                for ($i = 1; $i <= 3; $i++) {
                    $waiter = User::create([
                        'restaurant_id' => $restaurant->id,
                        'name'          => "Waiter {$i}",
                        'email'         => $restaurant->slug . "+waiter{$i}@example.com",
                        'password'      => 'Password!234',
                        'role'          => UserRole::WAITER,
                    ]);
                    $usersByRole[UserRole::WAITER->value][] = $waiter;
                }

                // 2 Kitchen Staff
                for ($i = 1; $i <= 2; $i++) {
                    $kitchen = User::create([
                        'restaurant_id' => $restaurant->id,
                        'name'          => "Chef {$i}",
                        'email'         => $restaurant->slug . "+kitchen{$i}@example.com",
                        'password'      => 'Password!234',
                        'role'          => UserRole::KITCHEN,
                    ]);
                    $usersByRole[UserRole::KITCHEN->value][] = $kitchen;
                }

                // 1 Cashier
                $cashier = User::create([
                    'restaurant_id' => $restaurant->id,
                    'name'          => "Cashier Main",
                    'email'         => $restaurant->slug . "+cashier@example.com",
                    'password'      => 'Password!234',
                    'role'          => UserRole::CASHIER,
                ]);
                $usersByRole[UserRole::CASHIER->value][] = $cashier;

                /* ------------------------------------------------------
                 | 3. Floor plans & Tables
                 |-------------------------------------------------------*/
                $plansSeed = [
                    ['name' => 'Dining Room',   'status' => FloorPlanStatus::ACTIVE],
                    ['name' => 'Patio',         'status' => FloorPlanStatus::ACTIVE],
                    ['name' => 'Bar Area',      'status' => FloorPlanStatus::ACTIVE],
                ];

                $tables = [];

                foreach ($plansSeed as $pIndex => $pData) {
                    $plan = FloorPlan::create([
                        'restaurant_id' => $restaurant->id,
                        'name'          => $pData['name'],
                        'status'        => $pData['status'],
                    ]);

                    // Vary table count per floor plan
                    $tableCount = match($pIndex) {
                        0 => 12, // Dining
                        1 => 6,  // Patio
                        2 => 5,  // Bar
                        default => 5
                    };

                    for ($i = 1; $i <= $tableCount; $i++) {
                        $statusPool = [
                            TableStatus::FREE, TableStatus::FREE, TableStatus::FREE,
                            TableStatus::OCCUPIED,
                            TableStatus::RESERVED,
                            TableStatus::NEEDS_CLEANING,
                        ];

                        $table = Table::create([
                            'restaurant_id' => $restaurant->id,
                            'floor_plan_id' => $plan->id,
                            'name'          => "{$plan->name} {$i}",
                            'code'          => strtoupper(Str::substr($plan->name, 0, 2)) . '-' . $restaurant->id . '-' . $i,
                            'capacity'      => $num(2, 6),
                            'qr_token'      => Str::uuid()->toString(),
                            'status'        => $pick($statusPool),
                        ]);

                        $tables[] = $table;
                    }
                }

                /* ------------------------------------------------------
                 | 4. Extensive Menu (Categories & Products) - EURO Prices
                 |-------------------------------------------------------*/
                $menuData = [
                    'Starters' => [
                        ['name' => 'Beef Carpaccio',        'price' => 14.50],
                        ['name' => 'French Onion Soup',     'price' => 9.00],
                        ['name' => 'Burrata & Tomato',      'price' => 13.00],
                        ['name' => 'Garlic Prawns',         'price' => 12.50],
                        ['name' => 'Bruschetta Trio',       'price' => 8.50],
                        ['name' => 'Calamari Fritti',       'price' => 11.00],
                    ],
                    'Salads' => [
                        ['name' => 'Caesar Salad',          'price' => 12.00],
                        ['name' => 'Niçoise Salad',         'price' => 14.00],
                        ['name' => 'Goat Cheese Salad',     'price' => 13.50],
                        ['name' => 'Greek Salad',           'price' => 11.50],
                    ],
                    'Pasta & Risotto' => [
                        ['name' => 'Truffle Tagliatelle',   'price' => 19.00],
                        ['name' => 'Spaghetti Carbonara',   'price' => 15.50],
                        ['name' => 'Seafood Risotto',       'price' => 21.00],
                        ['name' => 'Penne Arrabbiata',      'price' => 13.00],
                        ['name' => 'Lasagna Bolognese',     'price' => 16.00],
                    ],
                    'Mains' => [
                        ['name' => 'Ribeye Steak (300g)',   'price' => 29.00],
                        ['name' => 'Duck Confit',           'price' => 22.50],
                        ['name' => 'Grilled Salmon',        'price' => 24.00],
                        ['name' => 'Chicken Supreme',       'price' => 18.50],
                        ['name' => 'Lamb Chops',            'price' => 26.00],
                        ['name' => 'Beef Burger & Fries',   'price' => 17.50],
                    ],
                    'Pizza' => [
                        ['name' => 'Margherita',            'price' => 10.00],
                        ['name' => 'Pepperoni',             'price' => 12.50],
                        ['name' => 'Quattro Formaggi',      'price' => 13.50],
                        ['name' => 'Parma Ham & Rocket',    'price' => 15.00],
                        ['name' => 'Vegetariana',           'price' => 12.00],
                    ],
                    'Sides' => [
                        ['name' => 'French Fries',          'price' => 4.50],
                        ['name' => 'Truffle Mash',          'price' => 6.00],
                        ['name' => 'Roasted Vegetables',    'price' => 5.50],
                        ['name' => 'Green Salad',           'price' => 4.00],
                    ],
                    'Desserts' => [
                        ['name' => 'Tiramisu',              'price' => 8.00],
                        ['name' => 'Crème Brûlée',          'price' => 8.50],
                        ['name' => 'Chocolate Fondant',     'price' => 9.00],
                        ['name' => 'Lemon Tart',            'price' => 7.50],
                        ['name' => 'Gelato (3 Scoops)',     'price' => 6.50],
                    ],
                    'Drinks' => [
                        ['name' => 'Still Water (75cl)',    'price' => 4.50],
                        ['name' => 'Sparkling Water',       'price' => 4.50],
                        ['name' => 'Coca Cola',             'price' => 3.50],
                        ['name' => 'Lemonade',              'price' => 4.00],
                        ['name' => 'Fresh Orange Juice',    'price' => 5.00],
                    ],
                    'Alcohol' => [
                        ['name' => 'House Red (Glass)',     'price' => 6.50],
                        ['name' => 'House White (Glass)',   'price' => 6.50],
                        ['name' => 'Prosecco (Glass)',      'price' => 7.50],
                        ['name' => 'Local Draft Beer',      'price' => 5.50],
                        ['name' => 'Aperol Spritz',         'price' => 9.00],
                        ['name' => 'Gin & Tonic',           'price' => 10.00],
                    ],
                    'Coffee' => [
                        ['name' => 'Espresso',              'price' => 2.50],
                        ['name' => 'Double Espresso',       'price' => 3.50],
                        ['name' => 'Cappuccino',            'price' => 4.00],
                        ['name' => 'Latte Macchiato',       'price' => 4.20],
                        ['name' => 'Irish Coffee',          'price' => 8.50],
                    ]
                ];

                $products = [];
                $pos = 1;

                foreach ($menuData as $catName => $items) {
                    $category = Category::create([
                        'restaurant_id' => $restaurant->id,
                        'name'          => $catName,
                        'description'   => "Selection of " . strtolower($catName),
                        'position'      => $pos++,
                    ]);

                    foreach ($items as $item) {
                        $products[] = Product::create([
                            'restaurant_id' => $restaurant->id,
                            'category_id'   => $category->id,
                            'name'          => $item['name'],
                            'description'   => $item['name'] . " prepared with fresh ingredients.",
                            'price'         => $item['price'],
                            'is_available'  => true,
                        ]);
                    }
                }

                /* ------------------------------------------------------
                 | 5. Clients
                 |-------------------------------------------------------*/
                $clientsSeed = [
                    ['name' => 'Jean Dupont',     'email' => 'jean@example.com',    'phone' => '+33612345678'],
                    ['name' => 'Marie Curie',     'email' => 'marie@example.com',   'phone' => '+33687654321'],
                    ['name' => 'Lucas Martin',    'email' => 'lucas@example.com',   'phone' => '+33611223344'],
                    ['name' => 'Sarah Bernard',   'email' => 'sarah@example.com',   'phone' => '+33699887766'],
                    ['name' => 'Thomas Petit',    'email' => 'thomas@example.com',  'phone' => '+33655443322'],
                ];

                $clients = [];
                foreach ($clientsSeed as $idx => $cSeed) {
                    $clients[] = Client::create([
                        'restaurant_id' => $restaurant->id,
                        'name'          => $cSeed['name'],
                        'email'         => $cSeed['email'],
                        'phone'         => $cSeed['phone'],
                        'external_id'   => 'CL-' . ($idx + 1),
                    ]);
                }

                /* ------------------------------------------------------
                 | 6. Realistic Orders (Last 7 Days)
                 |-------------------------------------------------------*/
                $waiters  = $usersByRole[UserRole::WAITER->value] ?? [];
                $cashiers = $usersByRole[UserRole::CASHIER->value] ?? [];

                if (empty($waiters) || empty($cashiers) || empty($products) || empty($tables)) continue;

                $orderCount = 40;

                for ($i = 1; $i <= $orderCount; $i++) {
                    $table  = $pick($tables);
                    $waiter = $pick($waiters);
                    $client = $num(0, 100) < 40 ? $pick($clients) : null;

                    // Status distribution
                    $oStatus = $pick([
                        OrderStatus::COMPLETED, OrderStatus::COMPLETED, OrderStatus::COMPLETED,
                        OrderStatus::SERVED, OrderStatus::SERVED,
                        OrderStatus::PREPARING,
                        OrderStatus::READY,
                        OrderStatus::PENDING,
                        OrderStatus::CANCELLED,
                    ]);

                    // Spread orders over last 7 days + today
                    $openedAt = $now->copy()->subDays($num(0, 6))->setHour($num(11, 22))->setMinute($num(0, 59));

                    // If status is 'active', bring time closer to now
                    if (in_array($oStatus, [OrderStatus::PENDING, OrderStatus::PREPARING, OrderStatus::READY])) {
                        $openedAt = $now->copy()->subMinutes($num(5, 60));
                    }

                    $closedAt = in_array($oStatus, [OrderStatus::COMPLETED, OrderStatus::CANCELLED], true)
                        ? $openedAt->copy()->addMinutes($num(30, 90))
                        : null;

                    $order = Order::create([
                        'restaurant_id'   => $restaurant->id,
                        'table_id'        => $table->id,
                        'client_id'       => $client?->id,
                        'waiter_id'       => $waiter->id,
                        'status'          => $oStatus,
                        'source'          => 'dine_in',
                        'subtotal'        => 0,
                        'tax_amount'      => 0,
                        'service_charge'  => 0,
                        'discount_amount' => 0,
                        'total'           => 0,
                        'paid_amount'     => 0,
                        'payment_status'  => PaymentStatus::UNPAID,
                        'opened_at'       => $openedAt,
                        'closed_at'       => $closedAt,
                    ]);

                    /* --- Order Items --- */
                    $itemsCount = $num(2, 6);
                    $subtotal   = 0.0;

                    for ($j = 1; $j <= $itemsCount; $j++) {
                        /** @var Product $prod */
                        $prod = $pick($products);
                        $qty  = $num(1, 2);
                        $unit = $prod->price;
                        $line = $qty * $unit;

                        $subtotal += $line;

                        // Item status logic based on order
                        $itemStatus = OrderItemStatus::SERVED;
                        if ($oStatus === OrderStatus::PENDING) $itemStatus = OrderItemStatus::PENDING;
                        if ($oStatus === OrderStatus::PREPARING) $itemStatus = $pick([OrderItemStatus::COOKING, OrderItemStatus::PENDING]);
                        if ($oStatus === OrderStatus::READY) $itemStatus = OrderItemStatus::READY;
                        if ($oStatus === OrderStatus::CANCELLED) $itemStatus = OrderItemStatus::CANCELLED;

                        OrderItem::create([
                            'order_id'    => $order->id,
                            'product_id'  => $prod->id,
                            'quantity'    => $qty,
                            'unit_price'  => $unit,
                            'total_price' => $line,
                            'status'      => $itemStatus,
                            'notes'       => $num(0, 100) < 10 ? 'Sauce on side' : null,
                        ]);
                    }

                    $taxAmount     = round($subtotal * $restaurant->tax_rate, 2);
                    $serviceCharge = round($subtotal * $restaurant->service_charge_rate, 2);
                    $discount      = 0.0; // Keep simple
                    $total         = $subtotal + $taxAmount + $serviceCharge - $discount;

                    // Payment logic
                    $paymentStatus = PaymentStatus::UNPAID;
                    $paidAmount    = 0.0;

                    if ($oStatus === OrderStatus::COMPLETED) {
                        $paymentStatus = PaymentStatus::PAID;
                        $paidAmount    = $total;
                    } elseif ($oStatus === OrderStatus::SERVED) {
                        // Some served people have paid, some haven't
                        if ($num(0, 100) < 20) {
                            $paymentStatus = PaymentStatus::PAID;
                            $paidAmount    = $total;
                        }
                    }

                    $order->update([
                        'subtotal'        => $subtotal,
                        'tax_amount'      => $taxAmount,
                        'service_charge'  => $serviceCharge,
                        'total'           => $total,
                        'paid_amount'     => $paidAmount,
                        'payment_status'  => $paymentStatus,
                    ]);

                    // Transactions
                    if ($paymentStatus === PaymentStatus::PAID) {
                        $cashierUser = $pick($cashiers);
                        Transaction::create([
                            'order_id'     => $order->id,
                            'processed_by' => $cashierUser->id,
                            'amount'       => $total,
                            'method'       => $pick([PaymentMethod::CARD, PaymentMethod::CASH]),
                            'status'       => PaymentStatus::PAID,
                            'reference'    => strtoupper($restaurant->settings['ticket_prefix'] ?? 'TX') . '-' . $order->id,
                            'paid_at'      => $closedAt ?? $now,
                        ]);
                    }
                }
            }
        });
    }
}
