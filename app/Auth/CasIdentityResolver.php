<?php

namespace App\Auth;

use App\Models\MonitorAdmin;
use App\Models\User;
use App\Services\MonitorAdminAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use Lovevisss\LaravelZufedfcCas\Contracts\CasIdentityResolver as ResolverContract;
use Lovevisss\LaravelZufedfcCas\Data\CasIdentity;

final class CasIdentityResolver implements ResolverContract
{
    public function __construct(private readonly MonitorAdminAccess $admins) {}

    public function resolve(CasIdentity $identity): ?Authenticatable
    {
        $admin = MonitorAdmin::query()->where('cas_account', $identity->account)->first();

        if ($admin && ! $admin->is_enabled) {
            return null;
        }

        if ($admin) {
            $user = User::query()->firstOrCreate(
                ['cas_account' => $identity->account],
                [
                    'name' => 'CAS '.$identity->account,
                    'email' => 'cas-'.hash('sha256', $identity->account).'@accounts.invalid',
                    'password' => Str::random(64),
                ],
            );

            return $this->admins->allows($user, viaCas: true) ? $user : null;
        }

        $user = User::query()->where('cas_account', $identity->account)->first();

        if (! $user || ! is_string($user->cas_account) || ! hash_equals($user->cas_account, $identity->account)) {
            return null;
        }

        return $this->admins->allows($user, viaCas: true) ? $user : null;
    }
}
