<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property-read MonitorIncident $incident */
class MonitorAlert extends Model
{
    protected $fillable = [
        'monitor_incident_id', 'kind', 'request_id', 'status',
        'platform_task_id', 'recipient_id', 'recipient_email',
        'title', 'content', 'attempts', 'next_attempt_at', 'last_error',
    ];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime'];
    }

    /** @return BelongsTo<MonitorIncident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(MonitorIncident::class, 'monitor_incident_id');
    }
}
