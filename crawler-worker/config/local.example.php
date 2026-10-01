<?php

// Development-only local override. Copy to config/local.php; that file is gitignored.
return [
    'api_base_url' => 'http://127.0.0.1:8000',
    'environment' => 'development',
    'allow_insecure_local_api' => true,
    // On Windows, keep credential_store='windows'. Use file-dev only for disposable local testing.
    'credential_store' => 'windows',
];
