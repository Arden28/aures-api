<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderRequest extends FormRequest
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
            'table_id'        => 'sometimes|nullable|exists:tables,id',
            'client_id'       => 'sometimes|nullable|exists:clients,id',
            'source'          => 'sometimes|string|max:255',
            'discount_amount' => 'sometimes|numeric|min:0',
            'opened_at'       => 'sometimes|nullable|date',
            'closed_at'       => 'sometimes|nullable|date|after_or_equal:opened_at',
        ];
    }
}
