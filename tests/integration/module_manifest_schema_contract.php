<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$schemaPath = $root . '/docs/module-manifest.schema.json';
$auditPath = $root . '/docs/CORE_SECURITY_AUDIT_0.14.md';

function failModuleContract(string $message): never
{
    fwrite(STDERR, "Module architecture contract failed: {$message}\n");
    exit(1);
}

if (!is_file($schemaPath)) {
    failModuleContract('module manifest schema is missing');
}

$schemaText = file_get_contents($schemaPath);
if ($schemaText === false) {
    failModuleContract('cannot read module manifest schema');
}

$schema = json_decode($schemaText, true);
if (!is_array($schema) || json_last_error() !== JSON_ERROR_NONE) {
    failModuleContract('module manifest schema must be valid JSON');
}

$requiredTopLevel = [
    'id',
    'name',
    'version',
    'core',
    'provider',
    'dependencies',
    'conflicts',
    'capabilities',
    'requires_capabilities',
    'routes',
    'migrations',
    'storage',
    'healthcheck',
];

$actualRequired = $schema['required'] ?? null;
if (!is_array($actualRequired)) {
    failModuleContract('schema required list is missing');
}

foreach ($requiredTopLevel as $field) {
    if (!in_array($field, $actualRequired, true)) {
        failModuleContract("required manifest field is missing: {$field}");
    }
}

if (($schema['additionalProperties'] ?? null) !== false) {
    failModuleContract('top-level manifest must reject undeclared properties');
}

$properties = $schema['properties'] ?? null;
if (!is_array($properties)) {
    failModuleContract('schema properties are missing');
}

foreach (['license', 'permissions', 'assets', 'settings_schema'] as $field) {
    if (!array_key_exists($field, $properties)) {
        failModuleContract("optional manifest contract is missing: {$field}");
    }
}

$pathPattern = $schema['$defs']['relativePath']['pattern'] ?? '';
if (!is_string($pathPattern) || !str_contains($pathPattern, '\\.\\.')) {
    failModuleContract('relative path contract must explicitly reject traversal');
}

if (!is_file($auditPath)) {
    failModuleContract('core security audit baseline is missing');
}

$audit = file_get_contents($auditPath);
if ($audit === false) {
    failModuleContract('cannot read core security audit baseline');
}

foreach ([
    'A-01 — Application modules are recursively executable at bootstrap',
    'A-02 — Central router owns every functional module',
    'S-01 — Request parsing mixes transport data with output encoding',
    'S-02 — SQL diagnostic logging can include parameter fragments',
    'signed update metadata',
    'signed license payload',
    'ModuleRegistry',
] as $marker) {
    if (!str_contains($audit, $marker)) {
        failModuleContract("audit baseline is missing marker: {$marker}");
    }
}

fwrite(STDOUT, "Module architecture contract: OK\n");
