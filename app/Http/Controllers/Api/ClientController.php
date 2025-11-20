<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\StoreClientRequest;
use App\Http\Requests\Client\UpdateClientRequest;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    /**
     * List clients for the authenticated restaurant.
     */
    public function index(Request $request): JsonResponse
    {
        $restaurantId = $request->user()->restaurant_id;

        $query = Client::where('restaurant_id', $restaurantId)
            ->orderByDesc('created_at');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $clients = $query->paginate(20);

        return response()->json(ClientResource::collection($clients));
    }

    /**
     * Create a client for the authenticated restaurant.
     */
    public function store(StoreClientRequest $request): ClientResource
    {
        $restaurantId = $request->user()->restaurant_id;

        $data = $request->validated();

        $client = Client::create([
            ...$data,
            'restaurant_id' => $restaurantId,
        ]);

        return new ClientResource($client);
    }

    /**
     * Show a single client (scoped).
     */
    public function show(Request $request, Client $client): ClientResource
    {
        $this->authorizeRestaurant($request, $client);

        $client->loadMissing('restaurant');

        return new ClientResource($client);
    }

    /**
     * Update a client (scoped).
     */
    public function update(UpdateClientRequest $request, Client $client): ClientResource
    {
        $this->authorizeRestaurant($request, $client);

        $data = $request->validated();
        unset($data['restaurant_id']);

        $client->update($data);

        return new ClientResource($client->fresh());
    }

    /**
     * Delete a client (scoped).
     */
    public function destroy(Request $request, Client $client): JsonResponse
    {
        $this->authorizeRestaurant($request, $client);

        $client->delete();

        return response()->json([
            'message' => 'Client deleted successfully.',
        ]);
    }

    protected function authorizeRestaurant(Request $request, Client $client): void
    {
        if ($request->user()->restaurant_id !== $client->restaurant_id) {
            abort(403, 'Forbidden for this restaurant.');
        }
    }
}
