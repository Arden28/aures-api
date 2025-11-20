<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Category\StoreCategoryRequest;
use App\Http\Requests\Category\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    /**
     * List categories for the authenticated user's restaurant.
     */
    public function index(Request $request): JsonResponse
    {
        $restaurantId = $request->user()->restaurant_id;

        $query = Category::query()
            ->where('restaurant_id', $restaurantId)
            ->orderBy('position')
            ->orderBy('name');

        // (Optional) simple search by name
        if ($search = $request->query('search')) {
            $query->where('name', 'like', '%' . $search . '%');
        }

        $categories = $query->get();

        return response()->json(CategoryResource::collection($categories));
    }

    /**
     * Store a newly created category for the authenticated restaurant.
     */
    public function store(StoreCategoryRequest $request): CategoryResource
    {
        $restaurantId = $request->user()->restaurant_id;
        $data         = $request->validated();

        // Compute next position if not provided
        if (! array_key_exists('position', $data)) {
            $maxPosition    = Category::where('restaurant_id', $restaurantId)->max('position');
            $data['position'] = is_null($maxPosition) ? 1 : $maxPosition + 1;
        }

        $category = Category::create([
            ...$data,
            'restaurant_id' => $restaurantId, // force restaurant from auth
        ]);

        return new CategoryResource($category);
    }

    /**
     * Display the specified category (scoped to restaurant).
     */
    public function show(Request $request, Category $category): CategoryResource
    {
        $this->authorizeRestaurant($request, $category);

        $category->loadMissing('restaurant');

        return new CategoryResource($category);
    }

    /**
     * Update the specified category (scoped to restaurant).
     */
    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        $this->authorizeRestaurant($request, $category);

        $data = $request->validated();

        // Make sure restaurant_id can’t be changed through this endpoint
        unset($data['restaurant_id']);

        $category->update($data);

        return new CategoryResource($category->fresh());
    }

    /**
     * Remove the specified category (scoped to restaurant).
     */
    public function destroy(Request $request, Category $category): JsonResponse
    {
        $this->authorizeRestaurant($request, $category);

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully.',
        ]);
    }

    /**
     * Ensure the category belongs to the authenticated user's restaurant.
     */
    protected function authorizeRestaurant(Request $request, Category $category): void
    {
        if ($request->user()->restaurant_id !== $category->restaurant_id) {
            abort(403, 'Forbidden for this restaurant.');
        }
    }
}
