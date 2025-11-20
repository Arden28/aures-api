<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Product\StoreProductRequest;
use App\Http\Requests\Product\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    /**
     * List products for restaurant with optional filters.
     */
    public function index(Request $request): JsonResponse
    {
        $restaurantId = $request->user()->restaurant_id;

        $query = Product::with('category')
            ->where('restaurant_id', $restaurantId);

        if ($categoryId = $request->query('category_id')) {
            $query->where('category_id', $categoryId);
        }

        if (! is_null($request->query('available'))) {
            $available = filter_var($request->query('available'), FILTER_VALIDATE_BOOL);
            $query->where('is_available', $available);
        }

        if ($search = $request->query('search')) {
            $query->where('name', 'like', "%{$search}%");
        }

        $products = $query->paginate(20);

        return response()->json(ProductResource::collection($products));
    }

    /**
     * Create a product for the restaurant.
     */
    public function store(StoreProductRequest $request): ProductResource
    {
        $restaurantId = $request->user()->restaurant_id;
        $data         = $request->validated();

        // Ensure category belongs to same restaurant
        if (! empty($data['category_id'])) {
            $belongs = Category::where('id', $data['category_id'])
                ->where('restaurant_id', $restaurantId)
                ->exists();

            if (! $belongs) {
                abort(422, 'Category does not belong to this restaurant.');
            }
        }

        $product = Product::create([
            ...$data,
            'restaurant_id' => $restaurantId,
        ]);

        return new ProductResource($product->load('category'));
    }

    /**
     * Show a product (scoped).
     */
    public function show(Request $request, Product $product): ProductResource
    {
        $this->authorizeRestaurant($request, $product);

        $product->loadMissing('category');

        return new ProductResource($product);
    }

    /**
     * Update a product (scoped).
     */
    public function update(UpdateProductRequest $request, Product $product): ProductResource
    {
        $this->authorizeRestaurant($request, $product);

        $data = $request->validated();
        unset($data['restaurant_id']);

        if (array_key_exists('category_id', $data) && $data['category_id']) {
            $restaurantId = $request->user()->restaurant_id;
            $belongs = Category::where('id', $data['category_id'])
                ->where('restaurant_id', $restaurantId)
                ->exists();

            if (! $belongs) {
                abort(422, 'Category does not belong to this restaurant.');
            }
        }

        $product->update($data);

        return new ProductResource($product->fresh('category'));
    }

    /**
     * Delete a product (scoped).
     */
    public function destroy(Request $request, Product $product): JsonResponse
    {
        $this->authorizeRestaurant($request, $product);

        $product->delete();

        return response()->json([
            'message' => 'Product deleted successfully.',
        ]);
    }

    protected function authorizeRestaurant(Request $request, Product $product): void
    {
        if ($request->user()->restaurant_id !== $product->restaurant_id) {
            abort(403, 'Forbidden for this restaurant.');
        }
    }
}
