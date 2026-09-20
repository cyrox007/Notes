#!/usr/bin/env bash
set -euo pipefail

mapfile -t callers < <(git grep -l -E -- '->queue(Insert|Update|Delete)\(' -- '*.php' | sort)

# Every queued-write caller is intentionally enumerated. Adding a new caller
# requires reviewing its error path because DatabaseManager::commit() now throws.
expected=(
  'modules/files/controllers/FileController.php'
  'modules/tasks/models/TaskModel.php'
  'core/ORM.php'
  'tests/integration/database_queue_integrity.php'
)

printf 'Queued-write callers found:\n'
printf '  %s\n' "${callers[@]}"

unexpected=0
for path in "${callers[@]}"; do
  allowed=0
  for known in "${expected[@]}"; do
    if [[ "$path" == "$known" ]]; then
      allowed=1
      break
    fi
  done
  if [[ "$allowed" -ne 1 ]]; then
    echo "Unexpected queued-write caller: $path" >&2
    unexpected=1
  fi
done

for known in "${expected[@]}"; do
  if ! printf '%s\n' "${callers[@]}" | grep -Fxq "$known"; then
    echo "Expected queued-write caller disappeared; update this audit intentionally: $known" >&2
    unexpected=1
  fi
done

for production_caller in \
  modules/files/controllers/FileController.php \
  modules/tasks/models/TaskModel.php \
  core/ORM.php
do
  if ! git grep -q -F -- '->commit()' -- "$production_caller"; then
    echo "Queued writes without a commit call in $production_caller" >&2
    unexpected=1
  fi
done

if [[ "$unexpected" -ne 0 ]]; then
  exit 1
fi

echo 'Queued-write caller audit: OK'
