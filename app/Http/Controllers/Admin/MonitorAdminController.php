<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MonitorAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MonitorAdminController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/Administrators', [
            'administrators' => MonitorAdmin::query()->orderBy('id')->get(['id', 'cas_account', 'is_enabled']),
            'currentCasAccount' => $request->session()->get('auth.via_cas') === true
                ? $request->user()?->cas_account
                : null,
            'message' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cas_account' => ['required', 'string', 'max:120', 'regex:/\A[A-Za-z0-9_-]+\z/', Rule::unique('monitor_admins', 'cas_account')],
        ]);

        MonitorAdmin::query()->create([
            'cas_account' => $data['cas_account'],
            'is_enabled' => true,
        ]);

        return redirect()->route('admin.administrators.index')->with('status', '管理员已添加，可使用该学工号通过 CAS 登录。');
    }

    public function disable(Request $request, MonitorAdmin $admin): RedirectResponse
    {
        if ($request->user()?->cas_account === $admin->cas_account) {
            throw ValidationException::withMessages(['administrator' => '不能停用当前登录的管理员。']);
        }

        DB::transaction(function () use ($admin): void {
            $active = MonitorAdmin::query()->where('is_enabled', true)->lockForUpdate()->get(['id']);
            if ($active->count() <= 1 && $active->contains('id', $admin->id)) {
                throw ValidationException::withMessages(['administrator' => '至少需要保留一名已启用的 CAS 管理员。']);
            }

            $admin->update(['is_enabled' => false]);
        });

        return redirect()->route('admin.administrators.index')->with('status', '管理员已停用，现有会话下次访问后台时将失去权限。');
    }

    public function enable(MonitorAdmin $admin): RedirectResponse
    {
        $admin->update(['is_enabled' => true]);

        return redirect()->route('admin.administrators.index')->with('status', '管理员已重新启用。');
    }
}
