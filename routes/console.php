<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('operations:heartbeat scheduler')->everyMinute()->withoutOverlapping();
Schedule::command('queue:work database --stop-when-empty --max-time=50 --max-jobs=100 --memory=128 --sleep=1 --tries=3')->everyMinute()->withoutOverlapping();
Schedule::command('queue:prune-failed --hours=720')->dailyAt('02:05');
Schedule::command('audit-logs:prune')->dailyAt('02:15');
Schedule::command('reporting:import hourly --retry-failed')->hourlyAt(12)->withoutOverlapping();
Schedule::command('reporting:import daily --retry-failed')->dailyAt('04:10')->withoutOverlapping();
Schedule::command('campaigns:monitor --reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('reporting:close-period --force')->monthlyOn(2, '05:20')->withoutOverlapping();
