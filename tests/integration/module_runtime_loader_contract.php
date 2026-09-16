<?php

declare(strict_types=1);

// Intentionally tiny smoke: the substantive isolated-runtime cases live in
// module_registry_contract.php so there is a single source of truth for module
// discovery/composition/entrypoint behavior. This file exists only as a stable
// direct CI entrypoint for downstream packaging checks.
require __DIR__ . '/module_registry_contract.php';
