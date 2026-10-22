<?php

namespace App\Services;

use App\Models\User;

class MonitorAdminAccess
{
    public function allows(?User $user): bool
    {
        if (! $user || ! $user->hasVerifiedEmail()) {
            return false;
        }

        $emails = array_filter(array_map(
            fn (string $email) => strtolower(trim($email)),
            explode(',', (string) config('monitors.admin_emails', '')),
        ));

        return in_array(strtolower($user->email), $emails, true);
    }
}
