<?php

namespace App\Services;

use App\Models\MonitorAdmin;
use App\Models\User;

class MonitorAdminAccess
{
    public function allows(?User $user, bool $viaCas = false): bool
    {
        if (! $user) {
            return false;
        }

        $admin = $user->cas_account
            ? MonitorAdmin::query()->where('cas_account', $user->cas_account)->first()
            : null;

        if ($admin && ! $admin->is_enabled) {
            return false;
        }

        if ($admin && $viaCas) {
            return true;
        }

        if (! $user->hasVerifiedEmail()) {
            return false;
        }

        $emails = array_filter(array_map(
            fn (string $email) => strtolower(trim($email)),
            explode(',', (string) config('monitors.admin_emails', '')),
        ));

        return in_array(strtolower($user->email), $emails, true);
    }
}
