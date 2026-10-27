<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $cas_account
 * @property bool $is_enabled
 */
class MonitorAdmin extends Model
{
    protected $fillable = ['cas_account', 'is_enabled'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }
}
