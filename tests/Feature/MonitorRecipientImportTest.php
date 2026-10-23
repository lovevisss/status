<?php

namespace Tests\Feature;

use App\Models\MonitorRecipient;
use Tests\TestCase;

class MonitorRecipientImportTest extends TestCase
{
    public function test_migration_imports_existing_environment_list_once(): void
    {
        config()->set('monitors.alerts.recipients', json_encode([
            ['id' => '000001', 'email' => 'one@example.edu.cn'],
            ['id' => '000002', 'email' => 'two@example.edu.cn'],
        ]));

        $this->artisan('migrate:fresh')->assertExitCode(0);
        $this->assertSame(2, MonitorRecipient::count());
        $this->assertDatabaseHas('monitor_recipients', ['person_id' => '000001', 'email' => 'one@example.edu.cn']);

        config()->set('monitors.alerts.recipients', null);
        $this->artisan('migrate')->assertExitCode(0);
        $this->assertSame(2, MonitorRecipient::count());
    }
}
