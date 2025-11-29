<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductRequest extends FormRequest
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
            'category_id'       => ['sometimes', 'exists:categories,id'],
            'name'              => ['sometimes', 'string', 'max:255'],
            'description'       => ['sometimes', 'nullable', 'string'],
            'price'             => ['sometimes', 'numeric', 'min:0'],
            'is_available'      => ['sometimes', 'boolean'],
            'image'             => ['sometimes', 'nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
        ];
    }
}
