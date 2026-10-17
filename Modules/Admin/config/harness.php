<?php

return [
    /*
    | This switch is intentionally false in every environment unless an operator
    | enables it for one invocation/deployment. The command still applies its
    | environment, driver, database and acknowledgement guards when enabled.
    */
    'enabled' => filter_var(env('ASSAB_STAGING_HARNESS_ENABLED', false), FILTER_VALIDATE_BOOL),

    /* Exact database names only; patterns and partial matches are not accepted. */
    'allowed_databases' => array_values(array_filter(array_map(
        static fn (string $name): string => trim($name),
        explode(',', (string) env('ASSAB_STAGING_HARNESS_DATABASE_ALLOWLIST', '')),
    ))),

    'default_namespace' => env('ASSAB_STAGING_HARNESS_NAMESPACE', 'assab-harness-v1'),
    'password_env' => env('ASSAB_STAGING_HARNESS_PASSWORD_ENV', 'ASSAB_HARNESS_PASSWORD'),
];
