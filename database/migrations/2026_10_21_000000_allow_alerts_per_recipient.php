<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitor_alerts', function (Blueprint $table) {
            $table->index('monitor_incident_id', 'monitor_alerts_incident_id_index');
        });

        Schema::table('monitor_alerts', function (Blueprint $table) {
            $table->dropUnique('monitor_alerts_monitor_incident_id_kind_unique');
            $table->unique(['monitor_incident_id', 'kind', 'recipient_id'], 'monitor_alerts_event_recipient_unique');
        });
    }

    public function down(): void
    {
        Schema::table('monitor_alerts', function (Blueprint $table) {
            $table->dropUnique('monitor_alerts_event_recipient_unique');
            $table->unique(['monitor_incident_id', 'kind']);
        });

        Schema::table('monitor_alerts', function (Blueprint $table) {
            $table->dropIndex('monitor_alerts_incident_id_index');
        });
    }
};
