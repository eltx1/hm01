<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('operations:heartbeat scheduler')->everyMinute()->withoutOverlapping();
Schedule::command('static-delivery:process')->everyMinute()->withoutOverlapping(10);
Schedule::command('queue:work database --stop-when-empty --max-time='.(int) config('operations.queue_max_time', 50).' --tries='.(int) config('operations.queue_tries', 3))
    ->everyMinute()->withoutOverlapping();
Schedule::command('campaigns:monitor --reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('audit-logs:prune')->dailyAt('02:15');
Schedule::command('reporting:import hourly --retry-failed')->hourlyAt(12)->withoutOverlapping();
Schedule::command('reporting:import daily --retry-failed')->dailyAt('04:10')->withoutOverlapping();
Schedule::command('reporting:close-period --force')->monthlyOn(2, '05:20')->withoutOverlapping();
