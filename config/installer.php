<?php

return [
    'env_path' => base_path('.env'),
    'installed_path' => storage_path('installed'),
    'setup_token_path' => storage_path('installer-setup-token'),
    'setup_lock_path' => storage_path('installer-setup-lock'),
    'in_progress_path' => storage_path('installer-in-progress'),
    'setup_token_ttl' => (int) env('INSTALLER_SETUP_TOKEN_TTL', 3600),
];
