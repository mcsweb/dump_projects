<?php
/* =========================================================
   DUMP PROJECT SCRIPT v5.0 (deepseek)
   ---------------------------------------------------------
   Назначение: Генерация Markdown-дампов структуры проекта
   Возможности:
   • Древовидное отображение папок и файлов
   • Фильтрация по расширениям и исключение системных папок
   • Два режима: только список или полный дамп с кодом
   • Автопропуск логов и временных файлов
   • Веб-интерфейс с настройками через форму
   • Динамические имена файлов с датой и временем
   • Поддержка масок (wildcards) для исключения файлов и папок
   • Все дампы сохраняются в папку /z_dump
   • Автоочистка старых дампов (по количеству и времени)
   • Выбор рабочей директории через дерево папок
   • Сохранение конфигурации в отдельный файл
   • Авторизация с ограничением попыток
   
   Изменения в v5.0:
   - Авторизация с блокировкой после неудачных попыток
   - Дерево директорий для выбора рабочей папки
   - Сохранение конфига в [DUMP_DIR]/[DUMP_FILE_PREFIX]conf.php
   - Проверка глубины дерева при выборе директории
   - Использование Font Awesome вместо UTF-8 иконок
   - Конфигурация вынесена в единый блок

   Особенности релиза:
   - Запуск дебага (стр.90 $enabled = false)
   - динамичное сохранение параметров 
   
   2026.09.09 - Версия 5.0
   ========================================================= */

// ============================================================
// КОНФИГУРАЦИЯ СКРИПТА
// ============================================================

// --- Настройки вывода ---
define('DUMP_DIR', 'z_dump');
define('DUMP_FILE_PREFIX', 'project_structure_');
define('DATE_FORMAT', 'Y_m_d_H_i');

// --- Глубина дерева ---
define('MAX_TREE_DEPTH', 10);

// --- Настройки авторизации ---
define('MAX_LOGIN_ATTEMPTS', 5);
define('BLOCK_TIME_MINUTES', 5);

// --- Пользователи ---
define('USERS', serialize([
    'admin' => password_hash('admin123', PASSWORD_DEFAULT),
    'user' => password_hash('pass123', PASSWORD_DEFAULT)
]));

// --- Расширения файлов для включения в дамп ---
define('DEFAULT_EXTENSIONS', serialize([
    'php', 'txt', 'html', 'htm', 'js', 'css', 
    'json', 'md', 'env', 'sql', 'yml', 'yaml', 
    'xml', 'ini'
]));

// --- Исключаемые директории ---
define('DEFAULT_EXCLUDE_DIRS', serialize([
    '.git', '__pycache__', 'node_modules', 'vendor', 
    '.idea', '.vscode', 'log_', 'logs', 'cache', 'tmp', 
    '*_old', 'z_dump'
]));

// --- Исключаемые файлы ---
define('DEFAULT_EXCLUDE_FILES', serialize([
    'dump_project.php',
    'project_structure_*.md',
    'project_structure_conf.php',
    '*.log', '*.tmp', '*.cache'
]));

// --- Настройки автоочистки ---
define('MAX_DUMP_COUNT', 10);
define('MAX_DUMP_AGE_DAYS', 30);

// ============================================================

session_start();

// ============================================================
// ОТЛАДКА (временная)
// ============================================================
function debug($text, $enabled = false){
    if($enabled){
        $trace = debug_backtrace();
        $caller = $trace[1]['function'] ?? 'unknown';
        $message = "[$caller] " . $text;
        
        // Пишем в сессию (массив для хранения всех сообщений)
        if(!isset($_SESSION['debug_messages'])){
            $_SESSION['debug_messages'] = [];
        }
        $_SESSION['debug_messages'][] .= $message;
        
        // Выводим на экран (как раньше)
        //echo $message . "<br>";
    }
}

// Функция для вывода всех отладочных сообщений
function showDebugMessages(){
    if(isset($_SESSION['debug_messages']) && !empty($_SESSION['debug_messages'])){
        echo '<div style="background: #ff0; padding: 15px; margin: 10px; border: 3px solid #f00; font-family: monospace;">';
        echo '<h4 style="color: #f00;">=== ОТЛАДКА ===</h4>';
        foreach($_SESSION['debug_messages'] as $msg){
            echo $msg . "<br>";
        }
        echo '</div>';
        // Очищаем после показа
        unset($_SESSION['debug_messages']);
    }
}

