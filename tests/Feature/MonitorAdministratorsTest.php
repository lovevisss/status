<?php

namespace Tests\Feature;

use App\Auth\CasIdentityResolver;
use App\Models\MonitorAdmin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Lovevisss\LaravelZufedfcCas\Data\CasIdentity;
use Lovevisss\LaravelZufedfcCas\Testing\CasResponse;
use Tests\TestCase;

class MonitorAdministratorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('app.url', 'https://status.zufedfc.edu.cn');
        config()->set('cas.service_url', 'https://status.zufedfc.edu.cn/auth/cas/callback');
        config()->set('cas.base_url', 'https://cas.paas.zufedfc.edu.cn/cas');
        config()->set('cas.enabled', true);
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => '443']);
    }

    public function test_initial_cas_account_can_log_in_without_a_preexisting_local_user(): void
    {
        $this->assertDatabaseHas('monitor_admins', ['cas_account' => '20150301', 'is_enabled' => true]);
        Http::fake(['https://cas.paas.zufedfc.edu.cn/cas/serviceValidate*' => Http::response(CasResponse::success('20150301'))]);

        $this->get('https://status.zufedfc.edu.cn/auth/cas/login');
        $this->get('https://status.zufedfc.edu.cn/auth/cas/callback?ticket=ST-initial-admin')
            ->assertRedirect('/admin/monitors');

        $user = User::query()->where('cas_account', '20150301')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->email_verified_at);
        $this->assertStringEndsWith('@accounts.invalid', $user->email);
        $this->get('/admin/monitors')->assertOk();
        $this->get('/admin/administrators')->assertInertia(fn (Assert $page) => $page
            ->component('admin/Administrators')
            ->where('currentCasAccount', '20150301')
            ->etc());
    }

    public function test_only_authorized_administrators_can_manage_cas_accounts(): void
    {
        $this->get('/admin/administrators')->assertRedirect('/login');

        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin/administrators')->assertForbidden();
        $this->post('/admin/administrators', ['cas_account' => '20230001'])->assertForbidden();

        $user->forceFill(['cas_account' => '20150301'])->save();
        $this->withSession(['auth.via_cas' => true])->get('/admin/administrators')->assertOk();
    }

    public function test_admin_can_add_and_disable_another_cas_account(): void
    {
        $current = User::factory()->create(['email_verified_at' => null]);
        $current->forceFill(['cas_account' => '20150301'])->save();
        $this->actingAs($current)->withSession(['auth.via_cas' => true]);

        $this->post('/admin/administrators', ['cas_account' => '20230001'])
            ->assertRedirect('/admin/administrators');
        $this->assertDatabaseHas('monitor_admins', ['cas_account' => '20230001', 'is_enabled' => true]);

        $this->post('/admin/administrators', ['cas_account' => '20230001'])
            ->assertSessionHasErrors('cas_account');
        $this->post('/admin/administrators', ['cas_account' => '2023 0002'])
            ->assertSessionHasErrors('cas_account');

        $added = MonitorAdmin::query()->where('cas_account', '20230001')->firstOrFail();
        $this->post("/admin/administrators/{$added->id}/disable")->assertRedirect();
        $this->assertFalse($added->fresh()->is_enabled);
        $this->assertNull(app(CasIdentityResolver::class)->resolve(new CasIdentity('20230001', '20230001')));
        $this->post("/admin/administrators/{$added->id}/enable")->assertRedirect();
        $this->assertTrue($added->fresh()->is_enabled);
        $this->assertNotNull(app(CasIdentityResolver::class)->resolve(new CasIdentity('20230001', '20230001')));
    }

    public function test_current_or_last_cas_admin_cannot_be_disabled(): void
    {
        $current = User::factory()->create(['email_verified_at' => null]);
        $current->forceFill(['cas_account' => '20150301'])->save();
        $this->actingAs($current)->withSession(['auth.via_cas' => true]);
        $initial = MonitorAdmin::query()->firstOrFail();

        $this->post("/admin/administrators/{$initial->id}/disable")
            ->assertSessionHasErrors('administrator');
        $this->assertTrue($initial->fresh()->is_enabled);
    }

    public function test_legacy_admin_cannot_disable_the_last_cas_admin(): void
    {
        config()->set('monitors.admin_emails', 'legacy@example.edu.cn');
        $legacy = User::factory()->create(['email' => 'legacy@example.edu.cn']);
        $this->actingAs($legacy);
        $initial = MonitorAdmin::query()->firstOrFail();

        $this->post("/admin/administrators/{$initial->id}/disable")
            ->assertSessionHasErrors('administrator');
        $this->assertTrue($initial->fresh()->is_enabled);
    }

    public function test_cas_roster_does_not_grant_access_to_a_local_password_session(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);
        $user->forceFill(['cas_account' => '20150301'])->save();

        $this->actingAs($user)->get('/admin/monitors')->assertRedirect('/email/verify');

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->get('/admin/monitors')->assertForbidden();
    }

    public function test_disabling_an_admin_revokes_an_existing_cas_session(): void
    {
        $initial = MonitorAdmin::query()->firstOrFail();
        $other = MonitorAdmin::query()->create(['cas_account' => '20230001', 'is_enabled' => true]);
        $user = app(CasIdentityResolver::class)->resolve(new CasIdentity('20230001', '20230001'));
        $this->actingAs($user)->withSession(['auth.via_cas' => true])->get('/admin/monitors')->assertOk();

        $other->update(['is_enabled' => false]);
        $this->get('/admin/monitors')->assertForbidden();
        $this->assertTrue($initial->is_enabled);
    }
}
