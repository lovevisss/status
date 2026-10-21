<?php

namespace App\Services;

use App\Models\MonitorCheck;

class MonitorServiceStats
{
    public ?MonitorCheck $first = null;

    public ?MonitorCheck $latest = null;

    public int $count = 0;

    public int $up = 0;

    /** @var array<string, array{count: int, up: int}> */
    public array $days = [];

    public function add(MonitorCheck $check): void
    {
        if ($this->first === null) {
            $this->first = $check;
        }

        $this->latest = $check;
        $this->count++;
        $this->up += (int) $check->is_up;
        $day = $check->checked_at->copy()->timezone('Asia/Shanghai')->toDateString();
        if (! isset($this->days[$day])) {
            $this->days[$day] = ['count' => 0, 'up' => 0];
        }
        $this->days[$day]['count']++;
        $this->days[$day]['up'] += (int) $check->is_up;
    }
}
