<?php

namespace App\Http\Requests\Order;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderStatusRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user) {
            return false;
        }

        return in_array($user->role, [
            UserRole::OWNER,
            UserRole::MANAGER,
            UserRole::WAITER,
            UserRole::KITCHEN,
            UserRole::CASHIER,
        ], true);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $values = array_map(fn (OrderStatus $s) => $s->value, OrderStatus::cases());

        return [
            'status' => ['required', 'in:' . implode(',', $values)],
            'session_id' => ['sometimes', 'nullable', 'integer', 'exists:table_sessions,id'],
            'waiter_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ];
    }
}
