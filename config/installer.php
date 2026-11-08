<?php

return [
    'env_path' => base_path('.env'),
    'installed_path' => storage_path('installed'),
    'setup_lock_path' => storage_path('installer-setup-lock'),
    'in_progress_path' => storage_path('installer-in-progress'),
    'allow_remote_db' => (bool) env('INSTALLER_ALLOW_REMOTE_DB', false),
];
