<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('incident_key', 26)->unique();
            $table->string('service_key', 80)->index();
            $table->unsignedSmallInteger('consecutive_failures')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('recovered_at')->nullable();
            $table->timestamps();
            $table->index(['service_key', 'recovered_at']);
        });

        Schema::create('monitor_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_incident_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('request_id', 120)->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->string('platform_task_id')->nullable();
            $table->string('recipient_id', 120)->nullable();
            $table->string('recipient_email', 255)->nullable();
            $table->string('title', 255);
            $table->text('content');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['monitor_incident_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_alerts');
        Schema::dropIfExists('monitor_incidents');
    }
};
