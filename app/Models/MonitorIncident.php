<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $started_at
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $recovered_at
 */
class MonitorIncident extends Model
{
    protected $fillable = [
        'incident_key', 'service_key', 'consecutive_failures',
        'started_at', 'confirmed_at', 'recovered_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'recovered_at' => 'datetime',
        ];
    }

    /** @return HasMany<MonitorAlert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(MonitorAlert::class);
    }
}
