<?php
// scripts/pull-contract-fixtures.php
// Run via: composer pull-contract-fixtures
//
// Copies the auth-service OpenAPI fragments into tests/Contract/fixtures/
// so the contract test runs against a vendored copy (no live filesystem
// dependency on the auth-service repo at CI time).

declare(strict_types=1);

$authServicePath = getenv('AUTH_SERVICE_PATH')
    ?: dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'auth-service';

$source = $authServicePath
    . DIRECTORY_SEPARATOR . 'project'
    . DIRECTORY_SEPARATOR . 'docs'
    . DIRECTORY_SEPARATOR . 'openapi'
    . DIRECTORY_SEPARATOR . 'handoff-tokens.yaml';

$dest = dirname(__DIR__)
    . DIRECTORY_SEPARATOR . 'tests'
    . DIRECTORY_SEPARATOR . 'Contract'
    . DIRECTORY_SEPARATOR . 'fixtures'
    . DIRECTORY_SEPARATOR . 'handoff-tokens.yaml';

if (!is_file($source)) {
    fwrite(STDERR, "ERROR: source spec not found at: {$source}\n");
    fwrite(STDERR, "Set AUTH_SERVICE_PATH env var or place auth-service repo as a sibling of auth-service-helper.\n");
    exit(1);
}

if (!is_dir(dirname($dest))) {
    mkdir(dirname($dest), 0775, true);
}

if (!copy($source, $dest)) {
    fwrite(STDERR, "ERROR: failed to copy {$source} → {$dest}\n");
    exit(1);
}

fwrite(STDOUT, "Pulled OpenAPI fixture: {$source} → {$dest}\n");
exit(0);
