<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\TableStatus;
use App\Models\Category;
use App\Models\FloorPlan;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    /**
     * GET /api/v1/dashboard/overview
     *
     * Query params:
     *  - timeframe: today|week|month|year (default: today)
     *
     * The response is tailored to the authenticated user's role:
     *  - OWNER/MANAGER: global restaurant overview + staff performance
     *  - WAITER: only their own orders
     *  - CASHIER: only orders they processed payments for
     *  - KITCHEN: focus on active orders (PENDING/PREPARING/READY)
     *
     * CLIENT role is not allowed.
     */
    public function overview(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user || $user->role === UserRole::CLIENT) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Dashboard not available for this user.',
            ], 403);
        }

        /** @var Restaurant|null $restaurant */
        $restaurant = $user->restaurant;

        if (! $restaurant) {
            return response()->json([
                'status'  => 'error',
                'message' => 'User is not attached to any restaurant.',
            ], 422);
        }

        $timeframe = $this->normalizeTimeframe((string) $request->query('timeframe', 'today'));
        [$from, $to] = $this->resolveTimeRange($timeframe, $restaurant->timezone ?? config('app.timezone'));

        // Base orders query, scoped by role
        $ordersQuery = $this->buildScopedOrdersQuery($user, $from, $to);

        // Clone for reuse
        $ordersForMetrics = (clone $ordersQuery)->with(['table', 'waiter', 'client', 'transactions']);

        /** @var Collection<int, Order> $orders */
        $orders = $ordersForMetrics->get();

        $currency = $restaurant->currency ?? 'KES';

        // ---- Metrics ------------------------------------------------------
        $totalOrders = $orders->count();

        // Revenue uses PAID and PARTIAL totals
        $revenue = $orders
            ->filter(function (Order $o) {
                return in_array($o->payment_status, [PaymentStatus::PAID, PaymentStatus::PARTIAL], true);
            })
            ->sum('total');

        $averageOrderValue = $totalOrders > 0 ? ($revenue / $totalOrders) : 0.0;

        $activeOrders = $orders->filter(function (Order $o) {
            return in_array($o->status, [
                OrderStatus::PENDING,
                OrderStatus::PREPARING,
                OrderStatus::READY,
            ], true);
        })->count();

        $completedInPeriod = $orders->filter(function (Order $o) {
            return in_array($o->status, [OrderStatus::COMPLETED, OrderStatus::SERVED], true);
        })->count();

        // Occupancy: based on tables for the restaurant
        $tables = Table::where('restaurant_id', $restaurant->id)->get();

        $totalTables = $tables->count();
        $tablesInUse = $tables->filter(function (Table $t) {
            return $t->status !== TableStatus::FREE;
        })->count();

        $occupancyRate = $totalTables > 0 ? ($tablesInUse / $totalTables) * 100 : 0.0;

        // ---- Series -------------------------------------------------------
        $revenueSeries   = $this->buildRevenueSeries($orders, $timeframe, $restaurant->timezone);
        $ordersSeries    = $this->buildOrdersSeries($orders, $timeframe, $restaurant->timezone);
        $statusCounts    = $this->buildOrdersByStatus($orders);
        $sourceCounts    = $this->buildOrdersBySource($orders);
        $paymentCounts   = $this->buildPaymentBreakdown($orders);
        $topProducts     = $this->buildTopProducts($orders);
        $floorPlans      = $this->buildFloorPlanOccupancy($restaurant->id);
        $recentOrders    = $this->buildRecentOrders($orders);

        // Staff performance: only for OWNER / MANAGER
        $staffPerformance = null;
        if (in_array($user->role, [UserRole::OWNER, UserRole::MANAGER], true)) {
            $staffPerformance = $this->buildStaffPerformance($restaurant, $orders);
        }

        $efficiency = $this->buildOperationalEfficiency($orders);

        // ---- Response -----------------------------------------------------
        return response()->json([
            'status' => 'success',
            'data'   => [
                'currency'  => $currency,
                'timeframe' => $timeframe,
                'metrics'   => [
                    'total_revenue'       => round($revenue, 2),
                    'total_orders'        => $totalOrders,
                    'average_order_value' => round($averageOrderValue, 2),
                    'active_orders'       => $activeOrders,
                    'completed_today'     => $completedInPeriod,
                    'occupancy_rate'      => round($occupancyRate, 1),
                ],
                'revenue_series'     => $revenueSeries,
                'orders_series'      => $ordersSeries,
                'orders_by_status'   => $statusCounts,
                'orders_by_source'   => $sourceCounts,
                'payment_breakdown'  => $paymentCounts,
                'top_products'       => $topProducts,
                'floor_plans'        => $floorPlans,
                'recent_orders'      => $recentOrders,
                'staff_performance'  => $staffPerformance,
                'operational_efficiency' => $efficiency
            ],
        ]);
    }

    /**
     * Calculate average duration (in minutes) between key status checkpoints.
     */
    protected function buildOperationalEfficiency(Collection $orders): array
    {
        $times = [
            'wait_time' => [],   // Pending -> Preparing (Reaction time)
            'prep_time' => [],   // Preparing -> Ready (Kitchen speed)
            'serve_time' => [],  // Ready -> Served (Waiter pickup speed)
        ];

        foreach ($orders as $order) {
            $history = $order->statusHistory ?? [];
            if (empty($history)) continue;

            // Helper to find timestamp of specific status
            $findTime = function ($status) use ($history) {
                foreach ($history as $entry) {
                    if (($entry['status'] ?? '') === $status) return Carbon::parse($entry['at']);
                }
                return null;
            };

            $pending   = $order->opened_at; // Or $findTime(OrderStatus::PENDING->value)
            $preparing = $findTime(OrderStatus::PREPARING->value);
            $ready     = $findTime(OrderStatus::READY->value);
            $served    = $findTime(OrderStatus::SERVED->value);

            // 1. Reaction Time (Client sits -> Kitchen starts)
            if ($pending && $preparing) {
                $times['wait_time'][] = $pending->diffInMinutes($preparing);
            }

            // 2. Kitchen Time (Start cooking -> Food ready)
            if ($preparing && $ready) {
                $times['prep_time'][] = $preparing->diffInMinutes($ready);
            }

            // 3. Service Time (Food ready -> On table)
            if ($ready && $served) {
                $times['serve_time'][] = $ready->diffInMinutes($served);
            }
        }

        // Calculate Averages
        $avg = fn($arr) => count($arr) > 0 ? round(array_sum($arr) / count($arr), 1) : 0;

        return [
            'avg_wait_mins'  => $avg($times['wait_time']),
            'avg_prep_mins'  => $avg($times['prep_time']),
            'avg_serve_mins' => $avg($times['serve_time']),
        ];
    }

    /** Normalize and whitelist timeframe. */
    protected function normalizeTimeframe(string $timeframe): string
    {
        $timeframe = strtolower($timeframe);
        return in_array($timeframe, ['today', 'week', 'month', 'year'], true)
            ? $timeframe
            : 'today';
    }

    /**
     * Resolve [from, to] datetime for a given timeframe.
     */
    protected function resolveTimeRange(string $timeframe, string $tz): array
    {
        $now = Carbon::now($tz);

        return match ($timeframe) {
            'today' => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
            ],
            'week' => [
                $now->copy()->startOfWeek(),
                $now->copy()->endOfWeek(),
            ],
            'month' => [
                $now->copy()->startOfMonth(),
                $now->copy()->endOfMonth(),
            ],
            'year' => [
                $now->copy()->startOfYear(),
                $now->copy()->endOfYear(),
            ],
            default => [
                $now->copy()->startOfDay(),
                $now->copy()->endOfDay(),
            ],
        };
    }

    /**
     * Build a base orders query scoped to:
     *  - restaurant
     *  - timeframe
     *  - user role (owner/manager vs waiter vs cashier vs kitchen)
     */
    protected function buildScopedOrdersQuery(User $user, Carbon $from, Carbon $to): Builder
    {
        $query = Order::query()
            ->where('restaurant_id', $user->restaurant_id)
            ->whereBetween('opened_at', [$from, $to]);

        switch ($user->role) {
            case UserRole::WAITER:
                $query->where('waiter_id', $user->id);
                break;

            case UserRole::CASHIER:
                $query->whereHas('transactions', function (Builder $q) use ($user) {
                    $q->where('processed_by', $user->id);
                });
                break;

            case UserRole::KITCHEN:
                $query->whereIn('status', [
                    OrderStatus::PENDING,
                    OrderStatus::PREPARING,
                    OrderStatus::READY,
                ]);
                break;

            case UserRole::MANAGER:
            case UserRole::OWNER:
            default:
                // already scoped by restaurant
                break;
        }

        return $query;
    }

    /**
     * Build revenue series for chart.
     * For "today" -> group by hour label ("09:00"), otherwise group by date ("Nov 20").
     */
    protected function buildRevenueSeries(Collection $orders, string $timeframe, ?string $tz): array
    {
        $tz = $tz ?: config('app.timezone');
        $buckets = [];

        /** @var Order $order */
        foreach ($orders as $order) {
            if (! $order->opened_at) {
                continue;
            }

            $opened = $order->opened_at->copy()->setTimezone($tz);

            $label = $timeframe === 'today'
                ? $opened->format('H:00')
                : $opened->format('M d');

            if (! isset($buckets[$label])) {
                $buckets[$label] = 0.0;
            }
            $buckets[$label] += $order->total;
        }

        $series = [];
        foreach ($buckets as $label => $total) {
            $series[] = [
                'label' => $label,
                'total' => round($total, 2),
            ];
        }

        // keep sorted by label (time)
        usort($series, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $series;
    }

    /**
     * Build orders series grouped by label & source.
     * (Dine-in / online / takeaway), same labels as revenue series.
     */
    protected function buildOrdersSeries(Collection $orders, string $timeframe, ?string $tz): array
    {
        $tz = $tz ?: config('app.timezone');
        $buckets = [];

        /** @var Order $order */
        foreach ($orders as $order) {
            if (! $order->opened_at) {
                continue;
            }

            $opened = $order->opened_at->copy()->setTimezone($tz);

            $label = $timeframe === 'today'
                ? $opened->format('H:00')
                : $opened->format('M d');

            if (! isset($buckets[$label])) {
                $buckets[$label] = [
                    'label'    => $label,
                    'dine_in'  => 0,
                    'online'   => 0,
                    'takeaway' => 0,
                ];
            }

            $source = $order->source ?? 'dine_in';

            if ($source === 'online') {
                $buckets[$label]['online']++;
            } elseif ($source === 'takeaway') {
                $buckets[$label]['takeaway']++;
            } else {
                $buckets[$label]['dine_in']++;
            }
        }

        $series = array_values($buckets);
        usort($series, fn ($a, $b) => strcmp($a['label'], $b['label']));

        return $series;
    }

    /** Count orders by status. */
    protected function buildOrdersByStatus(Collection $orders): array
    {
        $init = [
            'pending'     => 0,
            'preparing' => 0,
            'ready'       => 0,
            'served'      => 0,
            'completed'   => 0,
            'cancelled'   => 0,
        ];

        /** @var Order $o */
        foreach ($orders as $o) {
            $status = $o->status?->value ?? null;
            if ($status && isset($init[$status])) {
                $init[$status]++;
            }
        }

        return $init;
    }

    /** Count orders by source. */
    protected function buildOrdersBySource(Collection $orders): array
    {
        $init = [
            'dine_in'  => 0,
            'online'   => 0,
            'takeaway' => 0,
        ];

        /** @var Order $o */
        foreach ($orders as $o) {
            $source = $o->source ?? 'dine_in';
            if (! isset($init[$source])) {
                $init[$source] = 0;
            }
            $init[$source]++;
        }

        return $init;
    }

    /** Payments breakdown by PaymentStatus. */
    protected function buildPaymentBreakdown(Collection $orders): array
    {
        $init = [
            'unpaid'   => 0,
            'partial'  => 0,
            'paid'     => 0,
            'refunded' => 0,
        ];

        /** @var Order $o */
        foreach ($orders as $o) {
            $status = $o->payment_status?->value ?? null;
            if ($status && isset($init[$status])) {
                $init[$status]++;
            }
        }

        return $init;
    }

    /** Top products (by quantity + revenue) in the scoped orders. */
    protected function buildTopProducts(Collection $orders): array
    {
        $orderIds = $orders->pluck('id')->all();
        if (empty($orderIds)) {
            return [];
        }

        $rows = OrderItem::query()
            ->selectRaw('product_id, SUM(quantity) as total_quantity, SUM(total_price) as total_revenue')
            ->whereIn('order_id', $orderIds)
            ->groupBy('product_id')
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->get();

        $products = Product::whereIn('id', $rows->pluck('product_id'))->get()->keyBy('id');
        $categories = Category::whereIn('id', $products->pluck('category_id'))->get()->keyBy('id');

        return $rows->map(function ($row) use ($products, $categories) {
            $product = $products->get($row->product_id);
            $category = $product ? $categories->get($product->category_id) : null;

            return [
                'id'             => $row->product_id,
                'name'           => $product?->name ?? 'Unknown product',
                'category'       => $category?->name ?? 'Uncategorized',
                'total_quantity' => (int) $row->total_quantity,
                'total_revenue'  => round($row->total_revenue, 2),
            ];
        })->values()->all();
    }

    /** Floor plan occupancy snapshot per restaurant. */
    protected function buildFloorPlanOccupancy(int $restaurantId): array
    {
        $plans = FloorPlan::where('restaurant_id', $restaurantId)
            ->with('tables')
            ->get();

        return $plans->map(function (FloorPlan $plan) {
            $tables = $plan->tables;
            $total = $tables->count();

            $occupied      = $tables->where('status', TableStatus::OCCUPIED)->count();
            $reserved      = $tables->where('status', TableStatus::RESERVED)->count();
            $needsCleaning = $tables->where('status', TableStatus::NEEDS_CLEANING)->count();

            return [
                'id'                    => $plan->id,
                'name'                  => $plan->name,
                'total_tables'          => $total,
                'occupied_tables'       => $occupied,
                'reserved_tables'       => $reserved,
                'needs_cleaning_tables' => $needsCleaning,
            ];
        })->values()->all();
    }

    /** Last 10 orders for the scoped query. */
    protected function buildRecentOrders(Collection $orders): array
    {
        return $orders
            ->sortByDesc('opened_at')
            ->take(10)
            ->map(function (Order $o) {
                return [
                    'id'             => $o->id,
                    'code'           => (string) $o->id,
                    'table_name'     => $o->table?->name,
                    'waiter_name'    => $o->waiter?->name,
                    'client_name'    => $o->client?->name,
                    'status'         => $o->status?->value ?? 'unknown',
                    'payment_status' => $o->payment_status?->value ?? 'unpaid',
                    'total'          => (float) $o->total,
                    'opened_at'      => $o->opened_at?->toIso8601String() ?? now()->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Staff performance, only for OWNER / MANAGER.
     *
     * Returns:
     *  - waiters: orders handled, revenue, AOV, active/completed orders
     *  - cashiers: payments processed & total amount
     */
    protected function buildStaffPerformance(Restaurant $restaurant, Collection $orders): array
    {
        $waiterStats = [];
        $cashierStats = [];

        /** @var Order $order */
        foreach ($orders as $order) {
            // Waiter side
            if ($order->waiter_id) {
                $wid = (int) $order->waiter_id;
                if (! isset($waiterStats[$wid])) {
                    $waiterStats[$wid] = [
                        'id'                 => $wid,
                        'total_orders'       => 0,
                        'total_revenue'      => 0.0,
                        'active_orders'      => 0,
                        'completed_orders'   => 0,
                    ];
                }

                $waiterStats[$wid]['total_orders']++;
                $waiterStats[$wid]['total_revenue'] += (float) $order->total;

                if (in_array($order->status, [
                    OrderStatus::PENDING,
                    OrderStatus::PREPARING,
                    OrderStatus::READY,
                ], true)) {
                    $waiterStats[$wid]['active_orders']++;
                }

                if (in_array($order->status, [
                    OrderStatus::COMPLETED,
                    OrderStatus::SERVED,
                ], true)) {
                    $waiterStats[$wid]['completed_orders']++;
                }
            }

            // Cashier side (via transactions)
            foreach ($order->transactions as $tx) {
                if (! $tx->processed_by) {
                    continue;
                }
                $cid = (int) $tx->processed_by;
                if (! isset($cashierStats[$cid])) {
                    $cashierStats[$cid] = [
                        'id'               => $cid,
                        'payments_count'   => 0,
                        'total_processed'  => 0.0,
                    ];
                }

                $cashierStats[$cid]['payments_count']++;
                $cashierStats[$cid]['total_processed'] += (float) $tx->amount;
            }
        }

        $allIds = array_unique(array_merge(
            array_keys($waiterStats),
            array_keys($cashierStats)
        ));

        $users = User::where('restaurant_id', $restaurant->id)
            ->whereIn('id', $allIds)
            ->get()
            ->keyBy('id');

        // Build waiters list
        $waiters = [];
        foreach ($waiterStats as $id => $stats) {
            /** @var User|null $u */
            $u = $users->get((int) $id);

            $totalOrders  = $stats['total_orders'];
            $totalRevenue = $stats['total_revenue'];
            $aov          = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0.0;

            $waiters[] = [
                'id'                 => (int) $id,
                'name'               => $u?->name ?? 'Unknown waiter',
                'role'               => $u?->role?->value ?? 'waiter',
                'total_orders'       => $totalOrders,
                'total_revenue'      => round($totalRevenue, 2),
                'average_order_value'=> round($aov, 2),
                'active_orders'      => $stats['active_orders'],
                'completed_orders'   => $stats['completed_orders'],
            ];
        }

        usort($waiters, fn ($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);

        // Build cashiers list
        $cashiers = [];
        foreach ($cashierStats as $id => $stats) {
            /** @var User|null $u */
            $u = $users->get((int) $id);

            $cashiers[] = [
                'id'              => (int) $id,
                'name'            => $u?->name ?? 'Unknown cashier',
                'role'            => $u?->role?->value ?? 'cashier',
                'payments_count'  => $stats['payments_count'],
                'total_processed' => round($stats['total_processed'], 2),
            ];
        }

        usort($cashiers, fn ($a, $b) => $b['total_processed'] <=> $a['total_processed']);

        return [
            'waiters'  => $waiters,
            'cashiers' => $cashiers,
        ];
    }
}
