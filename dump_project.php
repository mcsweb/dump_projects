<?php
/* =========================================================
   DUMP PROJECT SCRIPT v4
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
   
   Изменения в v4:
   - Добавлен веб-интерфейс с использованием Foundation 6
   - Параметры настраиваются через HTML-форму
   - Имя выходного файла включает дату и время
   - Оптимизирован алгоритм (один проход по файловой системе)
   - Поддержка масок (*, ?) для исключения файлов и папок
   - Улучшена безопасность и обработка ошибок
   
   2026.01.31 - Версия 4
   ========================================================= */

// Обработка POST-запроса (генерация дампа)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Получаем и обрабатываем параметры из формы
    $listOnlyMode = isset($_POST['listOnlyMode']) && $_POST['listOnlyMode'] === '1';
    
    // Обработка списка расширений
    $extensionsInput = $_POST['includeExtensions'] ?? '';
    $includeExtensions = array_filter(
        array_map('trim', 
            preg_split('/[\s,]+/', $extensionsInput)
        ),
        function($ext) { return !empty($ext) && preg_match('/^[a-z0-9]+$/i', $ext); }
    );
    
    // Если пользователь не ввел расширения, используем значения по умолчанию
    if (empty($includeExtensions)) {
        $includeExtensions = ['php', 'txt', 'html', 'htm', 'js', 'css', 'json', 'md', 'env', 'sql', 'yml', 'yaml', 'xml', 'ini'];
    }
    
    // Обработка списка исключаемых папок (поддерживаются маски)
    $excludeDirsInput = $_POST['excludeDirs'] ?? '';
    $excludeDirs = array_filter(
        array_map('trim', 
            preg_split('/[\s,]+/', $excludeDirsInput)
        ),
        function($dir) { return !empty($dir); }
    );
    
    // Обработка списка исключаемых файлов (поддерживаются маски)
    $excludeFilesInput = $_POST['excludeFiles'] ?? '';
    $excludeFiles = array_filter(
        array_map('trim', 
            preg_split('/[\s,]+/', $excludeFilesInput)
        ),
        function($file) { return !empty($file); }
    );
    
    // Генерация имени файла с датой и временем
    $timestamp = date('Y_m_d_H_i');
    $outputFile = __DIR__ . "/project_structure_{$timestamp}.md";
    
    // Основной массив для сбора файлов (для второго раздела)
    $filesToDump = [];
    
    // Открываем файл для записи
    $handle = fopen($outputFile, 'w');
    if (!$handle) {
        die("❌ Не удалось создать выходной файл.\n");
    }
    
    // Функция для очистки пути
    function sanitizePath($path) {
        return str_replace('\\', '/', $path);
    }
    
    // Функция для проверки соответствия файла маске исключения
    function isFileExcluded($filename, $excludePatterns) {
        foreach ($excludePatterns as $pattern) {
            // Поддержка простых масок: * для любой последовательности, ? для одного символа
            if (fnmatch($pattern, $filename, FNM_CASEFOLD)) {
                return true;
            }
        }
        return false;
    }
    
    // Заголовок файла дампа
    $projectRoot = __DIR__;
    fwrite($handle, "# 🏗️ Структура проекта\n\n");
    fwrite($handle, "_Сгенерировано автоматически скриптом " . basename(__FILE__) . "_\n");
    fwrite($handle, "_Дата генерации: " . date('Y-m-d H:i:s') . "_\n\n");
    fwrite($handle, "```\n");
    fwrite($handle, "Корень проекта: $projectRoot\n");
    fwrite($handle, "Режим: " . ($listOnlyMode ? 'Только структура' : 'Полный дамп') . "\n");
    fwrite($handle, "```\n\n");
    
    // === ЕДИНЫЙ РЕКУРСИВНЫЙ ПРОХОД ===
    // Функция сканирует директорию и одновременно:
    // 1. Записывает структуру в файл
    // 2. Собирает файлы для дампа (если нужно)
    
    fwrite($handle, "## 1. 📂 Структура директорий\n\n");
    fwrite($handle, "```\n");
    
    function scanDirectory($dir, $handle, &$filesToDump, $prefix = '', $isLast = true, $params) {
        // Параметры
        list($includeExtensions, $excludeDirs, $excludeFiles, $listOnlyMode) = $params;
        
        if (!is_dir($dir)) return;
        
        $items = [];
        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $filepath = "$dir/$file";
            $relPath = sanitizePath(substr($filepath, strlen(__DIR__) + 1));
            
            if (is_dir($filepath)) {
                // Проверяем, не входит ли папка в исключения (поддержка масок)
                $shouldExclude = false;
                foreach ($excludeDirs as $pattern) {
                    if (fnmatch($pattern, $file, FNM_CASEFOLD)) {
                        $shouldExclude = true;
                        break;
                    }
                }
                if ($shouldExclude) continue;
                
                $items[] = ['type' => 'dir', 'name' => "$file/", 'path' => $filepath];
            } else {
                // Проверяем файлы на исключение по маскам
                $shouldExclude = false;
                
                // Проверяем по маскам из списка исключений
                if (isFileExcluded($file, $excludeFiles)) {
                    $shouldExclude = true;
                }
                
                // Проверяем паттерны логов и временных файлов (автоматические исключения)
                $lower = strtolower($file);
                if (!$shouldExclude && (
                    preg_match('/\.(log|tmp|cache)$/i', $lower) ||
                    preg_match('/^message_|^user_messages_|^query_/i', $lower)
                )) {
                    $shouldExclude = true;
                }
                
                if ($shouldExclude) continue;
                
                $items[] = ['type' => 'file', 'name' => $file, 'path' => $filepath];
                
                // Если не режим "только структура" и расширение подходит - собираем файл для дампа
                if (!$listOnlyMode) {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (in_array($ext, $includeExtensions)) {
                        $filesToDump[] = $filepath;
                    }
                }
            }
        }
        
        // Сортировка: папки первыми
        usort($items, function($a, $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
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
    
    // Запускаем сканирование
    $params = [$includeExtensions, $excludeDirs, $excludeFiles, $listOnlyMode];
    scanDirectory($projectRoot, $handle, $filesToDump, '', true, $params);
    
    fwrite($handle, "```\n\n");
    
    // === ВТОРОЙ РАЗДЕЛ: СОДЕРЖИМОЕ ФАЙЛОВ ===
    if (!$listOnlyMode && !empty($filesToDump)) {
        fwrite($handle, "## 2. 📄 Полное содержимое ключевых файлов\n\n");
        fwrite($handle, "> ⚠️ Логи, временные и бинарные файлы пропущены.\n");
        fwrite($handle, "> Включенные расширения: " . implode(', ', $includeExtensions) . "\n\n");
        
        foreach ($filesToDump as $filepath) {
            $relativePath = sanitizePath(substr($filepath, strlen($projectRoot) + 1));
            $basename = basename($relativePath);
            
            fwrite($handle, str_repeat('━', 80) . "\n");
            fwrite($handle, "📄 `$relativePath`\n");
            fwrite($handle, str_repeat('━', 80) . "\n\n");
            
            if (is_readable($filepath)) {
                $content = file_get_contents($filepath);
                if ($content !== false) {
                    $ext = pathinfo($basename, PATHINFO_EXTENSION);
                    $language = in_array($ext, ['htm', 'html']) ? 'html' :
                               ($ext === 'php' ? 'php' :
                               ($ext === 'json' ? 'json' :
                               ($ext === 'js' ? 'javascript' :
                               ($ext === 'yml' || $ext === 'yaml' ? 'yaml' : $ext))));
                    
                    // Экранирование редких backticks
                    $content = str_replace('```', '`​``', $content); // Zero-width space после первого `
                    fwrite($handle, "```$language\n");
                    fwrite($handle, rtrim($content) . "\n");
                    fwrite($handle, "```\n\n");
                } else {
                    fwrite($handle, "> ❌ Не удалось прочитать содержимое файла.\n\n");
                }
            } else {
                fwrite($handle, "> 🔒 Файл не доступен для чтения.\n\n");
            }
        }
    } elseif (!$listOnlyMode) {
        fwrite($handle, "## 2. 📄 Полное содержимое ключевых файлов\n\n");
        fwrite($handle, "> ⚠️ Файлов с указанными расширениями не найдено.\n\n");
    }
    
    fclose($handle);
    
    // Определяем имя файла для вывода (без полного пути)
    $outputFilename = basename($outputFile);
    
    // Выводим результат с использованием Foundation
    ?>
    <!DOCTYPE html>
    <html lang="ru" class="no-js">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Генерация дампа проекта - Результат</title>
        <!-- Foundation 6 CSS -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/foundation/6.7.5/css/foundation.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/foundation/6.7.5/css/foundation-float.min.css">
        <style>
            body {
                padding: 20px;
                background-color: #f8f9fa;
            }
            .header-info {
                margin-bottom: 30px;
                padding: 20px;
                border-radius: 5px;
            }
            .result-section {
                margin-top: 30px;
                padding: 25px;
                border-radius: 5px;
            }
            .file-link {
                font-size: 1.2em;
                padding: 15px;
                background-color: #e7f3ff;
                border-radius: 5px;
                margin: 15px 0;
            }
            .param-summary {
                background-color: #f8f9fa;
                padding: 15px;
                border-radius: 5px;
                margin: 15px 0;
            }
            .back-button {
                margin-top: 20px;
            }
            .mask-examples {
                font-family: monospace;
                background-color: #f0f0f0;
                padding: 10px;
                border-radius: 5px;
                margin-top: 10px;
            }
        </style>
    </head>
    <body>
        <div class="grid-container">
            <div class="grid-x grid-padding-x">
                <div class="cell">
                    <div class="callout success header-info">
                        <h2>✅ Дамп проекта успешно создан!</h2>
                        <p>Файл сохранен в корневой директории скрипта.</p>
                    </div>
                    
                    <div class="callout primary result-section">
                        <h4>📄 Результат генерации</h4>
                        
                        <div class="file-link">
                            <strong>Имя файла:</strong> 
                            <a href="<?php echo htmlspecialchars($outputFilename); ?>" target="_blank">
                                <?php echo htmlspecialchars($outputFilename); ?>
                            </a>
                        </div>
                        
                        <div class="param-summary">
                            <h5>Использованные параметры:</h5>
                            <ul>
                                <li><strong>Режим:</strong> <?php echo $listOnlyMode ? 'Только структура' : 'Полный дамп'; ?></li>
                                <li><strong>Корневая директория:</strong> <?php echo htmlspecialchars($projectRoot); ?></li>
                                <li><strong>Расширения файлов:</strong> <?php echo htmlspecialchars(implode(', ', $includeExtensions)); ?></li>
                                <li><strong>Исключенные папки:</strong> <?php echo !empty($excludeDirs) ? htmlspecialchars(implode(', ', $excludeDirs)) : '(нет)'; ?></li>
                                <li><strong>Исключенные файлы (маски):</strong> <?php echo !empty($excludeFiles) ? htmlspecialchars(implode(', ', $excludeFiles)) : '(нет)'; ?></li>
                            </ul>
                        </div>
                        
                        <div class="callout secondary">
                            <h5>📊 Статистика:</h5>
                            <ul>
                                <li>Первый раздел: структура директорий (дерево файлов)</li>
                                <?php if (!$listOnlyMode): ?>
                                <li>Второй раздел: содержимое <?php echo count($filesToDump); ?> файлов</li>
                                <?php else: ?>
                                <li>Режим: ТОЛЬКО список файлов (без содержимого)</li>
                                <?php endif; ?>
                            </ul>
                            <p><strong>📎 Теперь вы можете скачать этот файл и отправить его целиком.</strong></p>
                        </div>
                        
                        <div class="grid-x grid-margin-x">
                            <div class="cell medium-6">
                                <a href="<?php echo htmlspecialchars($outputFilename); ?>" class="button expanded  back-button" target="_blank">
                                    📥 Скачать файл дампа
                                </a>
                            </div>
                            <div class="cell medium-6">
                                <a href="<?php echo basename(__FILE__); ?>" class="button secondary expanded back-button">
                                    ↩️ Создать новый дамп
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Foundation 6 JS -->
        <script src="https://cdnjs.cloudflare.com/ajax/libs/foundation/6.7.5/js/foundation.min.js"></script>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                $(document).foundation();
            });
        </script>
    </body>
    </html>
    <?php
    exit();
}

