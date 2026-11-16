<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GatewayMonitor;
use App\Services\GatewayManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GatewayController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/Gateways', [
            'gateways' => GatewayMonitor::orderBy('building')->get()
                ->map(fn (GatewayMonitor $item) => $item->only(['id', 'gateway_number', 'ip', 'location', 'building', 'is_enabled'])),
            'message' => $request->session()->get('status'),
        ]);
    }

    public function update(Request $request, GatewayMonitor $gateway, GatewayManager $manager): RedirectResponse
    {
        $data = $request->validate([
            'gateway_number' => ['required', 'regex:/\A[0-9]{1,32}\z/', Rule::unique('gateway_monitors')->ignore($gateway->id)],
            'ip' => ['required', 'ipv4', Rule::unique('gateway_monitors')->ignore($gateway->id)],
            'building' => ['required', 'integer', 'between:1,99'],
            'location' => ['required', 'string', 'max:120'],
        ]);
        if (! str_starts_with($data['ip'], '10.')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['ip' => '仅允许 10.0.0.0/8 校园内网地址。']);
        }
        $manager->update($gateway, $data);

        return redirect()->route('admin.gateways.index')->with('status', '网关已更新。');
    }

    public function disable(GatewayMonitor $gateway, GatewayManager $manager): RedirectResponse
    {
        $manager->setEnabled($gateway, false);

        return redirect()->route('admin.gateways.index')->with('status', '网关监测已停用。');
    }

    public function enable(GatewayMonitor $gateway, GatewayManager $manager): RedirectResponse
    {
        $manager->setEnabled($gateway, true);

        return redirect()->route('admin.gateways.index')->with('status', '网关监测已启用，将开始新的检测周期。');
    }
}
