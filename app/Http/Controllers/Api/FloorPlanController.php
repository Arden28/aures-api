<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Floor\StoreFloorRequest;
use App\Http\Requests\Floor\UpdateFloorRequest;
use App\Http\Resources\FloorPlanResource;
use App\Models\FloorPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FloorPlanController extends Controller
{
    /**
     * List floor plans for the authenticated restaurant.
     */
    public function index(Request $request): JsonResponse
    {
        $restaurantId = $request->user()->restaurant_id;

        $floorPlans = FloorPlan::with('tables')
            ->where('restaurant_id', $restaurantId)
            ->orderBy('name')
            ->get();

        return response()->json(FloorPlanResource::collection($floorPlans));
    }

    /**
     * Create floor plan for restaurant.
     */
    public function store(StoreFloorRequest $request): FloorPlanResource
    {
        $restaurantId = $request->user()->restaurant_id;

        $data = $request->validated();

        $floorPlan = FloorPlan::create([
            ...$data,
            'restaurant_id' => $restaurantId,
        ]);

        return new FloorPlanResource($floorPlan);
    }

    /**
     * Show floor plan (scoped).
     */
    public function show(Request $request, FloorPlan $floorPlan): FloorPlanResource
    {
        $this->authorizeRestaurant($request, $floorPlan);

        $floorPlan->loadMissing('tables');

        return new FloorPlanResource($floorPlan);
    }

    /**
     * Update floor plan (scoped).
     */
    public function update(UpdateFloorRequest $request, FloorPlan $floorPlan): FloorPlanResource
    {
        $this->authorizeRestaurant($request, $floorPlan);

        $data = $request->validated();
        unset($data['restaurant_id']);

        $floorPlan->update($data);

        return new FloorPlanResource($floorPlan->fresh('tables'));
    }

    /**
     * Delete floor plan (scoped).
     */
    public function destroy(Request $request, FloorPlan $floorPlan): JsonResponse
    {
        $this->authorizeRestaurant($request, $floorPlan);

        $floorPlan->delete();

        return response()->json([
            'message' => 'Floor plan deleted successfully.',
        ]);
    }

    protected function authorizeRestaurant(Request $request, FloorPlan $floorPlan): void
    {
        if ($request->user()->restaurant_id !== $floorPlan->restaurant_id) {
            abort(403, 'Forbidden for this restaurant.');
        }
    }
}
