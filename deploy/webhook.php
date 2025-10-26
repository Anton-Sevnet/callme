<?php
/**
 * GitHub Webhook handler for automatic deployment
 * 
 * Setup:
 * 1. Place this file on your server (e.g., /var/www/deploy/webhook.php)
 * 2. Configure GitHub webhook: Settings -> Webhooks -> Add webhook
 *    - Payload URL: http://your-server.com/deploy/webhook.php
 *    - Content type: application/json
 *    - Secret: <your-secret-key>
 *    - Events: Just the push event
 * 3. Set proper permissions for git pull
 */

// Configuration
define('SECRET_KEY', 'your-secret-key-here'); // Измените на свой секретный ключ
define('PROJECT_PATH', '/path/to/your/project/callme'); // Путь к проекту на сервере
define('BRANCH', 'master'); // Ветка для деплоя
define('LOG_FILE', __DIR__ . '/deploy.log'); // Лог файл

// Функция для логирования
function writeLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
    echo $logMessage;
}

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    writeLog('ERROR: Invalid request method');
    die('Method Not Allowed');
}

// Получение payload
$payload = file_get_contents('php://input');
$data = json_decode($payload, true);

// Проверка сигнатуры GitHub
if (defined('SECRET_KEY') && SECRET_KEY !== 'your-secret-key-here') {
    $signature = 'sha256=' . hash_hmac('sha256', $payload, SECRET_KEY);
    $gitHubSignature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    
    if (!hash_equals($signature, $gitHubSignature)) {
        http_response_code(403);
        writeLog('ERROR: Invalid signature');
        die('Invalid signature');
    }
}

// Проверка события
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($event !== 'push') {
    writeLog("INFO: Ignoring event: $event");
    die('Not a push event');
}

// Проверка ветки
$ref = $data['ref'] ?? '';
$pushedBranch = str_replace('refs/heads/', '', $ref);
if ($pushedBranch !== BRANCH) {
    writeLog("INFO: Ignoring branch: $pushedBranch");
    die("Not the target branch (expected: " . BRANCH . ")");
}

writeLog("=== DEPLOYMENT STARTED ===");
writeLog("Branch: $pushedBranch");
writeLog("Commits: " . count($data['commits'] ?? []));

// Переходим в директорию проекта
if (!is_dir(PROJECT_PATH)) {
    http_response_code(500);
    writeLog('ERROR: Project directory not found: ' . PROJECT_PATH);
    die('Project directory not found');
}

chdir(PROJECT_PATH);

// Выполняем деплой команды
$commands = [
    'git fetch origin ' . BRANCH . ' 2>&1',
    'git reset --hard origin/' . BRANCH . ' 2>&1',
    'composer install --no-dev --optimize-autoloader 2>&1',
    // 'php artisan migrate --force 2>&1', // Раскомментируйте если используете Laravel
    // 'php artisan config:cache 2>&1',
    // 'systemctl restart your-service 2>&1', // Перезапуск сервиса если нужно
];

$output = [];
$exitCode = 0;

foreach ($commands as $command) {
    writeLog("Executing: $command");
    exec($command, $commandOutput, $commandExitCode);
    
    $output = array_merge($output, $commandOutput);
    
    foreach ($commandOutput as $line) {
        writeLog("  > $line");
    }
    
    if ($commandExitCode !== 0) {
        $exitCode = $commandExitCode;
        writeLog("ERROR: Command failed with exit code: $commandExitCode");
        break;
    }
}

if ($exitCode === 0) {
    writeLog("=== DEPLOYMENT SUCCESSFUL ===");
    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'message' => 'Deployment completed successfully',
        'output' => $output
    ]);
} else {
    writeLog("=== DEPLOYMENT FAILED ===");
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Deployment failed',
        'output' => $output,
        'exit_code' => $exitCode
    ]);
}

