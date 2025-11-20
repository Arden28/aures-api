<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FloorPlanController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RestaurantController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\TableController;
use App\Http\Controllers\Api\TransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| This file defines all API endpoints for the Restaurant Management System
| (RMS) backend. The API is designed to be consumed by:
|
| - Owner / Manager dashboards (Backoffice)
| - Waiter / Cashier / Kitchen apps (POS & KDS)
| - Client apps (QR-code digital menu & order tracking)
|
| All routes are prefixed with /api and versioned under /v1.
| Authentication is handled via Laravel Sanctum.
|
*/

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // Public auth routes
    Route::prefix('auth')->group(function () {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login',    [AuthController::class, 'login']);
    });


    /*
    |--------------------------------------------------------------------------
    | Public / Guest Endpoints (No Auth)
    |--------------------------------------------------------------------------
    |
    | Endpoints that do not require authentication.
    | Typical use cases:
    | - Public menu (via QR code)
    | - Order tracking by token
    |
    | NOTE: These are placeholders for now. You can wire them to dedicated
    | controllers later (e.g., PublicMenuController, PublicOrderController).
    |
    */

    // Example:
    // Route::get('public/menu/{restaurant}', [PublicMenuController::class, 'show']);
    // Route::get('public/orders/{token}', [PublicOrderController::class, 'track']);

    /*
    |--------------------------------------------------------------------------
    | Authenticated Restaurant API
    |--------------------------------------------------------------------------
    |
    | All routes below require an authenticated user (Sanctum).
    | Each request is implicitly scoped to the user's restaurant_id.
    |
    */

    Route::middleware('auth:sanctum')->group(function () {

        /*
        |--------------------------------------------------------------------------
        | Auth / Profile
        |--------------------------------------------------------------------------
        */
        Route::prefix('auth')->group(function () {
            Route::get('me',               [AuthController::class, 'me']);
            Route::post('logout',          [AuthController::class, 'logout']);
            Route::post('logout-all',      [AuthController::class, 'logoutAll']);
            Route::put('profile',          [AuthController::class, 'updateProfile']);
            Route::put('password',         [AuthController::class, 'updatePassword']);
            Route::post('refresh-token',   [AuthController::class, 'refreshToken']); // optional
        });

        /*
        |--------------------------------------------------------------------------
        | Dashboard
        |--------------------------------------------------------------------------
        |
        | Dashboard endpoints provide high-level business insights such as
        | recent orders, revenue summaries, and activity metrics.
        | All dashboard data is scoped to the authenticated user's restaurant.
        |
        */
        Route::get('dashboard/overview', [DashboardController::class, 'overview']);

        /*
        |--------------------------------------------------------------------------
        | Categories
        |--------------------------------------------------------------------------
        |
        | Categories group menu items (e.g. "Pizzas", "Drinks").
        | All categories are scoped to the authenticated user's restaurant.
        |
        */

        Route::apiResource('categories', CategoryController::class)
            ->names('categories');

        /*
        |--------------------------------------------------------------------------
        | Products (Menu Items)
        |--------------------------------------------------------------------------
        |
        | Products represent items on the menu:
        | - Belong to a Category
        | - Belong to a Restaurant
        | - Can be filtered by category or availability
        |
        */

        Route::apiResource('products', ProductController::class)
            ->names('products');

        /*
        |--------------------------------------------------------------------------
        | Floor Plans
        |--------------------------------------------------------------------------
        |
        | Floor plans represent dining areas (e.g. "Terrace", "Main Hall").
        | They have many tables.
        |
        */

        Route::apiResource('floor-plans', FloorPlanController::class)
            ->names('floor-plans');

        /*
        |--------------------------------------------------------------------------
        | Tables
        |--------------------------------------------------------------------------
        |
        | Tables represent physical tables:
        | - Belong to a restaurant
        | - Optionally linked to a floor plan
        | - Have a state machine for status:
        |   FREE → RESERVED → OCCUPIED → NEEDS_CLEANING → FREE
        |
        */

        Route::apiResource('tables', TableController::class)
            ->names('tables');

        // Table status state machine endpoint
        Route::patch('tables/{table}/status', [TableController::class, 'updateStatus'])
            ->name('tables.update-status');

        /*
        |--------------------------------------------------------------------------
        | Clients
        |--------------------------------------------------------------------------
        |
        | Clients represent guests/customers tied to a restaurant:
        | - Can be created via POS or API
        | - Useful for loyalty, history, etc.
        |
        */

        Route::apiResource('clients', ClientController::class)
            ->names('clients');

        /*
        |--------------------------------------------------------------------------
        | Orders & Order Items
        |--------------------------------------------------------------------------
        |
        | Orders:
        | - Linked to a table and optionally a client
        | - Have order items
        | - Have a status state machine:
        |   PENDING → IN_PROGRESS → READY → SERVED → COMPLETED / CANCELLED
        |
        | Order Items:
        | - Each item refers to a Product, quantity, and status:
        |   PENDING → COOKING → READY → SERVED / CANCELLED
        |
        */

        Route::apiResource('orders', OrderController::class)
            ->names('orders');

        // Order status state machine endpoint
        Route::patch('orders/{order}/status', [OrderController::class, 'updateStatus'])
            ->name('orders.update-status');

        // Order item status state machine endpoint (KDS core)
        Route::patch('order-items/{item}/status', [OrderController::class, 'updateItemStatus'])
            ->name('order-items.update-status');

        /*
        |--------------------------------------------------------------------------
        | Transactions / Payments
        |--------------------------------------------------------------------------
        |
        | Transactions record payments against orders:
        | - Each transaction has amount, method, status, reference
        | - After each transaction, the order's paid_amount and payment_status
        |   are recalculated.
        |
        */

        Route::apiResource('transactions', TransactionController::class)
            ->only(['index', 'store', 'show'])
            ->names('transactions');

        /*
        |--------------------------------------------------------------------------
        | Staff Management
        |--------------------------------------------------------------------------
        |
        | Staff endpoints manage all restaurant personnel:
        | - Each staff member has name, email, role, and restaurant assignment
        | - Roles define permissions (owner, manager, waiter, kitchen, cashier)
        | - Email uniqueness and access are scoped to the restaurant
        |
        */

        Route::apiResource('staff', StaffController::class)
            ->parameter('staff', 'staff') // binding to User model
                ->names('staff');

        /*
        |--------------------------------------------------------------------------
        | Restaurant Settings
        |--------------------------------------------------------------------------
        |
        | Restaurant settings define the core configuration of the restaurant such as:
        | - General information (name, logo, contact details)
        | - Operational preferences (currency, tax rates, service charges)
        | - Display and menu settings
        |
        | All settings are scoped to the authenticated user's restaurant.
        |
        */

        Route::get('restaurant', [RestaurantController::class, 'show'])
            ->name('restaurant.show');

        Route::put('restaurant', [RestaurantController::class, 'update'])
            ->name('restaurant.update');

        // If later you want to support reversing / refunding transactions,
        // you can add PATCH/DELETE endpoints here.
    });
});
