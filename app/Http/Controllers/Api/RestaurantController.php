<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestaurantController extends Controller
{
    /**
     * GET /api/v1/restaurant
     *
     * Returns the authenticated user's restaurant with settings.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $restaurant = $user?->restaurant;

        if (! $restaurant) {
            return response()->json([
                'status'  => 'error',
                'message' => 'User is not attached to any restaurant.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'data'   => $restaurant,
        ]);
    }

    /**
     * PUT /api/v1/restaurant
     *
     * Update restaurant profile + configuration settings.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $restaurant = $user?->restaurant;

        if (! $restaurant) {
            return response()->json([
                'status'  => 'error',
                'message' => 'User is not attached to any restaurant.',
            ], 422);
        }

        $validated = $request->validate([
            'name'                => ['required', 'string', 'max:255'],
            'currency'            => ['required', 'string', 'max:10'],
            'timezone'            => ['required', 'string', 'max:64'],
            // DB is using decimals like 0.16 = 16% / 0.10 = 10%
            'tax_rate'            => ['nullable', 'numeric', 'min:0', 'max:1'],
            'service_charge_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],

            'settings'                             => ['array'],
            'settings.ticket_prefix'               => ['nullable', 'string', 'max:20'],
            'settings.enable_tips'                 => ['boolean'],
            'settings.kds_sound'                   => ['boolean'],
            'settings.order_timeout'               => ['nullable', 'integer', 'min:0'], // seconds
            'settings.auto_accept_online_orders'   => ['boolean'],
            'settings.auto_close_paid_orders'      => ['boolean'],
            'settings.enable_kds_auto_bump'        => ['boolean'],
            'settings.receipt_footer'              => ['nullable', 'string', 'max:500'],
        ]);

        $restaurant->name                = $validated['name'];
        $restaurant->currency            = $validated['currency'];
        $restaurant->timezone            = $validated['timezone'];
        $restaurant->tax_rate            = $validated['tax_rate'] ?? 0.0;
        $restaurant->service_charge_rate = $validated['service_charge_rate'] ?? 0.0;

        // Merge settings so we don’t drop unknown / future keys
        $currentSettings = $restaurant->settings ?? [];
        $newSettings     = $validated['settings'] ?? [];

        $restaurant->settings = array_merge($currentSettings, $newSettings);

        $restaurant->save();

        return response()->json([
            'status' => 'success',
            'data'   => $restaurant->fresh(),
        ]);
    }
}
