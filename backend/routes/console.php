<?php

use Illuminate\Support\Facades\Schedule;

// The nightly sweep across every active club: expire what ran out, close
// forgotten check-ins, fire renewal reminders and refresh the AI insights.
Schedule::command('gymflow:maintenance')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();
