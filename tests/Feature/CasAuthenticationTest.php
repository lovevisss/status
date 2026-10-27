<?php

namespace Tests\Feature;

use App\Auth\CasIdentityResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Lovevisss\LaravelZufedfcCas\Data\CasIdentity;
use Lovevisss\LaravelZufedfcCas\Testing\CasResponse;
use Tests\TestCase;

class CasAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config()->set('app.url', 'https://status.zufedfc.edu.cn');
        config()->set('cas.service_url', 'https://status.zufedfc.edu.cn/auth/cas/callback');
        config()->set('cas.base_url', 'https://cas.paas.zufedfc.edu.cn/cas');
        config()->set('cas.post_logout_url', 'https://status.zufedfc.edu.cn/');
        config()->set('cas.slo_cache_store', 'array');
        config()->set('monitors.admin_emails', 'admin@example.edu.cn');
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => '443']);
    }

    private function admin(): User
    {
        $user = User::factory()->create(['email' => 'admin@example.edu.cn', 'email_verified_at' => now()]);
        $user->forceFill(['cas_account' => '00001234'])->save();

        return $user;
    }

    public function test_cas_entry_is_unavailable_until_https_callback_is_configured_and_enabled(): void
    {
        $this->get('/auth/cas/login')->assertNotFound();
        $this->get('/auth/cas/callback?ticket=ST-test')->assertNotFound();

        config()->set('cas.enabled', true);
        config()->set('cas.service_url', 'http://status.zufedfc.edu.cn/auth/cas/callback');
        $this->get('/auth/cas/login')->assertNotFound();

        config()->set('cas.service_url', 'https://status.zufedfc.edu.cn/auth/cas/callback');
        $this->withServerVariables(['HTTPS' => 'off'])->get('/auth/cas/login')->assertNotFound();
    }

    public function test_login_redirect_uses_the_exact_service_and_ignores_external_return_targets(): void
    {
        config()->set('cas.enabled', true);

        $response = $this->get('https://status.zufedfc.edu.cn/auth/cas/login?returnUrl=https://evil.example/');
        $response->assertRedirect('https://cas.paas.zufedfc.edu.cn/cas/login?service=https%3A%2F%2Fstatus.zufedfc.edu.cn%2Fauth%2Fcas%2Fcallback');
        $response->assertSessionHas('cas.return_url', '/admin/monitors');
    }

    public function test_login_page_only_offers_cas_on_https_when_ready(): void
    {
        config()->set('cas.enabled', true);

        $this->get('http://status.zufedfc.edu.cn/login')
            ->assertInertia(fn (Assert $page) => $page->component('auth/Login')->where('canUseCas', false)->etc());
        $this->get('https://status.zufedfc.edu.cn/login')
            ->assertInertia(fn (Assert $page) => $page->component('auth/Login')->where('canUseCas', true)->etc());
    }

    public function test_valid_cas_ticket_logs_in_the_exact_allowlisted_account_and_regenerates_session(): void
    {
        config()->set('cas.enabled', true);
        $admin = $this->admin();
        Http::fake(['https://cas.paas.zufedfc.edu.cn/cas/serviceValidate*' => Http::response(CasResponse::success('00001234'))]);

        $this->get('https://status.zufedfc.edu.cn/auth/cas/login');
        $previousSessionId = session()->getId();
        $this->get('https://status.zufedfc.edu.cn/auth/cas/callback?ticket=ST-test-valid')->assertRedirect('/admin/monitors');

        $this->assertAuthenticatedAs($admin);
        $this->assertNotSame($previousSessionId, session()->getId());
        $this->assertTrue(session('auth.via_cas'));
        Http::assertSent(fn ($request) => $request['service'] === 'https://status.zufedfc.edu.cn/auth/cas/callback'
            && $request['ticket'] === 'ST-test-valid');
        $this->get('/admin/monitors')->assertOk();
    }

    public function test_unbound_unverified_or_unlisted_people_are_denied(): void
    {
        $admin = $this->admin();
        $resolver = app(CasIdentityResolver::class);

        $this->assertSame($admin->id, $resolver->resolve(new CasIdentity('00001234', '00001234'))?->getAuthIdentifier());
        $this->assertNull($resolver->resolve(new CasIdentity('not-bound', 'not-bound')));
        $this->assertNull($resolver->resolve(new CasIdentity('00001235', '00001235')));

        $admin->forceFill(['cas_account' => 'Ab1234'])->save();
        $this->assertNull($resolver->resolve(new CasIdentity('ab1234', 'ab1234')));
        $admin->forceFill(['cas_account' => '00001234'])->save();

        $admin->forceFill(['email_verified_at' => null])->save();
        $this->assertNull($resolver->resolve(new CasIdentity('00001234', '00001234')));

        $admin->forceFill(['email_verified_at' => now()])->save();
        config()->set('monitors.admin_emails', 'someone-else@example.edu.cn');
        $this->assertNull($resolver->resolve(new CasIdentity('00001234', '00001234')));
    }

    public function test_failed_ticket_or_cas_connection_returns_to_login_without_authentication(): void
    {
        config()->set('cas.enabled', true);
        Http::fake(['*' => Http::response(CasResponse::failure())]);

        $this->get('https://status.zufedfc.edu.cn/auth/cas/callback?ticket=ST-test-invalid')->assertRedirect('/login');
        $this->assertGuest();

        Http::fake(fn () => throw new ConnectionException('network unavailable'));
        $this->get('https://status.zufedfc.edu.cn/auth/cas/callback?ticket=ST-test-timeout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_unbound_cas_identity_reaches_a_public_no_access_page(): void
    {
        config()->set('cas.enabled', true);
        Http::fake(['*' => Http::response(CasResponse::success('not-bound'))]);

        $this->get('https://status.zufedfc.edu.cn/auth/cas/callback?ticket=ST-test-unbound')->assertRedirect('/access-denied');
        $this->get('/access-denied')->assertForbidden()->assertSee('无法进入管理后台');
        $this->assertGuest();
    }

    public function test_unsafe_or_unsuccessful_cas_responses_do_not_authenticate(): void
    {
        config()->set('cas.enabled', true);
        $this->admin();

        Http::fake(['*' => Http::response('<!DOCTYPE bad><cas:serviceResponse/>')]);
        $this->get('https://status.zufedfc.edu.cn/auth/cas/callback?ticket=ST-test-dtd')->assertRedirect('/login');
        $this->assertGuest();

        Http::fake(['*' => Http::response('unavailable', 503)]);
        $this->get('https://status.zufedfc.edu.cn/auth/cas/callback?ticket=ST-test-503')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_standard_back_channel_and_school_jsonp_logout_end_sessions(): void
    {
        config()->set('cas.enabled', true);
        $admin = $this->admin();
        Http::fake(['*' => Http::response(CasResponse::success('00001234'))]);
        $this->get('https://status.zufedfc.edu.cn/auth/cas/callback?ticket=ST-slo-test')->assertRedirect('/admin/monitors');
        $this->assertTrue(Auth::check());

        $xml = '<samlp:LogoutRequest xmlns:samlp="urn:oasis:names:tc:SAML:2.0:protocol"><samlp:SessionIndex>ST-slo-test</samlp:SessionIndex></samlp:LogoutRequest>';
        $sessionId = session()->getId();
        $this->withCookie(config('session.cookie'), 'separate-cas-server-request')
            ->post('/auth/cas/slo', ['logoutRequest' => $xml])->assertOk()->assertJsonPath('data.success', true);
        $this->assertDatabaseMissing('sessions', ['id' => $sessionId]);

        $this->actingAs($admin);
        $this->get('/auth/cas/slo?callback=school.logout')
            ->assertOk()
            ->assertSee('school.logout(', false);
        $this->assertGuest();
    }

    public function test_local_cas_logout_invalidates_session_and_redirects_to_cas(): void
    {
        config()->set('cas.enabled', true);
        $this->admin();
        Http::fake(['*' => Http::response(CasResponse::success('00001234'))]);
        $this->get('https://status.zufedfc.edu.cn/auth/cas/callback?ticket=ST-logout-test');

        $this->post('/auth/cas/logout')
            ->assertRedirect('https://cas.paas.zufedfc.edu.cn/cas/logout?service=https%3A%2F%2Fstatus.zufedfc.edu.cn%2F');
        $this->assertGuest();
    }

    public function test_inertia_cas_logout_uses_full_page_redirect_to_cas(): void
    {
        config()->set('cas.enabled', true);
        $this->admin();
        Http::fake(['*' => Http::response(CasResponse::success('00001234'))]);
        $this->get('https://status.zufedfc.edu.cn/auth/cas/callback?ticket=ST-inertia-logout-test');

        $this->withHeader('X-Inertia', 'true')->post('/auth/cas/logout')
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://cas.paas.zufedfc.edu.cn/cas/logout?service=https%3A%2F%2Fstatus.zufedfc.edu.cn%2F');
        $this->assertGuest();
    }
}
