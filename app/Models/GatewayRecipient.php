<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatewayRecipient extends Model
{
    protected $fillable = ['person_id', 'email', 'mobile', 'notify_email', 'notify_sms', 'is_enabled'];

    protected function casts(): array
    {
        return ['notify_email' => 'boolean', 'notify_sms' => 'boolean', 'is_enabled' => 'boolean'];
    }
}
