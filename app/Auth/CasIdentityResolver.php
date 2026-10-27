<?php

namespace App\Auth;

use App\Models\User;
use App\Services\MonitorAdminAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Lovevisss\LaravelZufedfcCas\Contracts\CasIdentityResolver as ResolverContract;
use Lovevisss\LaravelZufedfcCas\Data\CasIdentity;

final class CasIdentityResolver implements ResolverContract
{
    public function __construct(private readonly MonitorAdminAccess $admins) {}

    public function resolve(CasIdentity $identity): ?Authenticatable
    {
        $user = User::query()->where('cas_account', $identity->account)->first();

        if (! $user || ! is_string($user->cas_account) || ! hash_equals($user->cas_account, $identity->account)) {
            return null;
        }

        return $this->admins->allows($user) ? $user : null;
    }
}
