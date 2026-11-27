<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_incidents', function (Blueprint $table) {
            $table->timestamp('notification_decided_at')->nullable()->index();
        });
        DB::table('monitor_incidents')->where('service_key', 'like', 'gateway-%')
            ->whereNotNull('confirmed_at')->update(['notification_decided_at' => now()]);
        Schema::create('gateway_alert_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_incident_id')->unique()->constrained('monitor_incidents');
            $table->timestamp('recovery_queued_at')->nullable();
            $table->timestamps();
        });
        Schema::create('gateway_alert_batch_members', function (Blueprint $table) {
            $table->foreignId('gateway_alert_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('monitor_incident_id')->unique()->constrained('monitor_incidents');
            $table->primary(['gateway_alert_batch_id', 'monitor_incident_id'], 'gateway_batch_member_pk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_alert_batch_members');
        Schema::dropIfExists('gateway_alert_batches');
        Schema::table('monitor_incidents', fn (Blueprint $table) => $table->dropColumn('notification_decided_at'));
    }
};
