<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;

class MediaPolicy
{
    public function view(User $user, Media $media): bool
    {
        return $user->hasPermission('media.view') && ($user->hasPermission('media.manage') || $media->uploaded_by === $user->id);
    }

    public function delete(User $user, Media $media): bool
    {
        return $user->hasPermission('media.delete') && ($user->hasPermission('media.manage') || $media->uploaded_by === $user->id);
    }
}
