<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyRecipients = $this->legacyRecipients();

        Schema::create('monitor_recipients', function (Blueprint $table) {
            $table->id();
            $table->string('person_id', 120)->unique();
            $table->string('email', 255)->nullable();
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_test_at')->nullable();
            $table->foreignId('last_test_alert_id')->nullable()->constrained('monitor_alerts')->nullOnDelete();
            $table->timestamps();
        });

        foreach ($legacyRecipients as $recipient) {
            DB::table('monitor_recipients')->insert([
                ...$recipient,
                'is_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** @return list<array{person_id: string, email: string|null}> */
    private function legacyRecipients(): array
    {
        $raw = config('monitors.alerts.recipients');
        if ($raw === null || $raw === '') {
            return [];
        }
        if (! is_string($raw)) {
            throw new RuntimeException('MONITOR_ALERT_RECIPIENTS 格式错误：需要 JSON 数组。');
        }

        $entries = json_decode($raw, true);
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new RuntimeException('MONITOR_ALERT_RECIPIENTS 格式错误：需要 JSON 数组。');
        }

        $seen = [];
        $recipients = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null)
                || $entry['id'] === '' || strlen($entry['id']) > 120
                || ! preg_match('/\A[A-Za-z0-9_-]+\z/', $entry['id'])
                || isset($seen[$entry['id']])) {
                throw new RuntimeException('MONITOR_ALERT_RECIPIENTS 含有无效或重复的学工号。');
            }
            $email = $entry['email'] ?? null;
            if ($email === '') {
                $email = null;
            }
            if ($email !== null && (! is_string($email) || strlen($email) > 255
                || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
                throw new RuntimeException('MONITOR_ALERT_RECIPIENTS 含有无效邮箱。');
            }

            $seen[$entry['id']] = true;
            $recipients[] = [
                'person_id' => $entry['id'],
                'email' => $email,
            ];
        }

        return $recipients;
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_recipients');
    }
};
