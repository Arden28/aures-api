<?php

namespace App\Http\Requests\OrderItem;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderItemRequest extends FormRequest
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
            'order_id'    => 'sometimes|exists:orders,id',
            'product_id'  => 'sometimes|exists:products,id',
            'quantity'    => 'sometimes|integer|min:1',
            'unit_price'  => 'sometimes|numeric|min:0',
            'total_price' => 'sometimes|numeric|min:0',
            'status'      => 'sometimes|in:pending,cooking,ready,served,cancelled',
            'notes'       => 'sometimes|nullable|string|max:500',
        ];
    }
}
