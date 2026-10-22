param(
    [ValidateSet('Status', 'Install', 'Remove')]
    [string]$Mode = 'Status',
    [string]$PhpPath = ''
)

$ErrorActionPreference = 'Stop'
$taskName = 'Notes - фоновая обработка напоминаний'
$root = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$scriptPath = Join-Path $root 'bin/tasks_reminder_cycle.php'

if (-not (Test-Path -LiteralPath $scriptPath -PathType Leaf)) {
    throw 'Служебный сценарий фонового цикла Notes не найден.'
}

$existing = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($Mode -eq 'Status') {
    if ($null -eq $existing) {
        Write-Output 'Задание напоминаний Notes не установлено.'
    } else {
        $information = Get-ScheduledTaskInfo -TaskName $taskName
        Write-Output ('Состояние: {0}; последний запуск: {1}; результат: {2}' -f $existing.State, $information.LastRunTime, $information.LastTaskResult)
    }
    exit 0
}

if ($Mode -eq 'Remove') {
    if ($null -ne $existing) {
        Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    }
    Write-Output 'Задание напоминаний Notes удалено.'
    exit 0
}

if ([string]::IsNullOrWhiteSpace($PhpPath)) {
    $PhpPath = $env:NOTES_PHP_BIN
}
if ([string]::IsNullOrWhiteSpace($PhpPath)) {
    $command = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($null -ne $command) {
        $PhpPath = $command.Source
    }
}
if ([string]::IsNullOrWhiteSpace($PhpPath) -or -not (Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw 'Укажите полный путь к PHP 8.3+ параметром -PhpPath.'
}
& $PhpPath -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'
if ($LASTEXITCODE -ne 0) {
    throw 'Для фонового цикла требуется PHP 8.3 или новее.'
}

$action = New-ScheduledTaskAction -Execute $PhpPath -Argument ('"{0}" --json' -f $scriptPath) -WorkingDirectory $root
$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 15) -RepetitionDuration (New-TimeSpan -Days 3650)
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Description 'Напоминания Notes каждые 15 минут; выполняется текущим пользователем.' -Force | Out-Null
Write-Output 'Задание установлено для текущего пользователя. Проверяйте проходы в админке Notes.'
