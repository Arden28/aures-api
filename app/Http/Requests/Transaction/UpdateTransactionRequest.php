<?php

namespace App\Http\Requests\Transaction;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionRequest extends FormRequest
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
            'order_id'          => 'sometimes|exists:orders,id',
            'processed_by'      => 'sometimes|exists:users,id',
            'amount'            => 'sometimes|numeric|min:0',
            'method'            => 'sometimes|in:cash,card,mobile,other',
            'status'            => 'sometimes|in:unpaid,partial,paid,refunded',
            'reference'         => 'sometimes|nullable|string|max:255',
            'paid_at'           => 'sometimes|nullable|date',
        ];
    }
}