// ============================================================
// ФУНКЦИИ АВТОРИЗАЦИИ
// ============================================================

function isAuthenticated() {
    if (isset($_SESSION['user']) && isset($_SESSION['login_time'])) {
        if (time() - $_SESSION['login_time'] < 86400) {
            return true;
        }
    }
    return false;
}

function isBlocked() {
    if (isset($_SESSION['blocked_until']) && $_SESSION['blocked_until'] > time()) {
        return true;
    }
    return false;
}

function getBlockTimeRemaining() {
    if (isset($_SESSION['blocked_until'])) {
        return max(0, $_SESSION['blocked_until'] - time());
    }
    return 0;
}

function handleLogin() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login']) && isset($_POST['password'])) {
        if (isBlocked()) {
            $remaining = getBlockTimeRemaining();
            return "⛔ Слишком много попыток. Попробуйте через " . ceil($remaining / 60) . " минут.";
        }
        
        $users = unserialize(USERS);
        $login = $_POST['login'];
        $password = $_POST['password'];
        
        if (isset($users[$login]) && password_verify($password, $users[$login])) {
            $_SESSION['user'] = $login;
            $_SESSION['login_time'] = time();
            $_SESSION['login_attempts'] = 0;
            unset($_SESSION['blocked_until']);
            header('Location: ' . basename(__FILE__));
            exit();
        } else {
            $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
            if ($_SESSION['login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
                $_SESSION['blocked_until'] = time() + (BLOCK_TIME_MINUTES * 60);
                return "⛔ Превышено количество попыток. Блокировка на " . BLOCK_TIME_MINUTES . " минут.";
            }
            return "❌ Неверный логин или пароль. Осталось попыток: " . (MAX_LOGIN_ATTEMPTS - $_SESSION['login_attempts']);
        }
    }
    return null;
}

// ============================================================
// ФУНКЦИИ РАБОТЫ С КОНФИГОМ
// ============================================================

function getConfPath() {
    return __DIR__ . '/' . DUMP_DIR . '/' . DUMP_FILE_PREFIX . 'conf.php';
}

function loadConfig() {
    
    $confFile = getConfPath();
    $config = [];
    
    if (file_exists($confFile)) {
        $config = require $confFile;
    }
    
    debug ("Конфиг загружен !");

    return [
        'work_dir' => $config['work_dir'] ?? __DIR__,
        'extensions' => $config['extensions'] ?? unserialize(DEFAULT_EXTENSIONS),
        'exclude_dirs' => $config['exclude_dirs'] ?? unserialize(DEFAULT_EXCLUDE_DIRS),
        'exclude_files' => $config['exclude_files'] ?? unserialize(DEFAULT_EXCLUDE_FILES)
    ];

    
}

function saveConfig($data) {
    $confFile = getConfPath();
    $dumpDir = __DIR__ . '/' . DUMP_DIR;
    
    if (!is_dir($dumpDir)) {
        if (!mkdir($dumpDir, 0755, true)) {
            debug("ОШИБКА: Не удалось создать директорию " . $dumpDir);
            return false;
        }
    }
    
    $content = "<?php\n";
    $content .= "// " . DUMP_FILE_PREFIX . "conf.php\n";
    $content .= "// Автоматически сгенерировано: " . date('Y-m-d H:i:s') . "\n";
    $content .= "// Версия скрипта: 5.0\n\n";
    $content .= "return [\n";
    $content .= "    'work_dir' => '" . addslashes($data['work_dir']) . "',\n";
    $content .= "    'extensions' => " . var_export($data['extensions'], true) . ",\n";
    $content .= "    'exclude_dirs' => " . var_export($data['exclude_dirs'], true) . ",\n";
    $content .= "    'exclude_files' => " . var_export($data['exclude_files'], true) . ",\n";
    $content .= "];\n";
    
    $result = file_put_contents($confFile, $content);
    
    if ($result === false) {
        debug("ОШИБКА: Не удалось записать конфиг в " . $confFile);
        return false;
    }
    
    debug("Успешно записано " . $result . " байт в " . $confFile);
    
    // Принудительная синхронизация с диском
    clearstatcache();
    
    // Проверяем, что файл действительно содержит новые данные
    if (file_exists($confFile)) {
        // ОЧИЩАЕМ КЭШ OPcache ПЕРЕД ЧТЕНИЕМ
        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($confFile, true);
            debug("OPcache инвалидирован для " . $confFile);
        }
        clearstatcache();
        
        $check = require $confFile;
        if (isset($check['work_dir']) && $check['work_dir'] === $data['work_dir']) {
            debug("Проверка: конфиг записан корректно, work_dir = " . $check['work_dir']);
            return true;
        } else {
            debug("ОШИБКА: Конфиг записан, но данные не совпадают!");
            return false;
        }
    }
    
    return false;
}

