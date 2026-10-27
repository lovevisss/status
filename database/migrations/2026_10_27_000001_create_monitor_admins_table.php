<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_admins', function (Blueprint $table): void {
            $table->id();
            $table->string('cas_account', 120)->unique();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        DB::table('monitor_admins')->insert([
            'cas_account' => '20150301',
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_admins');
    }
};
