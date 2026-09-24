<?php

namespace App\Http\Requests\Api;

use App\Services\MediaService;
use Illuminate\Foundation\Http\FormRequest;

class StoreMediaRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('collection')) {
            $this->merge(['collection' => 'default']);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('media.upload') ?? false;
    }

    public function rules(): array
    {
        return MediaService::rules();
    }
}
