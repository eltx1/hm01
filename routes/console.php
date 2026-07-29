<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('queue:work database --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('audit-logs:prune')->dailyAt('02:15');

Schedule::command('reporting:import hourly --retry-failed')
    ->hourlyAt(12)
    ->withoutOverlapping();

Schedule::command('reporting:import daily --retry-failed')
    ->dailyAt('04:10')
    ->withoutOverlapping();

Schedule::command('reporting:close-period --force')
    ->monthlyOn(2, '05:20')
    ->withoutOverlapping();
