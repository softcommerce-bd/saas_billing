<?php

use App\Support\Idempotency\IdempotencyKey;
use Illuminate\Support\Facades\Schedule;

// Subscription payments: resolve lost callbacks / timeouts and finish pending activations.
Schedule::command('subscriptions:reconcile-payments')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Informational status sync (access is enforced live from expires_at, not by this job).
Schedule::command('subscriptions:sync-statuses')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();

// Renewal reminders after the local day starts in Asia/Dhaka.
Schedule::command('subscriptions:send-reminders')->dailyAt('09:00')->timezone('Asia/Dhaka')->withoutOverlapping()->onOneServer();

Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->daily();
