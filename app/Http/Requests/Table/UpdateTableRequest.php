<?php

namespace App\Http\Requests\Table;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTableRequest extends FormRequest
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
            'name'          => ['sometimes', 'string', 'max:50'],
            'capacity'      => ['sometimes', 'integer', 'min:1'],
            'status'        => ['sometimes', 'in:free,occupied,reserved,needs_cleaning,disabled'],
            'floor_plan_id' => ['sometimes', 'nullable', 'exists:floor_plans,id'],
        ];
    }
}
