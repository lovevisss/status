<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MonitorService extends Model
{
    protected $fillable = [
        'key', 'name', 'description', 'url', 'active_url_hash', 'is_enabled', 'sort_order', 'archived_at',
    ];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'archived_at' => 'datetime'];
    }

    /** @param Builder<MonitorService> $query
     * @return Builder<MonitorService>
     */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /** @param Builder<MonitorService> $query
     * @return Builder<MonitorService>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->current()->where('is_enabled', true);
    }
}
