<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $cancelled_at
 */
class MonitorMaintenanceWindow extends Model
{
    protected $fillable = ['title', 'description', 'starts_at', 'ends_at', 'ended_at', 'cancelled_at'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'ended_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<MonitorService, $this> */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(MonitorService::class, 'monitor_maintenance_services');
    }

    public function effectiveEnd(): CarbonInterface
    {
        return $this->ended_at && $this->ended_at->lt($this->ends_at) ? $this->ended_at : $this->ends_at;
    }
}
