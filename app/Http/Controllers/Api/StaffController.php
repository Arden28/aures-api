<?php

namespace App\Http\Controllers\Api;

use App\Enums\StaffStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\StaffResource;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    /**
     * List staff for the authenticated restaurant.
     */
    public function index(Request $request): JsonResponse
    {
        $restaurantId = $request->user()->restaurant_id;

        $query = User::where('restaurant_id', $restaurantId)
            // staff roles only (exclude CLIENT)
            ->whereIn('role', [
                UserRole::OWNER,
                UserRole::MANAGER,
                UserRole::WAITER,
                UserRole::KITCHEN,
                UserRole::CASHIER,
            ])
            ->orderBy('name');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($role = $request->query('role')) {
            // Only apply if valid role
            if (in_array($role, array_column(UserRole::cases(), 'value'), true)) {
                $query->where('role', $role);
            }
        }

        $staff = $query->paginate(20);

        return response()->json(StaffResource::collection($staff));
    }

    /**
     * Create a staff member for the authenticated restaurant.
     */
    public function store(StoreStaffRequest $request): StaffResource
    {
        $restaurantId = $request->user()->restaurant_id;
        $data         = $request->validated();

        // Force restaurant + role in allowed set (handled in FormRequest)
        $user = User::create([
            'restaurant_id' => $restaurantId,
            'name'          => $data['name'],
            'email'         => $data['email'] ?? null,
            'password'      => $data['password'], // cast will hash
            'role'          => $data['role'],
        ]);

        return new StaffResource($user);
    }

    /**
     * Show a staff member (scoped).
     */
    public function show(Request $request, User $staff): StaffResource
    {
        $this->authorizeRestaurant($request, $staff);

        $staff->loadMissing('restaurant');

        return new StaffResource($staff);
    }

    /**
     * Update a staff member (scoped).
     */
    public function update(UpdateStaffRequest $request, User $staff): StaffResource
    {
        $this->authorizeRestaurant($request, $staff);

        $data = $request->validated();
        unset($data['restaurant_id']);

        // If password is empty in payload, drop it so we don't overwrite with ''
        if (array_key_exists('password', $data) && empty($data['password'])) {
            unset($data['password']);
        }

        $staff->update($data);

        return new StaffResource($staff->fresh('restaurant'));
    }

    /**
     * Change staff status (active / inactive).
     */
    public function updateStatus(Request $request, User $staff): JsonResponse
    {
        $this->authorizeRestaurant($request, $staff); // or whatever auth check you use

        $request->validate([
            'status' => 'required|in:' . implode(',', array_column(StaffStatus::cases(), 'value')),
        ]);

        $newStatus     = $request->status;
        $currentStatus = $staff->status->value;

        // Only allow ACTIVE <-> INACTIVE transitions
        $allowedTransitions = [
            StaffStatus::ACTIVE->value   => [StaffStatus::INACTIVE->value],
            StaffStatus::INACTIVE->value => [StaffStatus::ACTIVE->value],
        ];

        // if (! in_array($newStatus, $allowedTransitions[$currentStatus] ?? [], true)) {
        //     return response()->json([
        //         'message' => "Invalid transition: staff cannot move from '$currentStatus' to '$newStatus'.",
        //     ], 422);
        // }

        $staff->status = $newStatus;
        $staff->save();

        return response()->json([
            'message' => 'Staff status updated successfully.',
            'data'    => [
                'id'         => $staff->id,
                'old_status' => $currentStatus,
                'new_status' => $staff->status->value,
            ],
        ]);
    }

    /**
     * Delete staff (scoped).
     */
    public function destroy(Request $request, User $staff): JsonResponse
    {
        $this->authorizeRestaurant($request, $staff);

        // Avoid deleting the currently authenticated user if you don't want that – optional check
        // if ($request->user()->id === $staff->id) {
        //     abort(422, 'You cannot delete your own account from here.');
        // }

        $staff->delete();

        return response()->json([
            'message' => 'Staff member deleted successfully.',
        ]);
    }

    protected function authorizeRestaurant(Request $request, User $staff): void
    {
        if ($request->user()->restaurant_id !== $staff->restaurant_id) {
            abort(403, 'Forbidden for this restaurant.');
        }
    }
}
