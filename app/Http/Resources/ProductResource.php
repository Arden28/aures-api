<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ProductResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'category_id' => $this->category_id,
            'restaurant'     => $this->when($this->restaurant, function () {
                return [
                    'id'   => $this->restaurant->id,
                    'name' => $this->restaurant->name,

                ];
            }),
            'name'        => $this->name,
            'code'        => $this->code,
            'description' => $this->description,
            'price'       => $this->price,// Automatically generates: http://localhost:8000/storage/products/image.jpg
            'image_path' => $this->image_path
                ? Storage::disk('public')->url($this->image_path)
                : null,
            'is_available'      => $this->is_available,
            'category'    => $this->when($this->category, function () {
                return [
                    'id'   => $this->category->id,
                    'name' => $this->category->name,
                ];
            }),
        ];
    }
}
