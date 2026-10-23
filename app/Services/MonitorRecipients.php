<?php

namespace App\Services;

use App\Models\MonitorRecipient;

class MonitorRecipients
{
    /** @return list<array{id: string, email: string|null}> */
    public function all(): array
    {
        return array_values(MonitorRecipient::where('is_enabled', true)->orderBy('id')->get()
            ->map(fn (MonitorRecipient $recipient) => [
                'id' => $recipient->person_id,
                'email' => $recipient->email,
            ])->all());
    }
}
