<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_monitors', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->string('gateway_number', 32)->unique();
            $table->string('ip', 45)->unique();
            $table->string('location', 120);
            $table->unsignedSmallInteger('building');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        for ($building = 1; $building <= 16; $building++) {
            $number = (string) (202500829 + $building);
            DB::table('gateway_monitors')->insert([
                'key' => 'gateway-'.$number,
                'gateway_number' => $number,
                'ip' => $building === 16 ? '10.100.36.3' : '10.4.90.'.(10 + $building),
                'location' => $building.'号楼3楼电表间',
                'building' => $building,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('gateway_recipients', function (Blueprint $table) {
            $table->id();
            $table->string('person_id', 120)->unique();
            $table->string('email', 255)->nullable();
            $table->string('mobile', 20)->nullable();
            $table->boolean('notify_email')->default(false);
            $table->boolean('notify_sms')->default(false);
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        Schema::table('monitor_alerts', function (Blueprint $table) {
            $table->string('channel', 10)->default('email');
            $table->string('recipient_mobile', 20)->nullable();
        });
        Schema::table('monitor_incidents', function (Blueprint $table) {
            $table->string('target_name', 160)->nullable();
            $table->string('target_address', 255)->nullable();
        });
        Schema::table('monitor_alerts', function (Blueprint $table) {
            $table->dropUnique('monitor_alerts_event_recipient_unique');
            $table->unique(['monitor_incident_id', 'kind', 'recipient_id', 'channel'], 'monitor_alerts_event_recipient_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_incidents', function (Blueprint $table) {
            $table->dropColumn(['target_name', 'target_address']);
        });
        Schema::table('monitor_alerts', function (Blueprint $table) {
            $table->dropUnique('monitor_alerts_event_recipient_channel_unique');
            $table->unique(['monitor_incident_id', 'kind', 'recipient_id'], 'monitor_alerts_event_recipient_unique');
        });
        Schema::table('monitor_alerts', function (Blueprint $table) {
            $table->dropColumn(['channel', 'recipient_mobile']);
        });
        Schema::dropIfExists('gateway_recipients');
        Schema::dropIfExists('gateway_monitors');
    }
};
