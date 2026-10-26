<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_maintenance_windows', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->text('description');
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->index();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('monitor_maintenance_services', function (Blueprint $table) {
            $table->foreignId('monitor_maintenance_window_id');
            $table->foreignId('monitor_service_id');
            $table->foreign('monitor_maintenance_window_id', 'mm_services_window_fk')->references('id')->on('monitor_maintenance_windows')->cascadeOnDelete();
            $table->foreign('monitor_service_id', 'mm_services_service_fk')->references('id')->on('monitor_services')->cascadeOnDelete();
            $table->primary(['monitor_maintenance_window_id', 'monitor_service_id'], 'monitor_maintenance_service_primary');
            $table->index('monitor_service_id');
        });

        Schema::table('monitor_checks', function (Blueprint $table) {
            $table->boolean('is_maintenance')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('monitor_checks', fn (Blueprint $table) => $table->dropColumn('is_maintenance'));
        Schema::dropIfExists('monitor_maintenance_services');
        Schema::dropIfExists('monitor_maintenance_windows');
    }
};
