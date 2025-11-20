<?php

namespace App\Http\Requests\Staff;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Allow all for now — controller/service will handle real authorization
        return true;
    }

    /**
     * Get the validation rules for creating a staff member.
     */
    public function rules(): array
    {
        return [
            // Basic staff details
            'name'  => ['required', 'string', 'max:255'],

            /*
             * Email must be unique per restaurant.
             * Example:
             * unique:users,email,NULL,id,restaurant_id,5
             */
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')
                    ->where('restaurant_id', $this->user()->restaurant_id),
            ],

            // Role validation using enum
            'role' => [
                'required',
                Rule::enum(UserRole::class),
            ],

            // Default password when creating
            'password' => ['required', 'string', 'min:6'],
        ];
    }
}