function resetConfig() {
    debug ("Конфиг сброшен <br>");
    $confFile = getConfPath();
    if (file_exists($confFile)) {
        unlink($confFile);
    }
    return saveConfig([
        'work_dir' => __DIR__,
        'extensions' => unserialize(DEFAULT_EXTENSIONS),
        'exclude_dirs' => unserialize(DEFAULT_EXCLUDE_DIRS),
        'exclude_files' => unserialize(DEFAULT_EXCLUDE_FILES)
    ]);
}

// ============================================================
// ФУНКЦИИ РАБОТЫ С ДЕРЕВОМ
// ============================================================

function getDirectoryTree($dir, $selected = null, $depth = 0) {
    if ($depth > MAX_TREE_DEPTH) {
        return ['error' => 'max_depth'];
    }
    
    if (!is_readable($dir)) {
        return ['error' => 'no_access'];
    }
    
    $items = [];
    $files = scandir($dir);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..' || strpos($file, '.') === 0) {
            continue;
        }
        
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            // Проверяем исключения
            $excludeDirs = unserialize(DEFAULT_EXCLUDE_DIRS);
            $shouldExclude = false;
            foreach ($excludeDirs as $pattern) {
                if (fnmatch($pattern, $file, FNM_CASEFOLD)) {
                    $shouldExclude = true;
                    break;
                }
            }
            if ($shouldExclude) continue;
            
            $isSelected = ($path === $selected);
            $isReadable = is_readable($path);
            $subItems = $isReadable ? getDirectoryTree($path, $selected, $depth + 1) : ['error' => 'no_access'];
            $fileCount = $isReadable ? count(array_filter(scandir($path), function($f) use ($path) {
                return $f !== '.' && $f !== '..' && is_file($path . '/' . $f);
            })) : 0;
            $dirCount = $isReadable ? count(array_filter(scandir($path), function($f) use ($path) {
                return $f !== '.' && $f !== '..' && is_dir($path . '/' . $f);
            })) : 0;
            
            $items[] = [
                'name' => $file,
                'path' => $path,
                'is_selected' => $isSelected,
                'is_readable' => $isReadable,
                'file_count' => $fileCount,
                'dir_count' => $dirCount,
                'children' => is_array($subItems) ? $subItems : []
            ];
        }
    }
    
    usort($items, function($a, $b) {
        return strcasecmp($a['name'], $b['name']);
    });
    
    return $items;
}

function renderTree($items, $prefix = '') {
    $html = '';
    $count = count($items);
    
    foreach ($items as $index => $item) {
        $isLast = ($index === $count - 1);
        $connector = $isLast ? '└── ' : '├── ';
        
        if (isset($item['error'])) {
            if ($item['error'] === 'no_access') {
                $html .= $prefix . $connector . '🔒 <span class="text-secondary">' . htmlspecialchars($item['name']) . ' (нет прав)</span>';
            }
            continue;
        }
        
        $icon = $item['is_selected'] ? '📌' : '📁';
        $classes = $item['is_selected'] ? ' primary' : '';
        $style = $item['is_selected'] ? 'padding: 3px 8px; margin: 2px 0; display: inline-block;' : '';
        $clickable = $item['is_readable'] ? 'onclick="selectDirectory(\'' . addslashes($item['path']) . '\')" style="cursor: pointer;"' : 'style="cursor: not-allowed; opacity: 0.6;"';
        
        $html .= '<div style="margin: 2px 0;">';
        $html .= '<span ' . $clickable . ' class="' . $classes . '" style="' . $style . '">';
        $html .= $icon . ' ' . htmlspecialchars($item['name']);
        $html .= ' <span class="label secondary" style="font-size: 0.7em;">' . $item['file_count'] . ' файлов, ' . $item['dir_count'] . ' папок</span>';
        if ($item['is_selected']) {
            $html .= ' <span class="label success">текущая</span>';
        }
        if (!$item['is_readable']) {
            $html .= ' 🔒';
        }
        $html .= '</span>';
        $html .= '</div>';
        
        if (!empty($item['children']) && is_array($item['children'])) {
            $newPrefix = $prefix . ($isLast ? '    ' : '│   ');
            $html .= '<div style="margin-left: 20px;">';
            $html .= renderTree($item['children'], $newPrefix);
            $html .= '</div>';
        }
    }
    
    return $html;
}