// Если запрос не POST, показываем форму
?>
<!DOCTYPE html>
<html lang="ru" class="no-js">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Генерация дампа проекта v4</title>
    <!-- Foundation 6 CSS -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/foundation/6.7.5/css/foundation.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/foundation/6.7.5/css/foundation-float.min.css">
    <style>
        body {
            padding: 20px;
            background-color: #f8f9fa;
        }
        .header-info {
            margin-bottom: 30px;
            padding: 20px;
            border-radius: 5px;
        }
        .form-section {
            margin-bottom: 20px;
            padding: 20px;
            border-radius: 5px;
        }
        .help-text {
            font-size: 0.9em;
            color: #666;
            margin-top: 5px;
        }
        .button-group {
            margin-top: 30px;
        }
        .info-box {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .mask-examples {
            font-family: monospace;
            background-color: #f0f0f0;
            padding: 10px;
            border-radius: 5px;
            margin-top: 10px;
            font-size: 0.9em;
        }
        .mask-help {
            background-color: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 10px 15px;
            margin: 10px 0;
        }
    </style>
</head>
<body>
    <div class="grid-container">
        <div class="grid-x grid-padding-x">
            <div class="cell">
                <div class="callout primary header-info">
                    <h2>🏗️ Генератор дампа проекта v4</h2>
                    <p>Скрипт создаст Markdown-файл с структурой и содержимым вашего проекта.</p>
                </div>
                
                <div class="callout info-box">
                    <h5>📍 Текущая рабочая директория:</h5>
                    <div class="callout small">
                        <code><?php echo htmlspecialchars(__DIR__); ?></code>
                    </div>
                    <p class="help-text">Скрипт будет сканировать эту директорию и все вложенные папки.</p>
                </div>
                
                <form method="POST" action="">
                    <div class="callout form-section">
                        <h4>⚙️ Настройки генерации</h4>
                        
                        <div class="grid-x grid-margin-x">
                            <div class="cell">
                                <label>
                                    <input type="checkbox" name="listOnlyMode" value="1">
                                    <strong>Только структура (без содержимого файлов)</strong>
                                </label>
                                <p class="help-text">Если отмечено, в дамп попадет только дерево файлов и папок без их содержимого.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="callout form-section">
                        <h4>📄 Расширения файлов для включения в дамп</h4>
                        <div class="grid-x grid-margin-x">
                            <div class="cell">
                                <label>Расширения (через запятую или пробел)</label>
                                <input type="text" name="includeExtensions" 
                                       value="php, txt, html, htm, js, css, json, md, env, sql, yml, yaml, xml, ini"
                                       placeholder="php, js, html, css, ...">
                                <p class="help-text">Файлы с этими расширениями будут включены в дамп с полным содержимым (если не выбран режим "Только структура").</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="callout form-section">
                        <h4>🚫 Папки для исключения (поддержка масок)</h4>
                        <div class="grid-x grid-margin-x">
                            <div class="cell">
                                <label>Имена папок (через запятую или пробел)</label>
                                <input type="text" name="excludeDirs" 
                                       value=".git, __pycache__, node_modules, vendor, .idea, .vscode, log_, logs, cache, tmp, *_old"
                                       placeholder=".git, node_modules, vendor, temp_*, test* ...">
                                <div class="mask-help">
                                    <strong>Поддержка масок:</strong>
                                    <ul>
                                        <li><code>*</code> - любая последовательность символов</li>
                                        <li><code>?</code> - один любой символ</li>
                                        <li><code>log*</code> - все папки, начинающиеся с "log"</li>
                                        <li><code>temp_?</code> - папки типа "temp_1", "temp_a"</li>
                                    </ul>
                                </div>
                                <p class="help-text">Эти папки и их содержимое будут полностью исключены из дампа.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="callout form-section">
                        <h4>🚫 Файлы для исключения (поддержка масок)</h4>
                        <div class="grid-x grid-margin-x">
                            <div class="cell">
                                <label>Имена файлов и маски (через запятую или пробел)</label>
                                <input type="text" name="excludeFiles" 
                                       value="dump_project.php, project_structure_*.md"
                                       placeholder="*.log, *.tmp, project_structure_*.md, temp_* ...">
                                <div class="mask-help">
                                    <strong>Примеры масок для файлов:</strong>
                                    <div class="mask-examples">
                                        <div><strong>project_structure_*.md</strong> - все дампы проекта</div>
                                        <div><strong>*.log</strong> - все файлы логов</div>
                                        <div><strong>*.{log,tmp,cache}</strong> - укажите через запятую</div>
                                        <div><strong>temp_*</strong> - все файлы, начинающиеся с "temp_"</div>
                                        <div><strong>*.tmp</strong> - все временные файлы</div>
                                        <div><strong>README*</strong> - все README файлы</div>
                                    </div>
                                </div>
                                <p class="help-text">Файлы, соответствующие этим маскам, будут исключены из дампа. Проверяется без учета вложенности.</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="callout warning">
                        <h5>⚠️ Внимание</h5>
                        <ul>
                            <li>Имя выходного файла будет сгенерировано автоматически с датой и временем (пример: <code>project_structure_2026_01_31_14_30.md</code>)</li>
                            <li>Логи и временные файлы (<code>*.log, *.tmp, *.cache</code>) исключаются автоматически</li>
                            <li>Файлы, соответствующие маске <code>project_structure_*.md</code>, исключаются по умолчанию</li>
                            <li>Процесс может занять некоторое время для больших проектов</li>
                        </ul>
                    </div>
                    
                    <div class="button-group">
                        <div class="grid-x grid-margin-x columns">
                            <div class="cell medium-6">
                                <button type="submit" class="button expanded large success">
                                    🚀 СГЕНЕРИРОВАТЬ ДАМП
                                </button>
                            </div>
                            <div class="cell medium-6">
                                <button type="reset" class="button expanded large secondary">
                                    🔄 Сбросить настройки
                                </button>
                            </div>
                        </div>
                    </div>
                </form>
                
                <div class="callout secondary">
                    <h5>📝 О скрипте (Версия 4)</h5>
                    <p><strong>Новые возможности:</strong></p>
                    <ul>
                        <li>Веб-интерфейс с настройками через форму</li>
                        <li>Динамические имена файлов с датой и временем</li>
                        <li>Поддержка масок (*, ?) для исключения файлов и папок</li>
                        <li>Оптимизированная производительность (один проход)</li>
                        <li>Автоматическое исключение логов и временных файлов</li>
                    </ul>
                    <p>Скрипт создает подробный Markdown-дамп вашего проекта, который можно использовать для документации, отладки или передачи проекта.</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Foundation 6 JS -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/foundation/6.7.5/js/foundation.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            $(document).foundation();
        });
    </script>
</body>
</html>
