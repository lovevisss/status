<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatewayMonitor extends Model
{
    protected $fillable = ['key', 'gateway_number', 'ip', 'location', 'building', 'is_enabled'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'building' => 'integer'];
    }
}
