<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class ApplicationSetting extends Model
{
    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    /** @return array{app_name: string, support_email: string, registration_enabled: bool} */
    public static function values(): array
    {
        return array_replace(config('settings'), static::whereIn('key', array_keys(config('settings')))->get()->pluck('value', 'key')->all());
    }
}
