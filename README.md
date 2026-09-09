# 🏗️ DUMP PROJECT SCRIPT v5.0

Инструмент для генерации Markdown-дампов структуры PHP-проектов с веб-интерфейсом.

[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=flat&logo=php&logoColor=white)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/version-5.0-blue)]()

---

## 📋 Описание

**Dump Project Script** — это PHP-скрипт для создания детализированных Markdown-дампов структуры проектов. Позволяет быстро документировать файловую структуру и содержимое ключевых файлов для передачи проекта, отладки или архивации.

### Возможности

- 🌳 **Древовидное отображение** структуры папок и файлов
- 📄 **Полный дамп** с содержимым файлов или только структура
- 🔍 **Гибкая фильтрация** по расширениям файлов
- 🚫 **Исключение папок и файлов** с поддержкой wildcards (`*`, `?`)
- 📁 **Выбор рабочей директории** через интерактивное дерево папок
- ⚙️ **Сохранение конфигурации** в отдельный файл
- 🔐 **Авторизация** с ограничением попыток входа
- 🧹 **Автоочистка** старых дампов по количеству и времени
- 🎨 **Современный интерфейс** на Foundation CSS + Font Awesome

---

## 🚀 Быстрый старт

### Установка

1. Скачайте файл `dump_project.php`
2. Поместите в корень вашего проекта
3. Откройте в браузере: `http://your-domain/dump_project.php`

### Первый запуск

По умолчанию доступны учетные записи:

| Логин | Пароль |
|-------|--------|
| `admin` | `admin123` |

> ⚠️ **Важно:** Измените пароли перед использованием в production!

---

## ⚙️ Конфигурация

Все настройки находятся в начале скрипта:

```php
// --- Настройки вывода ---
define('DUMP_DIR', 'z_dump');                    // Директория для дампов
define('DUMP_FILE_PREFIX', 'project_structure_'); // Префикс файлов
define('DATE_FORMAT', 'Y_m_d_H_i');              // Формат даты

// --- Глубина дерева ---
define('MAX_TREE_DEPTH', 10);                    // Максимальная глубина

// --- Настройки авторизации ---
define('MAX_LOGIN_ATTEMPTS', 5);                 // Попыток до блокировки
define('BLOCK_TIME_MINUTES', 5);                 // Минут блокировки

// --- Пользователи ---
define('USERS', serialize([
    'admin' => password_hash('admin123', PASSWORD_DEFAULT),
    'mcsweb' => password_hash('mamama', PASSWORD_DEFAULT)
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
define('MAX_DUMP_COUNT', 10);    // Максимум файлов в директории
define('MAX_DUMP_AGE_DAYS', 30); // Максимальный возраст файлов
