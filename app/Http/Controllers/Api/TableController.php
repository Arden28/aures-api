<?php

namespace App\Http\Controllers\Api;

use App\Enums\TableStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Table\StoreTableRequest;
use App\Http\Requests\Table\UpdateTableRequest;
use App\Http\Resources\TableResource;
use App\Models\FloorPlan;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TableController extends Controller
{
    /**
     * List tables for the restaurant.
     */
    public function index(Request $request): JsonResponse
    {
        $restaurantId = $request->user()->restaurant_id;

        $tables = Table::with('floorPlan')
            ->where('restaurant_id', $restaurantId)
            ->orderBy('name')
            ->get();

        return response()->json(TableResource::collection($tables));
    }

    /**
     * Create a table for the restaurant.
     */
    public function store(StoreTableRequest $request): TableResource
    {
        $restaurantId = $request->user()->restaurant_id;
        $data         = $request->validated();

        // Ensure floor plan (if provided) belongs to this restaurant
        if (! empty($data['floor_plan_id'])) {
            $belongs = FloorPlan::where('id', $data['floor_plan_id'])
                ->where('restaurant_id', $restaurantId)
                ->exists();

            if (! $belongs) {
                abort(422, 'Floor plan does not belong to this restaurant.');
            }
        }

        $table = Table::create([
            'restaurant_id' => $restaurantId,
            'floor_plan_id' => $data['floor_plan_id'] ?? null,
            'name'          => $data['name'],
            'capacity'      => $data['capacity'],
            'code'          => $this->generateCode($restaurantId),
            'qr_token'      => Str::uuid()->toString(),
            'status'        => TableStatus::FREE,
        ]);

        return new TableResource($table);
    }

    /**
     * Show a table (scoped).
     */
    public function show(Request $request, Table $table): TableResource
    {
        $this->authorizeRestaurant($request, $table);

        $table->loadMissing('floorPlan', 'orders');

        return new TableResource($table);
    }

    /**
     * Update a table (scoped).
     */
    public function update(UpdateTableRequest $request, Table $table): TableResource
    {
        $this->authorizeRestaurant($request, $table);

        $data = $request->validated();
        unset($data['restaurant_id']);

        // Re-check floor plan if updated
        if (array_key_exists('floor_plan_id', $data) && $data['floor_plan_id']) {
            $restaurantId = $request->user()->restaurant_id;
            $belongs = FloorPlan::where('id', $data['floor_plan_id'])
                ->where('restaurant_id', $restaurantId)
                ->exists();

            if (! $belongs) {
                abort(422, 'Floor plan does not belong to this restaurant.');
            }
        }

        $table->update($data);

        return new TableResource($table->fresh('floorPlan'));
    }

    /**
     * Change table status (state machine) - scoped.
     */
    public function updateStatus(Request $request, Table $table): JsonResponse
    {
        $this->authorizeRestaurant($request, $table);

        $request->validate([
            'status' => 'required|in:' . implode(',', array_column(TableStatus::cases(), 'value')),
        ]);

        $newStatus     = $request->status;
        $currentStatus = $table->status->value;

        $allowedTransitions = [
            TableStatus::FREE->value => [
                TableStatus::RESERVED->value,
                TableStatus::OCCUPIED->value,
            ],
            TableStatus::RESERVED->value => [
                TableStatus::OCCUPIED->value,
                TableStatus::FREE->value,
            ],
            TableStatus::OCCUPIED->value => [
                TableStatus::NEEDS_CLEANING->value,
            ],
            TableStatus::NEEDS_CLEANING->value => [
                TableStatus::FREE->value,
            ],
        ];

        if (! in_array($newStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
            return response()->json([
                'message' => "Invalid transition: table cannot move from '$currentStatus' to '$newStatus'.",
            ], 422);
        }

        $table->status = $newStatus;
        $table->save();

        return response()->json([
            'message' => 'Table status updated successfully.',
            'data'    => [
                'id'         => $table->id,
                'old_status' => $currentStatus,
                'new_status' => $table->status->value,
            ],
        ]);
    }

    /**
     * Delete table (scoped).
     */
    public function destroy(Request $request, Table $table): JsonResponse
    {
        $this->authorizeRestaurant($request, $table);

        $table->delete();

        return response()->json([
            'message' => 'Table deleted successfully.',
        ]);
    }


    /**
     * POST /api/v1/tables/{code}/session/{sessionId}/close
     * * Closes the table session (Checkout).
     */
    public function closeSession(string $code, string $sessionId): JsonResponse
    {
        $table   = Table::where('code', $code)->firstOrFail();
        $session = TableSession::where('id', $sessionId)
            ->where('table_id', $table->id)
            ->firstOrFail();

        // 1. Validation: Check for Unpaid Orders
        // We cannot close if there is money owed.
        $unpaidOrders = $session->orders()
            ->where('payment_status', '!=', 'paid')
            ->where('status', '!=', 'cancelled')
            ->exists();

        if ($unpaidOrders) {
            return response()->json([
                'message' => 'Cannot close session. There are unpaid orders.'
            ], 422);
        }

        // 2. Validation: Check for Active Pipeline Orders
        // (Optional: You might want to allow closing "served" items, but definitely not "pending" or "cooking")
        $activePipeline = $session->orders()
            ->whereIn('status', ['pending', 'preparing', 'ready'])
            ->exists();

        if ($activePipeline) {
             return response()->json([
                'message' => 'Cannot close session. Some orders are still being prepared.'
            ], 422);
        }

        DB::transaction(function () use ($session, $table) {
            $session->update([
                'status'    => 'closed',
                'closed_at' => now()
            ]);

            $table->update(['status' => 'needs_cleaning']);
        });

        return response()->json(['message' => 'Session closed successfully.']);
    }

    protected function authorizeRestaurant(Request $request, Table $table): void
    {
        if ($request->user()->restaurant_id !== $table->restaurant_id) {
            abort(403, 'Forbidden for this restaurant.');
        }
    }

    protected function generateCode(int $restaurantId): string
    {
        do {
            $code = 'T-' . $restaurantId . '-' . random_int(100, 999);
        } while (Table::where('code', $code)->exists());

        return $code;
    }
}
