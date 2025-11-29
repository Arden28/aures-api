<?php

namespace App\Http\Requests\Order;

use App\Enums\OrderItemStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class UpdateOrderItemStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        return in_array($user->role, [
            UserRole::OWNER,
            UserRole::MANAGER,
            UserRole::KITCHEN,
            UserRole::WAITER,
        ], true);
    }

    public function rules(): array
    {
        $values = array_map(fn (OrderItemStatus $s) => $s->value, OrderItemStatus::cases());

        Log::debug('OrderItem Update Validation', [
            'allowed_statuses' => $values,
            'request_status'   => request('status')
        ]);

        return [
            'status' => ['required', 'in:' . implode(',', $values)],
        ];
    }
}
