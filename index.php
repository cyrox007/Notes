<?php
ini_set('display_errors', 1);
if (!defined('SITEPATH')) {
    define('SITEPATH', dirname(__FILE__));
}
error_reporting(E_ALL);
ini_set('error_log', SITEPATH . '/.logs/php-errors.log');

function handleStartupError($message, $title = 'System Error') {
    http_response_code(500);
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-danger: #DC3545;
            --color-warning: #FFC107;
            --color-light: #f8f9fa;
            --color-dark: #343a40;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Montserrat', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .error-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 100%;
            overflow: hidden;
            animation: slideIn 0.5s ease-out;
        }
        @keyframes slideIn {
            from { transform: translateY(-30px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .error-header {
            background: var(--color-danger);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .error-icon {
            font-size: 48px;
            margin-bottom: 10px;
        }
        .error-title {
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 5px;
        }
        .error-subtitle {
            font-size: 14px;
            opacity: 0.9;
        }
        .error-body {
            padding: 30px;
            background: var(--color-light);
        }
        .error-message {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border-left: 4px solid var(--color-warning);
            color: var(--color-dark);
            font-size: 14px;
            line-height: 1.6;
            margin-bottom: 20px;
        }
        .error-hint {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            border-radius: 6px;
            font-size: 13px;
            color: #856404;
        }
        .error-hint strong {
            display: block;
            margin-bottom: 8px;
            color: #664d03;
        }
        .error-code {
            font-family: 'Courier New', monospace;
            background: #f1f3f5;
            padding: 10px;
            border-radius: 4px;
            font-size: 12px;
            color: #e03131;
            word-break: break-all;
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-header">
            <div class="error-icon">⚠️</div>
            <div class="error-title">{$title}</div>
            <div class="error-subtitle">System Configuration Error</div>
        </div>
        <div class="error-body">
            <div class="error-message">
                {$message}
            </div>
            <div class="error-hint">
                <strong>💡 How to fix:</strong>
                Follow the instructions above to resolve this issue.
                If the problem persists, check the PHP error logs.
            </div>
        </div>
    </div>
</body>
</html>
HTML;
    exit;
}

try {
    require_once SITEPATH . '/core.php';
} catch (\Exception $e) {
    handleStartupError(
        '<strong>' . htmlspecialchars($e->getMessage()) . '</strong>',
        'Configuration Error'
    );
}

// Маршрутизатор
require_once SITEPATH . '/core/Router.php';
require_once SITEPATH . '/core/RouterConfig.php';
