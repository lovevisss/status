<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon|null $last_test_at */
class MonitorRecipient extends Model
{
    protected $fillable = [
        'person_id', 'email', 'is_enabled', 'last_test_at', 'last_test_alert_id',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_test_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MonitorAlert, $this> */
    public function lastTestAlert(): BelongsTo
    {
        return $this->belongsTo(MonitorAlert::class, 'last_test_alert_id');
    }
}
