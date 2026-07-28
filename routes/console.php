<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:work database --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('audit-logs:prune')->dailyAt('02:15');
