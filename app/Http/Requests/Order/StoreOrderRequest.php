<?php

namespace App\Http\Requests\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'table_id'        => 'nullable|exists:tables,id',
            'client_id'       => 'nullable|exists:clients,id',
            'waiter_id'       => 'nullable|exists:users,id',
            'source'          => 'nullable|string|max:255',
            'discount_amount' => 'nullable|numeric|min:0',


            // Items block
            'items'                  => ['required', 'array', 'min:1'],
            'items.*.product_id'     => ['required', 'exists:products,id'],
            'items.*.quantity'       => ['required', 'integer', 'min:1'],
            'items.*.notes'          => ['nullable', 'string', 'max:500'],
        ];
    }
}
