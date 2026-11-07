<?php

return [
    'brute_force_attempts' => (int) env('AUTH_BRUTE_FORCE_ATTEMPTS', 3),
    'brute_force_window_seconds' => (int) env('AUTH_BRUTE_FORCE_WINDOW_SECONDS', 120),
    'blacklist_attempts' => (int) env('AUTH_IP_BLACKLIST_ATTEMPTS', 5),
    'blacklist_window_seconds' => (int) env('AUTH_IP_BLACKLIST_WINDOW_SECONDS', 300),
    'brute_force_retention_days' => (int) env('AUTH_SECURITY_RETENTION_DAYS', 30),
    'active_session_seconds' => (int) env('AUTH_ACTIVE_SESSION_SECONDS', 120),
];
