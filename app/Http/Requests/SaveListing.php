<?php

namespace App\Http\Requests;

use App\Support\LocationScope;
use App\Support\Marketplace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class SaveListing extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:120'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['required', 'string', 'exists:listing_categories,slug'],
            'condition' => ['required', Rule::in(array_keys(Marketplace::CONDITIONS))],
            'price' => ['required', 'numeric', 'decimal:0,2', 'between:0,9999999.99'],
            'negotiable' => ['nullable', 'boolean'],
            'location_id' => ['nullable', 'integer'],
            'details' => ['sometimes', 'array', 'max:10'],
            'photos' => ['sometimes', 'array', 'list', 'max:5'],
            'photos.*' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'],
        ];
        foreach (Marketplace::FIELDS as $fields) {
            foreach ($fields as $field => $label) {
                $rules["details.$field"] = match ($field) {
                    'year' => ['nullable', 'integer', 'between:1880,'.(now()->year + 2)],
                    'mileage', 'hours' => ['nullable', 'integer', 'min:0', 'max:9999999'],
                    'slides' => ['nullable', 'integer', 'between:0,30'],
                    'length' => ['nullable', 'numeric', 'between:1,200'],
                    default => ['nullable', 'string', 'max:240'],
                };
            }
        }

        // Extra visible groups have no impact on the chosen category.
        return $rules;
    }

    public function attributesForPersistence(): array
    {
        $data = $this->validated();
        $site = $this->attributes->get('site');
        $data['location_id'] = LocationScope::forPublication($site, isset($data['location_id']) ? (int) $data['location_id'] : null);
        $data['price_cents'] = (int) round((float) $data['price'] * 100);
        $data['negotiable'] = (bool) ($data['negotiable'] ?? false);
        $fields = Marketplace::FIELDS[$data['category']] ?? [];
        $data['attributes'] = array_filter(Arr::only($data['details'] ?? [], array_keys($fields)), fn ($value) => $value !== null);
        unset($data['price'], $data['details'], $data['photos']);

        return $data;
    }
}
