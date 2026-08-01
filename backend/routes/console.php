<?php

use Illuminate\Support\Facades\Schedule;

// The nightly sweep across every active club: expire what ran out, close
// forgotten check-ins, fire renewal reminders and refresh the AI insights.
Schedule::command('gymflow:maintenance')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();

// The nightly archive. It checks its own enabled flag, so leaving this
// scheduled on an install with backups off costs nothing.
Schedule::command('gymflow:backup')
    ->dailyAt(config('gymflow.backup.time', '03:30'))
    ->withoutOverlapping()
    ->onOneServer();
