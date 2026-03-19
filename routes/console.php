<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── Scheduled Jobs ──────────────────────────────────────────────────────────

Schedule::command('telescope:prune --hours=48')->daily();
Schedule::command('horizon:snapshot')->everyFiveMinutes();

// Clean up expired reading sessions
Schedule::call(function () {
    \Illuminate\Support\Facades\DB::table('analytics_events')
        ->where('created_at', '<', now()->subDays(90))
        ->delete();
})->weekly()->name('cleanup-old-analytics');

// Recalculate popular books cache
Schedule::call(function () {
    \Illuminate\Support\Facades\Cache::tags(['books'])->flush();
})->hourly()->name('refresh-book-cache');
