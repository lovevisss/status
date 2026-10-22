<?php

use App\Services\MonitorTargetGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_services', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('name', 120);
            $table->string('description', 255)->default('');
            $table->string('url', 2048);
            $table->char('active_url_hash', 64)->nullable()->unique();
            $table->boolean('is_enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });

        foreach (config('monitors.services', []) as $order => $service) {
            $url = app(MonitorTargetGuard::class)->normalize($service['url']);
            DB::table('monitor_services')->insert([
                'key' => $service['key'],
                'name' => $service['name'],
                'description' => $service['description'],
                'url' => $url,
                'active_url_hash' => hash('sha256', $url),
                'is_enabled' => true,
                'sort_order' => $order,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('monitor_incidents', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable()->index();
            $table->string('closed_reason', 32)->nullable();
        });

        DB::table('monitor_incidents')->whereNotNull('recovered_at')->update([
            'closed_at' => DB::raw('recovered_at'),
            'closed_reason' => 'recovered',
        ]);
    }

    public function down(): void
    {
        Schema::table('monitor_incidents', function (Blueprint $table) {
            $table->dropColumn(['closed_at', 'closed_reason']);
        });
        Schema::dropIfExists('monitor_services');
    }
};
