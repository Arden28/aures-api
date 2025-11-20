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

            $now = Carbon::now();

            /* ----------------------------------------------------------
             | 1. Restaurants
             |-----------------------------------------------------------*/
            $restaurantsData = [
                [
                    'name'                => 'Aures Bistro',
                    'slug'                => 'aures-bistro',
                    'currency'            => 'KES',
                    'timezone'            => 'Africa/Nairobi',
                    'tax_rate'            => 0.16,
                    'service_charge_rate' => 0.10,
                    'settings'            => [
                        'ticket_prefix'  => 'AB-',
                        'enable_tips'    => true,
                        'kds_sound'      => true,
                        'order_timeout'  => 900, // seconds
                    ],
                ],
                [
                    'name'                => 'Skyline Rooftop',
                    'slug'                => 'skyline-rooftop',
                    'currency'            => 'KES',
                    'timezone'            => 'Africa/Nairobi',
                    'tax_rate'            => 0.08,
                    'service_charge_rate' => 0.05,
                    'settings'            => [
                        'ticket_prefix'  => 'SR-',
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
                 | 2. Users (owner, manager, waiters, kitchen, cashier)
                 |-------------------------------------------------------*/
                $usersByRole = [];

                $owner = User::create([
                    'restaurant_id' => $restaurant->id,
                    'name'          => $restaurant->name . ' Owner',
                    'email'         => $restaurant->slug . '+owner@example.com',
                    'password'      => 'Password!234', // will be hashed by cast
                    'role'          => UserRole::OWNER,
                ]);
                $usersByRole[UserRole::OWNER->value][] = $owner;

                $manager = User::create([
                    'restaurant_id' => $restaurant->id,
                    'name'          => $restaurant->name . ' Manager',
                    'email'         => $restaurant->slug . '+manager@example.com',
                    'password'      => 'Password!234',
                    'role'          => UserRole::MANAGER,
                ]);
                $usersByRole[UserRole::MANAGER->value][] = $manager;

                // 2 waiters
                for ($i = 1; $i <= 2; $i++) {
                    $waiter = User::create([
                        'restaurant_id' => $restaurant->id,
                        'name'          => "Waiter {$i} " . Str::title(Str::before($restaurant->slug, '-')),
                        'email'         => $restaurant->slug . "+waiter{$i}@example.com",
                        'password'      => 'Password!234',
                        'role'          => UserRole::WAITER,
                    ]);
                    $usersByRole[UserRole::WAITER->value][] = $waiter;
                }

                // 2 kitchen
                for ($i = 1; $i <= 2; $i++) {
                    $kitchen = User::create([
                        'restaurant_id' => $restaurant->id,
                        'name'          => "Kitchen {$i} " . Str::title(Str::before($restaurant->slug, '-')),
                        'email'         => $restaurant->slug . "+kitchen{$i}@example.com",
                        'password'      => 'Password!234',
                        'role'          => UserRole::KITCHEN,
                    ]);
                    $usersByRole[UserRole::KITCHEN->value][] = $kitchen;
                }

                // 1 cashier
                $cashier = User::create([
                    'restaurant_id' => $restaurant->id,
                    'name'          => "Cashier " . Str::title(Str::before($restaurant->slug, '-')),
                    'email'         => $restaurant->slug . "+cashier@example.com",
                    'password'      => 'Password!234',
                    'role'          => UserRole::CASHIER,
                ]);
                $usersByRole[UserRole::CASHIER->value][] = $cashier;

                /* ------------------------------------------------------
                 | 3. Floor plans & tables
                 |-------------------------------------------------------*/
                $plansSeed = [
                    ['name' => 'Main Hall',   'status' => FloorPlanStatus::ACTIVE],
                    ['name' => 'Terrace',     'status' => FloorPlanStatus::ACTIVE],
                    ['name' => 'VIP Lounge',  'status' => FloorPlanStatus::INACTIVE],
                ];

                $tables = [];

                foreach ($plansSeed as $pIndex => $pData) {
                    /** @var FloorPlan $plan */
                    $plan = FloorPlan::create([
                        'restaurant_id' => $restaurant->id,
                        'name'          => $pData['name'],
                        'status'        => $pData['status'],
                    ]);

                    $tableCount = $pIndex === 0 ? 8 : 4; // more tables in main hall

                    for ($i = 1; $i <= $tableCount; $i++) {
                        $statusPool = [
                            TableStatus::FREE,
                            TableStatus::FREE,
                            TableStatus::OCCUPIED,
                            TableStatus::RESERVED,
                            TableStatus::NEEDS_CLEANING,
                        ];

                        /** @var TableStatus $tStatus */
                        $tStatus = $pick($statusPool);

                        $table = Table::create([
                            'restaurant_id' => $restaurant->id,
                            'floor_plan_id' => $plan->id,
                            'name'          => "{$plan->name} T{$i}",
                            'code'          => strtoupper(Str::substr($plan->name, 0, 2)) . '-' . $restaurant->id . '-' . $i,
                            'capacity'      => $num(2, 6),
                            'qr_token'      => Str::uuid()->toString(),
                            'status'        => $tStatus,
                        ]);

                        $tables[] = $table;
                    }
                }

                /* ------------------------------------------------------
                 | 4. Categories & products
                 |-------------------------------------------------------*/
                $categoriesSeed = [
                    'Breakfast',
                    'Starters',
                    'Mains',
                    'Desserts',
                    'Drinks',
                ];

                $productsByCategoryName = [
                    'Breakfast' => [
                        ['name' => 'English Breakfast',  'price' => 950],
                        ['name' => 'Pancakes & Syrup',   'price' => 600],
                        ['name' => 'Avocado Toast',      'price' => 750],
                    ],
                    'Starters' => [
                        ['name' => 'Tomato Soup',        'price' => 450],
                        ['name' => 'Chicken Wings',      'price' => 800],
                        ['name' => 'Bruschetta',         'price' => 550],
                    ],
                    'Mains' => [
                        ['name' => 'Grilled Beef Steak', 'price' => 1600],
                        ['name' => 'Chicken Burger',     'price' => 900],
                        ['name' => 'Margherita Pizza',   'price' => 1100],
                        ['name' => 'Seafood Pasta',      'price' => 1400],
                    ],
                    'Desserts' => [
                        ['name' => 'Chocolate Lava Cake','price' => 650],
                        ['name' => 'Cheesecake',         'price' => 600],
                        ['name' => 'Fruit Salad',        'price' => 450],
                    ],
                    'Drinks' => [
                        ['name' => 'Espresso',           'price' => 250],
                        ['name' => 'Cappuccino',         'price' => 350],
                        ['name' => 'Fresh Juice',        'price' => 400],
                        ['name' => 'Soda',               'price' => 200],
                        ['name' => 'House Cocktail',     'price' => 800],
                    ],
                ];

                $products = [];

                foreach ($categoriesSeed as $pos => $catName) {
                    /** @var Category $category */
                    $category = Category::create([
                        'restaurant_id' => $restaurant->id,
                        'name'          => $catName,
                        'description'   => $catName . ' menu',
                        'position'      => $pos + 1,
                    ]);

                    foreach ($productsByCategoryName[$catName] as $pSeed) {
                        $product = Product::create([
                            'restaurant_id' => $restaurant->id,
                            'category_id'   => $category->id,
                            'name'          => $pSeed['name'],
                            'description'   => $catName . ' - ' . $pSeed['name'],
                            'price'         => $pSeed['price'],
                            'is_available'  => true,
                            'image_path'    => null,
                        ]);

                        $products[] = $product;
                    }
                }

                /* ------------------------------------------------------
                 | 5. Clients
                 |-------------------------------------------------------*/
                $clientsSeed = [
                    ['name' => 'John Doe',        'email' => 'john.doe@example.com',       'phone' => '+254700000001'],
                    ['name' => 'Jane Smith',      'email' => 'jane.smith@example.com',     'phone' => '+254700000002'],
                    ['name' => 'Alex Mwangi',     'email' => 'alex.mwangi@example.com',    'phone' => '+254700000003'],
                    ['name' => 'Maria Fernandes', 'email' => 'maria.fernandes@example.com','phone' => '+254700000004'],
                ];

                $clients = [];

                foreach ($clientsSeed as $idx => $cSeed) {
                    $client = Client::create([
                        'restaurant_id' => $restaurant->id,
                        'name'          => $cSeed['name'],
                        'email'         => $cSeed['email'],
                        'phone'         => $cSeed['phone'],
                        'external_id'   => 'CL-' . $restaurant->id . '-' . ($idx + 1),
                    ]);

                    $clients[] = $client;
                }

                /* ------------------------------------------------------
                 | 6. Orders, items, transactions, reviews
                 |-------------------------------------------------------*/
                $waiters  = $usersByRole[UserRole::WAITER->value] ?? [];
                $cashiers = $usersByRole[UserRole::CASHIER->value] ?? [];

                if (empty($waiters) || empty($cashiers) || empty($products) || empty($tables)) {
                    // not enough data to build realistic orders
                    continue;
                }

                // Bias: more completed/served/in_progress than cancelled/pending
                $orderStatusPool = [
                    OrderStatus::COMPLETED,
                    OrderStatus::COMPLETED,
                    OrderStatus::SERVED,
                    OrderStatus::SERVED,
                    OrderStatus::IN_PROGRESS,
                    OrderStatus::READY,
                    OrderStatus::PENDING,
                    OrderStatus::CANCELLED,
                ];

                $commentSamples = [
                    'Everything was great!',
                    'Service was a bit slow, but food was good.',
                    'Absolutely loved it, will come back.',
                    'Food was okay, but drinks were excellent.',
                    'Amazing experience, highly recommended.',
                ];

                $orderCount = 25;

                for ($i = 1; $i <= $orderCount; $i++) {
                    /** @var Table $table */
                    $table  = $pick($tables);
                    /** @var User $waiter */
                    $waiter = $pick($waiters);
                    /** @var Client|null $client */
                    $client = $num(0, 100) < 70 ? $pick($clients) : null; // 70% have known client

                    /** @var OrderStatus $oStatus */
                    $oStatus = $pick($orderStatusPool);

                    // time window in last 5 days
                    $openedAt = $now->copy()->subDays($num(0, 5))->subMinutes($num(5, 240));
                    $closedAt = in_array($oStatus, [
                        OrderStatus::COMPLETED,
                        OrderStatus::SERVED,
                        OrderStatus::READY,
                        OrderStatus::CANCELLED,
                    ], true)
                        ? $openedAt->copy()->addMinutes($num(10, 120))
                        : null;

                    // Create order with temporary zeros for amounts, will update later
                    /** @var Order $order */
                    $order = Order::create([
                        'restaurant_id'   => $restaurant->id,
                        'table_id'        => $table->id,
                        'client_id'       => $client?->id,
                        'waiter_id'       => $waiter->id,
                        'status'          => $oStatus,
                        'source'          => $num(0, 100) < 20 ? 'online' : 'dine_in',
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

                    /* ----------------- Order items --------------------*/
                    $itemsCount  = $num(2, 5);
                    $subtotal    = 0.0;

                    for ($j = 1; $j <= $itemsCount; $j++) {
                        /** @var Product $prod */
                        $prod = $pick($products);
                        $qty  = $num(1, 3);
                        $unit = $prod->price;
                        $line = $qty * $unit;

                        $subtotal += $line;

                        // item status somewhat aligned with order status
                        $itemStatusPool = match ($oStatus) {
                            OrderStatus::PENDING     => [OrderItemStatus::PENDING],
                            OrderStatus::IN_PROGRESS => [OrderItemStatus::COOKING, OrderItemStatus::PENDING],
                            OrderStatus::READY       => [OrderItemStatus::READY, OrderItemStatus::COOKING],
                            OrderStatus::SERVED,
                            OrderStatus::COMPLETED   => [OrderItemStatus::SERVED, OrderItemStatus::READY],
                            OrderStatus::CANCELLED   => [OrderItemStatus::CANCELLED],
                        };

                        /** @var OrderItemStatus $itemStatus */
                        $itemStatus = $pick($itemStatusPool);

                        OrderItem::create([
                            'order_id'    => $order->id,
                            'product_id'  => $prod->id,
                            'quantity'    => $qty,
                            'unit_price'  => $unit,
                            'total_price' => $line,
                            'status'      => $itemStatus,
                            'notes'       => $num(0, 100) < 20 ? 'No onions, please.' : null,
                        ]);
                    }

                    $taxAmount     = round($subtotal * $restaurant->tax_rate, 2);
                    $serviceCharge = round($subtotal * $restaurant->service_charge_rate, 2);
                    $discount      = $num(0, 100) < 15 ? round($subtotal * 0.05, 2) : 0.0;
                    $total         = $subtotal + $taxAmount + $serviceCharge - $discount;

                    // Payment status logic
                    /** @var PaymentStatus $paymentStatus */
                    $paymentStatus = PaymentStatus::UNPAID;
                    $paidAmount    = 0.0;

                    if (in_array($oStatus, [OrderStatus::COMPLETED, OrderStatus::SERVED], true)) {
                        $paymentStatusPool = [
                            PaymentStatus::PAID,
                            PaymentStatus::PAID,
                            PaymentStatus::PARTIAL,
                            PaymentStatus::REFUNDED,
                        ];
                        $paymentStatus = $pick($paymentStatusPool);

                        if ($paymentStatus === PaymentStatus::PAID) {
                            $paidAmount = $total;
                        } elseif ($paymentStatus === PaymentStatus::PARTIAL) {
                            $paidAmount = round($total * (mt_rand(30, 80) / 100), 2);
                        } elseif ($paymentStatus === PaymentStatus::REFUNDED) {
                            $paidAmount = 0.0;
                        }
                    } elseif ($oStatus === OrderStatus::READY || $oStatus === OrderStatus::IN_PROGRESS) {
                        // sometimes partially paid even before completion
                        if ($num(0, 100) < 20) {
                            $paymentStatus = PaymentStatus::PARTIAL;
                            $paidAmount    = round($total * 0.3, 2);
                        }
                    }

                    // Update order totals & payment info
                    $order->update([
                        'subtotal'        => $subtotal,
                        'tax_amount'      => $taxAmount,
                        'service_charge'  => $serviceCharge,
                        'discount_amount' => $discount,
                        'total'           => $total,
                        'paid_amount'     => $paidAmount,
                        'payment_status'  => $paymentStatus,
                    ]);

                    /* ----------------- Transactions -------------------*/
                    if ($paymentStatus !== PaymentStatus::UNPAID) {
                        /** @var User $cashierUser */
                        $cashierUser = $pick($cashiers);

                        // handle partial / paid / refunded
                        if ($paymentStatus === PaymentStatus::PAID) {
                            $amount = $total;
                            $method = $pick([
                                PaymentMethod::CASH,
                                PaymentMethod::CARD,
                                PaymentMethod::MOBILE,
                            ]);

                            Transaction::create([
                                'order_id'     => $order->id,
                                'processed_by' => $cashierUser->id,
                                'amount'       => $amount,
                                'method'       => $method,
                                'status'       => PaymentStatus::PAID,
                                'reference'    => strtoupper($restaurant->settings['ticket_prefix']) . 'TX-' . $order->id,
                                'paid_at'      => $closedAt ?? $openedAt->copy()->addMinutes($num(5, 60)),
                            ]);
                        } elseif ($paymentStatus === PaymentStatus::PARTIAL) {
                            $amount = $paidAmount;
                            $method = $pick([
                                PaymentMethod::CASH,
                                PaymentMethod::CARD,
                                PaymentMethod::MOBILE,
                            ]);

                            Transaction::create([
                                'order_id'     => $order->id,
                                'processed_by' => $cashierUser->id,
                                'amount'       => $amount,
                                'method'       => $method,
                                'status'       => PaymentStatus::PARTIAL,
                                'reference'    => strtoupper($restaurant->settings['ticket_prefix']) . 'TX-P-' . $order->id,
                                'paid_at'      => $openedAt->copy()->addMinutes($num(3, 45)),
                            ]);
                        } elseif ($paymentStatus === PaymentStatus::REFUNDED) {
                            $amount = $total;

                            Transaction::create([
                                'order_id'     => $order->id,
                                'processed_by' => $cashierUser->id,
                                'amount'       => $amount,
                                'method'       => PaymentMethod::CARD,
                                'status'       => PaymentStatus::REFUNDED,
                                'reference'    => strtoupper($restaurant->settings['ticket_prefix']) . 'TX-R-' . $order->id,
                                'paid_at'      => $closedAt ?? $openedAt->copy()->addMinutes($num(10, 90)),
                            ]);
                        }
                    }

                    /* ----------------- Reviews ------------------------*/
                    if (in_array($oStatus, [OrderStatus::COMPLETED, OrderStatus::SERVED], true)
                        && $client
                        && $num(0, 100) < 60 // 60% of completed orders get reviews
                    ) {
                        Review::create([
                            'restaurant_id' => $restaurant->id,
                            'order_id'      => $order->id,
                            'client_id'     => $client->id,
                            'rating'        => $num(3, 5),
                            'comment'       => $pick($commentSamples),
                        ]);
                    }
                }
            }
        });
    }
}
