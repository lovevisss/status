<?php

namespace App\Services;

class MonitorRequestIds
{
    public static function forRecipient(string $kind, string $incidentKey, string $personId): string
    {
        return "campus_status:{$kind}:{$incidentKey}_".hash('sha256', $personId);
    }
}