function getDirectoryDepth($dir, $root) {
    $relative = str_replace($root, '', $dir);
    return substr_count($relative, DIRECTORY_SEPARATOR);
}

// ============================================================
// ФУНКЦИИ ГЕНЕРАЦИИ ДАМПА
// ============================================================

function cleanOldDumps() {
    $dumpDir = __DIR__ . '/' . DUMP_DIR;
    if (!is_dir($dumpDir)) return;
    
    $pattern = DUMP_FILE_PREFIX . '*.md';
    $files = glob($dumpDir . '/' . $pattern);
    if (empty($files)) return;
    
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    $now = time();
    $deleted = 0;
    
    foreach ($files as $index => $file) {
        $shouldDelete = false;
        if (MAX_DUMP_COUNT > 0 && $index >= MAX_DUMP_COUNT) $shouldDelete = true;
        if (MAX_DUMP_AGE_DAYS > 0 && (($now - filemtime($file)) / 86400) > MAX_DUMP_AGE_DAYS) $shouldDelete = true;
        if ($shouldDelete && unlink($file)) $deleted++;
    }
}

function isFileExcluded($filename, $excludePatterns) {
    foreach ($excludePatterns as $pattern) {
        if (fnmatch($pattern, $filename, FNM_CASEFOLD)) return true;
    }
    return false;
}

function generateDump($params) {
    extract($params);
    
    $dumpDir = __DIR__ . '/' . DUMP_DIR;
    if (!is_dir($dumpDir) && !mkdir($dumpDir, 0755, true)) {
        return ['error' => 'Не удалось создать директорию для дампов'];
    }
    
    cleanOldDumps();
    
    $timestamp = date(DATE_FORMAT);
    $outputFile = $dumpDir . '/' . DUMP_FILE_PREFIX . "{$timestamp}.md";
    $filesToDump = [];
    
    $handle = fopen($outputFile, 'w');
    if (!$handle) return ['error' => 'Не удалось создать выходной файл'];
    
    fwrite($handle, "# 🏗️ Структура проекта\n\n");
    fwrite($handle, "_Сгенерировано автоматически скриптом " . basename(__FILE__) . "_\n");
    fwrite($handle, "_Дата генерации: " . date('Y-m-d H:i:s') . "_\n\n");
    fwrite($handle, "```\n");
    fwrite($handle, "Корень проекта: $workDir\n");
    fwrite($handle, "Режим: " . ($listOnlyMode ? 'Только структура' : 'Полный дамп') . "\n");
    fwrite($handle, "```\n\n");
    
    fwrite($handle, "## 1. 📂 Структура директорий\n\n```\n");
    
    $scanParams = [$includeExtensions, $excludeDirs, $excludeFiles, $listOnlyMode, $workDir];
    scanDirectory($workDir, $handle, $filesToDump, '', true, $scanParams);
    
    fwrite($handle, "```\n\n");
    
    if (!$listOnlyMode && !empty($filesToDump)) {
        fwrite($handle, "## 2. 📄 Полное содержимое ключевых файлов\n\n");
        fwrite($handle, "> Включенные расширения: " . implode(', ', $includeExtensions) . "\n\n");
        
        foreach ($filesToDump as $filepath) {
            $relativePath = substr($filepath, strlen($workDir) + 1);
            fwrite($handle, str_repeat('━', 80) . "\n");
            fwrite($handle, "📄 `$relativePath`\n");
            fwrite($handle, str_repeat('━', 80) . "\n\n");
            
            if (is_readable($filepath)) {
                $content = file_get_contents($filepath);
                if ($content !== false) {
                    $ext = pathinfo($filepath, PATHINFO_EXTENSION);
                    $lang = in_array($ext, ['htm','html']) ? 'html' : ($ext === 'php' ? 'php' : ($ext === 'json' ? 'json' : ($ext === 'js' ? 'javascript' : ($ext === 'yml' || $ext === 'yaml' ? 'yaml' : $ext))));
                    $content = str_replace('```', '`​``', $content);
                    fwrite($handle, "```$lang\n" . rtrim($content) . "\n```\n\n");
                }
            }
        }
    }
    
    fclose($handle);
    return ['success' => true, 'file' => DUMP_DIR . '/' . basename($outputFile), 'count' => count($filesToDump)];
}

