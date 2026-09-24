<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Media> */
class MediaFactory extends Factory
{
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return ['uuid' => $uuid, 'uploaded_by' => User::factory(), 'collection' => 'default', 'disk' => 'local',
            'path' => 'media/'.$uuid.'.txt', 'filename' => 'example.txt', 'mime_type' => 'text/plain', 'size' => 10];
    }
}
