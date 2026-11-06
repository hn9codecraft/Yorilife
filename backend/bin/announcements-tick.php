<?php

declare(strict_types=1);

/*
 * Announcement scheduler. Run every minute (Windows Task Scheduler or cron):
 *
 *   php bin/announcements-tick.php
 *
 * Publishes scheduled announcements whose time has come and expires published
 * ones past their expiry. Safe to run often; the list and detail APIs also run
 * the same step as a safety net. Nothing is sent: outbox rows wait for the
 * employee app.
 */

use App\Services\AnnouncementService;

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';
restore_exception_handler();

try {
    $result = (new AnnouncementService())->tick();
    fwrite(STDOUT, sprintf("[%s UTC] published: %d, expired: %d\n", gmdate('Y-m-d H:i:s'), count($result['published']), count($result['expired'])));
} catch (Throwable $e) {
    fwrite(STDERR, 'Announcement tick failed: ' . $e->getMessage() . "\n");
    exit(1);
}