function scanDirectory($dir, $handle, &$filesToDump, $prefix = '', $isLast = true, $params) {
    list($includeExtensions, $excludeDirs, $excludeFiles, $listOnlyMode, $workDir) = $params;
    
    if (!is_dir($dir)) return;
    
    $items = [];
    $files = array_diff(scandir($dir), ['.', '..']);
    
    foreach ($files as $file) {
        $filepath = "$dir/$file";
        if (is_dir($filepath)) {
            $shouldExclude = false;
            foreach ($excludeDirs as $pattern) {
                if (fnmatch($pattern, $file, FNM_CASEFOLD)) { $shouldExclude = true; break; }
            }
            if ($shouldExclude) continue;
            $items[] = ['type' => 'dir', 'name' => "$file/", 'path' => $filepath];
        } else {
            if (isFileExcluded($file, $excludeFiles)) continue;
            $lower = strtolower($file);
            if (preg_match('/\.(log|tmp|cache)$/i', $lower) || preg_match('/^message_|^user_messages_|^query_/i', $lower)) continue;
            $items[] = ['type' => 'file', 'name' => $file, 'path' => $filepath];
            if (!$listOnlyMode) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($ext, $includeExtensions)) $filesToDump[] = $filepath;
            }
        }
    }
    
    usort($items, function($a, $b) {
        if ($a['type'] !== $b['type']) return $a['type'] === 'dir' ? -1 : 1;
        return strcmp($a['name'], $b['name']);
    });
    
    $count = count($items);
    foreach ($items as $i => $item) {
        $isLastItem = ($i === $count - 1);
        $connector = $isLastItem ? '└── ' : '├── ';
        fwrite($handle, "$prefix$connector{$item['name']}\n");
        if ($item['type'] === 'dir') {
            $newPrefix = $prefix . ($isLastItem ? '    ' : '│   ');
            scanDirectory($item['path'], $handle, $filesToDump, $newPrefix, $isLastItem, $params);
        }
    }
}

// ============================================================
// ОБРАБОТКА ЗАПРОСОВ (ЕДИНЫЙ БЛОК!)
// ============================================================

// Инициализация конфига и рабочей директории
$config = loadConfig();
$workDir = $config['work_dir'];
debug ("До POST : ".$workDir);

// Обработка POST-запросов (все в одном месте!)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Выход
    if (isset($_POST['logout'])) {
        session_destroy();
        header('Location: ' . basename(__FILE__));
        exit();
    }
    
    // Сброс конфига
    if (isset($_POST['reset_config'])) {
        resetConfig();
        header('Location: ' . basename(__FILE__));
        exit();
    }
    
    // Выбор директории (клик по папке)
    if (isset($_POST['select_dir']) && isset($_POST['work_dir'])) {
        $newWorkDir = $_POST['work_dir'];
        debug (" Принят новый путь : ".$newWorkDir);
        if (is_dir($newWorkDir) && is_readable($newWorkDir)) {
            saveConfig([
                'work_dir' => $newWorkDir,
                'extensions' => $config['extensions'],
                'exclude_dirs' => $config['exclude_dirs'],
                'exclude_files' => $config['exclude_files']
            ]);
            debug ("saveConfig отработала далее перезагрузка страницы.<br>");
            header('Location: ' . basename(__FILE__));
            exit();
        }
    }
    
    // Сохранение конфига
    if (isset($_POST['save_config'])) {
        $newWorkDir = isset($_POST['work_dir']) ? $_POST['work_dir'] : __DIR__;
        $configData = [
            'work_dir' => $newWorkDir,
            'extensions' => array_filter(array_map('trim', preg_split('/[\s,]+/', $_POST['includeExtensions'] ?? ''))),
            'exclude_dirs' => array_filter(array_map('trim', preg_split('/[\s,]+/', $_POST['excludeDirs'] ?? ''))),
            'exclude_files' => array_filter(array_map('trim', preg_split('/[\s,]+/', $_POST['excludeFiles'] ?? '')))
        ];
        if (empty($configData['extensions'])) $configData['extensions'] = unserialize(DEFAULT_EXTENSIONS);
        if (empty($configData['exclude_dirs'])) $configData['exclude_dirs'] = unserialize(DEFAULT_EXCLUDE_DIRS);
        if (empty($configData['exclude_files'])) $configData['exclude_files'] = unserialize(DEFAULT_EXCLUDE_FILES);
        saveConfig($configData);
        header('Location: ' . basename(__FILE__));
        exit();
    }
    
    // Генерация дампа
    if (isset($_POST['generate_dump'])) {
        $workDir = isset($_POST['work_dir']) ? $_POST['work_dir'] : $config['work_dir'];
        
        // Проверка глубины
        if (getDirectoryDepth($workDir, __DIR__) > MAX_TREE_DEPTH) {
            $depthError = "❌ Превышена максимальная глубина дерева (MAX_TREE_DEPTH = " . MAX_TREE_DEPTH . ")";
        } else {
            $extensions = array_filter(array_map('trim', preg_split('/[\s,]+/', $_POST['includeExtensions'] ?? '')));
            $excludeDirs = array_filter(array_map('trim', preg_split('/[\s,]+/', $_POST['excludeDirs'] ?? '')));
            $excludeFiles = array_filter(array_map('trim', preg_split('/[\s,]+/', $_POST['excludeFiles'] ?? '')));
            if (empty($extensions)) $extensions = unserialize(DEFAULT_EXTENSIONS);
            if (empty($excludeDirs)) $excludeDirs = unserialize(DEFAULT_EXCLUDE_DIRS);
            if (empty($excludeFiles)) $excludeFiles = unserialize(DEFAULT_EXCLUDE_FILES);
            
            $result = generateDump([
                'workDir' => $workDir,
                'includeExtensions' => $extensions,
                'excludeDirs' => $excludeDirs,
                'excludeFiles' => $excludeFiles,
                'listOnlyMode' => isset($_POST['listOnlyMode']) && $_POST['listOnlyMode'] === '1'
            ]);
            
            if (isset($result['error'])) {
                $dumpError = $result['error'];
            } else {
                $dumpSuccess = $result;
            }
        }
    }
}

