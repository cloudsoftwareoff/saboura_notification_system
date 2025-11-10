<?php
use Crunz\Schedule;

$schedule = new Schedule();

// Rule Runner - Every 10 minutes
$task1 = $schedule->run(PHP_BINARY . ' ' . __DIR__ . '/../notification/rule_runner.php');
$task1->everyTenMinutes();
$task1->description('Rule Runner');

// Notification Dispatcher - Every 1 minute
$task2 = $schedule->run(PHP_BINARY . ' ' . __DIR__ . '/../notification/notification_dispatcher.php');
$task2->everyMinute();
$task2->description('Email Dispatcher');

return $schedule;