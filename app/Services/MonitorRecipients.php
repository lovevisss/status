<?php

namespace App\Services;

class MonitorRecipients
{
    /** @return list<array{id: string, email: string}>|null */
    public function all(): ?array
    {
        $raw = config('monitors.alerts.recipients');
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded) || ! array_is_list($decoded) || $decoded === []) {
            return null;
        }

        $recipients = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry) || ! is_string($entry['id'] ?? null)
                || ! preg_match('/^[A-Za-z0-9_-]{1,40}$/', $entry['id'])
                || ! is_string($entry['email'] ?? null)
                || filter_var($entry['email'], FILTER_VALIDATE_EMAIL) === false
                || isset($recipients[$entry['id']])) {
                return null;
            }

            $recipients[$entry['id']] = ['id' => $entry['id'], 'email' => $entry['email']];
        }

        return array_values($recipients);
    }
}
