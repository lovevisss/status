<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** @property Carbon $checked_at */
class MonitorCheck extends Model
{
    public $timestamps = false;

    protected $fillable = ['service_key', 'is_up', 'status_code', 'response_ms', 'error', 'checked_at'];

    protected function casts(): array
    {
        return ['is_up' => 'boolean', 'checked_at' => 'datetime'];
    }
}
