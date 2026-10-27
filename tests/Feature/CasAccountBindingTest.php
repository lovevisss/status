<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CasAccountBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_existing_verified_allowlisted_admins_can_bind_an_exact_cas_account(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.edu.cn', 'email_verified_at' => now()]);
        config()->set('monitors.admin_emails', 'admin@example.edu.cn');

        $this->artisan('monitor:admin:bind-cas', ['email' => $admin->email, 'account' => '00001234'])
            ->assertSuccessful();
        $this->assertSame('00001234', $admin->fresh()?->cas_account);

        $this->artisan('monitor:admin:bind-cas', ['email' => $admin->email, 'account' => ' 00001234'])
            ->assertFailed();
        $this->assertSame('00001234', $admin->fresh()?->cas_account);

        $outsider = User::factory()->create(['email' => 'outsider@example.edu.cn', 'email_verified_at' => now()]);
        $this->artisan('monitor:admin:bind-cas', ['email' => $outsider->email, 'account' => '10001'])
            ->assertFailed();
        $this->assertNull($outsider->fresh()?->cas_account);
    }

    public function test_a_cas_account_cannot_be_bound_to_two_users(): void
    {
        config()->set('monitors.admin_emails', 'one@example.edu.cn,two@example.edu.cn');
        $first = User::factory()->create(['email' => 'one@example.edu.cn', 'email_verified_at' => now()]);
        $second = User::factory()->create(['email' => 'two@example.edu.cn', 'email_verified_at' => now()]);

        $this->artisan('monitor:admin:bind-cas', ['email' => $first->email, 'account' => '20230001'])
            ->assertSuccessful();
        $this->artisan('monitor:admin:bind-cas', ['email' => $second->email, 'account' => '20230001'])
            ->assertFailed();
        $this->assertNull($second->fresh()?->cas_account);

        $this->artisan('monitor:admin:unbind-cas', ['email' => $first->email])->assertSuccessful();
        $this->assertNull($first->fresh()?->cas_account);
    }
}
