<?php

namespace App\Http\Requests\Transaction;

use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
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

        // who can create payments? cashier/owner/manager, you can tweak
        return in_array($user->role, [
            UserRole::OWNER,
            UserRole::MANAGER,
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
        $methods = array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::cases());

        return [
            'order_id'  => ['required', 'exists:orders,id'],
            'amount'    => ['required', 'numeric', 'min:0.01'],
            'method'    => ['required', 'in:' . implode(',', $methods)],
            'reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}