// Проверка авторизации (после обработки POST)
if (!isAuthenticated()) {
    $loginError = handleLogin();
    if (!isAuthenticated()) {
        // Показываем форму логина
        ?>
        <!DOCTYPE html>
        <html lang="ru">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Авторизация - ДАМП ПРОЕКТА v5.0</title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/foundation/6.7.5/css/foundation.min.css">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
            <style>
                body { background: #f0f2f5; display: flex; align-items: center; min-height: 100vh; }
                .login-box { max-width: 600px; margin: 0 auto; padding: 30px; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .login-box h2 { text-align: center; margin-bottom: 30px; }
                .login-box .icon { text-align: center; font-size: 48px; color: #2196F3; margin-bottom: 20px; }
            </style>
        </head>
        <body>
            <div class="grid-container">
                <div class="grid-x grid-padding-x align-center">
                    <div class="cell medium-12 large-12">
                        <div class="login-box">
                            <div class="icon"><i class="fas fa-lock"></i></div>
                            <h2><i class="fas fa-code"></i> ДАМП ПРОЕКТА v5.0</h2>
                            <?php if ($loginError): ?>
                                <div class="callout alert"><?php echo $loginError; ?></div>
                            <?php endif; ?>
                            <?php if (isBlocked()): ?>
                                <div class="callout warning">
                                    <i class="fas fa-clock"></i> Доступ заблокирован на <?php echo ceil(getBlockTimeRemaining() / 60); ?> минут
                                </div>
                            <?php else: ?>
                                <form method="POST" action="<?php echo basename(__FILE__); ?>">
                                    <label><i class="fas fa-user"></i> Логин</label>
                                    <input type="text" name="login" required autofocus>
                                    <label><i class="fas fa-key"></i> Пароль</label>
                                    <input type="password" name="password" required>
                                    <button type="submit" class="button expanded primary">
                                        <i class="fas fa-sign-in-alt"></i> Войти
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit();
    }
}

// Перезагружаем конфиг после возможных изменений
$config = loadConfig();
$workDir = $config['work_dir'];
debug ("После POST : ".$workDir);

// Получение дерева директорий
$tree = getDirectoryTree(__DIR__, $workDir);

// ============================================================
// ОСНОВНОЙ ИНТЕРФЕЙС
// ============================================================

// ВРЕМЕННАЯ ОТЛАДКА - вывод всех POST данных
// echo '<div style="background: #f0f0f0; padding: 15px; margin: 10px; border: 2px solid #f00; font-family: monospace;">';
// echo '<h3 style="color: #f00;">=== ОТЛАДКА: POST ДАННЫЕ ===</h3>';
// echo '<pre>';
// echo 'REQUEST_METHOD: ' . $_SERVER['REQUEST_METHOD'] . "\n";
// echo 'POST данные: ' . print_r($_POST, true);
// echo '</pre>';
// echo '</div>';

showDebugMessages();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ДАМП ПРОЕКТА v5.0</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/foundation/6.7.5/css/foundation.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
</head>
<body>
    <div class="grid-container" style="padding: 20px 0;">
        <!-- Шапка -->
        <div class="grid-x grid-padding-x align-justify" style="margin-bottom: 20px;">
            <div class="cell shrink">
                <h3><i class="fas fa-code" style="color: #2196F3;"></i> ДАМП ПРОЕКТА <span class="label primary">v5.0</span></h3>
            </div>
            <div class="cell shrink">
                
                <form method="POST" style="display: inline;">
                    <button type="button" class="button success small" style="margin: 0; cursor: default; opacity: 0.8;">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($_SESSION['user']); ?>
                    </button>
                    <button type="submit" name="logout" class="button alert small" style="margin: 0;">
                        <i class="fas fa-sign-out-alt"></i> Выйти
                    </button>
                </form>
            </div>
        </div>
        
        <?php if (isset($depthError)): ?>
            <div class="callout alert"><i class="fas fa-exclamation-triangle"></i> <?php echo $depthError; ?></div>
        <?php endif; ?>
        
        <?php if (isset($dumpError)): ?>
            <div class="callout alert"><i class="fas fa-exclamation-circle"></i> <?php echo $dumpError; ?></div>
        <?php endif; ?>
        
        <?php if (isset($dumpSuccess)): ?>
            <div class="callout success">
                <i class="fas fa-check-circle"></i> Дамп успешно создан! 
                <a href="<?php echo htmlspecialchars($dumpSuccess['file']); ?>" target="_blank">
                    <i class="fas fa-file-alt"></i> <?php echo htmlspecialchars($dumpSuccess['file']); ?>
                </a>
                (<?php echo $dumpSuccess['count']; ?> файлов)
            </div>
        <?php endif; ?>
        
        <form method="POST" id="mainForm">    
    <!-- Скрытое поле для рабочей директории -->
    <input type="hidden" name="work_dir" id="work_dir" value="<?php echo htmlspecialchars($workDir); ?>">
            
            <!-- Дерево директорий -->
            <div class="callout primary">
                <h5><i class="fas fa-folder-open"></i> Текущая рабочая директория</h5>
                <div class="callout secondary" style="background: #f8f9fa; padding: 10px; margin-bottom: 10px; font-family: monospace; word-break: break-all;">
                    <i class="fas fa-folder"></i> <strong><?php echo htmlspecialchars($workDir); ?></strong>
                    <?php if (getDirectoryDepth($workDir, __DIR__) > MAX_TREE_DEPTH): ?>
                        <span class="label alert"><i class="fas fa-exclamation-triangle"></i> Превышена глубина</span>
                    <?php endif; ?>
                </div>
                <div class="callout secondary" style="background: white; padding: 10px; font-family: monospace; max-height: 400px; overflow-y: auto;">
                    <div style="margin-left: 0;">
                        <?php if (is_array($tree) && !isset($tree['error'])): ?>
                            <?php echo renderTree($tree); ?>
                        <?php elseif (isset($tree['error']) && $tree['error'] === 'max_depth'): ?>
                            <div class="callout warning" style="margin: 5px 0;">
                                <i class="fas fa-exclamation-triangle"></i> Достигнута максимальная глубина дерева (MAX_TREE_DEPTH = <?php echo MAX_TREE_DEPTH; ?>)
                            </div>
                        <?php elseif (isset($tree['error']) && $tree['error'] === 'no_access'): ?>
                            <div class="callout alert" style="margin: 5px 0;">
                                <i class="fas fa-lock"></i> Нет доступа к директории
                            </div>
                        <?php else: ?>
                            <div class="callout warning" style="margin: 5px 0;">
                                <i class="fas fa-folder-open"></i> Нет доступных папок
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Настройки -->
            <div class="callout">
                <h5><i class="fas fa-sliders-h"></i> Настройки генерации</h5>
                
                <div class="grid-x grid-margin-x">
                    <div class="cell">
                        <label>
                            <input type="checkbox" name="listOnlyMode" value="1">
                            <i class="fas fa-list"></i> Только структура (без содержимого файлов)
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="callout">
                <h5><i class="fas fa-file-code"></i> Расширения файлов для включения в дамп</h5>
                <input type="text" name="includeExtensions" 
                       value="<?php echo htmlspecialchars(implode(', ', $config['extensions'])); ?>"
                       placeholder="php, js, html, css, ...">
                <p class="help-text" style="color: #666; font-size: 0.8em;">
                    <i class="fas fa-info-circle"></i> Файлы с этими расширениями будут включены в дамп с полным содержимым
                </p>
            </div>
            
            <div class="callout">
                <h5><i class="fas fa-folder-minus"></i> Папки для исключения (поддержка масок)</h5>
                <input type="text" name="excludeDirs" 
                       value="<?php echo htmlspecialchars(implode(', ', $config['exclude_dirs'])); ?>"
                       placeholder=".git, node_modules, vendor, temp_* ...">
                <p class="help-text" style="color: #666; font-size: 0.8em;">
                    <i class="fas fa-info-circle"></i> 
                    <code>*</code> - любая последовательность, <code>?</code> - один символ
                </p>
            </div>
            
            <div class="callout">
                <h5><i class="fas fa-file-minus"></i> Файлы для исключения (поддержка масок)</h5>
                <input type="text" name="excludeFiles" 
                       value="<?php echo htmlspecialchars(implode(', ', $config['exclude_files'])); ?>"
                       placeholder="*.log, *.tmp, project_structure_*.md ...">
                <p class="help-text" style="color: #666; font-size: 0.8em;">
                    <i class="fas fa-info-circle"></i> Файлы, соответствующие маскам, будут исключены из дампа
                </p>
            </div>
            
            <!-- Кнопки -->
            <div class="grid-x grid-margin-x">
                <div class="cell medium-4">
                    <button type="submit" name="save_config" class="button expanded success">
                        <i class="fas fa-save"></i> Сохранить конфиг
                    </button>
                </div>
                <div class="cell medium-4">
                    <button type="submit" name="reset_config" class="button expanded warning">
                        <i class="fas fa-undo"></i> Сбросить конфиг
                    </button>
                </div>
                <div class="cell medium-4">
                    <button type="submit" name="generate_dump" class="button expanded primary">
                        <i class="fas fa-play"></i> Сгенерировать дамп
                    </button>
                </div>
            </div>
        </form>
        
        <!-- Информация -->
        <div class="callout secondary" style="margin-top: 20px;">
            <h6><i class="fas fa-info-circle"></i> Информация</h6>
            <ul style="margin: 0; font-size: 0.9em;">
                <li><i class="fas fa-folder"></i> Конфиг: <?php echo file_exists(getConfPath()) ? '<span class="label success">загружен</span>' : '<span class="label alert">не найден</span>'; ?></li>
                <li><i class="fas fa-tree"></i> Глубина дерева: <?php echo MAX_TREE_DEPTH; ?></li>
                <li><i class="fas fa-trash"></i> Автоочистка: <?php echo MAX_DUMP_COUNT > 0 ? MAX_DUMP_COUNT . ' файлов' : 'отключена'; ?>, <?php echo MAX_DUMP_AGE_DAYS > 0 ? MAX_DUMP_AGE_DAYS . ' дней' : 'отключена'; ?></li>
            </ul>
        </div>
    </div>
    
    <script>
    function selectDirectory(path) {
        console.log('=== selectDirectory called ===');
        console.log('Path:', path);
        
        // Получаем скрытое поле
        var workDirField = document.getElementById('work_dir');
        console.log('Current work_dir value:', workDirField.value);
        
        // Устанавливаем новое значение
        workDirField.value = path;
        console.log('New work_dir value:', workDirField.value);
        
        // Получаем форму
        var form = document.getElementById('mainForm');
        
        // Создаем скрытое поле select_dir
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'select_dir';
        input.value = '1';
        form.appendChild(input);
        console.log('Added select_dir=1');
        
        // Собираем ВСЕ данные формы
        var formData = new FormData(form);
        console.log('=== ALL FORM DATA ===');
        var dataToSend = {};
        for (var pair of formData.entries()) {
            console.log(pair[0] + ' = ' + pair[1]);
            dataToSend[pair[0]] = pair[1];
        }
        console.log('======================');
        console.log('Sending data:', dataToSend);
        
        // Отправляем форму!
        console.log('=== SUBMITTING FORM ===');
//        alert ("Отправка");
        form.submit();
    }
</script>
</body>
</html>
