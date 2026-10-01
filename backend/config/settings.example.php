<?php

declare(strict_types=1);

// Copy to settings.local.php in the HTTP-protected private/backend/config directory.
return [
    'environment' => 'production',
    'origin' => 'https://glimpse.example',
    // Every public address of the workspace (same application, database and accounts). Browsers keep separate
    // host-only cookies per address. Defaults to [origin]; origin must be one of them.
    // 'workspace_origins' => ['https://glimpse.example', 'https://old.glimpse.example'],
    // Address written into new and revealed share URLs. Defaults to origin.
    // 'share_public_origin' => 'https://glimpse.example',
    // Old share hosts that keep opening existing /s/... links but never serve sign-in or the workspace.
    // 'share_alias_origins' => ['https://old-links.glimpse.example'],
    // While bin/maintenance-mode.php is on, only these client addresses may use the API (acceptance checks).
    // 'maintenance_allow_ips' => [],
    'database' => [
        'dsn' => 'mysql:host=localhost;port=3306;dbname=glimpse;charset=utf8mb4',
        'username' => '',
        'password' => '',
    ],
    // Run bin/generate-share-key.php once to create the separate private share-key.local.php.
    // Alternatively set GLIMPSE_SHARE_ENCRYPTION_KEY to a base64-encoded random 32-byte key.
    // Shared preview-cache budget for all users (bytes, at least 1 MiB); each user is also limited to 32 MiB.
    'cache_global_max_bytes' => 268435456,
    // Run bin/generate-github-token-key.php once to create the separate private github-token-key.local.php
    // that encrypts users' GitHub and GitLab tokens. Alternatively set GLIMPSE_GITHUB_TOKEN_KEY (base64, 32 bytes)
    // and GLIMPSE_GITHUB_TOKEN_KEY_ID. Never reuse the share key.
    // GitLab: the address suggested in Connections, and the hosts users may connect to (`host` or `host:port`;
    // empty allows any public HTTPS host on port 443). Private and local addresses are always refused in production.
    // 'gitlab_default_url' => 'https://gitlab.com',
    // 'gitlab_allowed_hosts' => ['gitlab.com', 'gitlab.example.com'],
];
