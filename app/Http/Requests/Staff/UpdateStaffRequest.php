<?php

namespace App\Http\Requests\Staff;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Allow all for now — controller/service will handle permissions
        return true;
    }

    /**
     * Get validation rules for updating a staff member.
     */
    public function rules(): array
    {
        $staffId = $this->route('staff');  // If your route uses /staff/{staff}

        return [
            'name'  => ['required', 'string', 'max:255'],

            /*
             * Email unique per restaurant but ignore current staff record.
             */
            'email' => [
                'required',
                'email',
                Rule::unique('users', 'email')
                    ->ignore($staffId)
                    ->where('restaurant_id', $this->user()->restaurant_id),
            ],

            'role' => [
                'required',
                Rule::enum(UserRole::class),
            ],

            // Password is optional when updating
            'password' => ['nullable', 'string', 'min:6'],
        ];
    }
}
