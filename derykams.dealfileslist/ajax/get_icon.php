<?php

/**
 * AJAX-эндпоинт для генерации и кэширования SVG-иконок файлов.
 *
 * Принимает: GET ext (string) — расширение файла (pdf, xlsx, rb и т.д.)
 * Возвращает: SVG-иконку (image/svg+xml)
 *
 * Логика:
 *   1. Извлекаем расширение из параметра ext
 *   2. Проверяем есть ли уже готовая иконка в папке icons/ на сервере
 *   3. Если есть — отдаём как статический файл
 *   4. Если нет — генерируем SVG через PHP (простой шаблон с цветом по типу)
 *      и сохраняем в icons/{ext}.svg для переиспользования
 *
 * Иконки кэшируются навсегда — для одного расширения генерация
 * происходит только один раз. При удалении модуля папка icons/
 * очищается через UnInstallFiles().
 */

define('NO_KEEP_STATISTIC', 'Y');
define('NO_AGENT_STATISTIC', 'Y');
define('NO_AGENT_CHECK', true);
define('PUBLIC_AJAX_MODE', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

// === ПАРАМЕТРЫ ===

// Расширение файла из GET-параметра
$ext = strtolower(trim((string)($_GET['ext'] ?? '')));

// Очищаем: только буквы/цифры, до 5 символов
$ext = preg_replace('/[^a-z0-9]/', '', $ext);
if ($ext === '' || strlen($ext) > 5) {
    $ext = 'file';
}

// Папка для кэширования иконок
$iconsDir = __DIR__ . '/../icons';
$iconPath = $iconsDir . '/' . $ext . '.svg';

// === ОТДАЧА ИКОНКИ ===

// Если иконка уже есть в кэше — отдаём как статический файл
if (file_exists($iconPath) && is_readable($iconPath)) {
    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: public, max-age=31536000, immutable');
    header('Content-Length: ' . filesize($iconPath));
    readfile($iconPath);
    die();
}

// === ГЕНЕРАЦИЯ ИКОНКИ ===

// Если папки нет — создаём
if (!is_dir($iconsDir)) {
    @mkdir($iconsDir, 0755, true);
}

// Цвета по типам файлов (в формате hex без #)
$presets = [
    'pdf'   => ['color' => 'e74c3c', 'bg' => 'fef0f0'],
    'doc'   => ['color' => '2980b9', 'bg' => 'f0f7fe'],
    'docx'  => ['color' => '2980b9', 'bg' => 'f0f7fe'],
    'rtf'   => ['color' => '2980b9', 'bg' => 'f0f7fe'],
    'xls'   => ['color' => '27ae60', 'bg' => 'f0fdf4'],
    'xlsx'  => ['color' => '27ae60', 'bg' => 'f0fdf4'],
    'ppt'   => ['color' => 'e67e22', 'bg' => 'fef5f0'],
    'pptx'  => ['color' => 'e67e22', 'bg' => 'fef5f0'],
    'zip'   => ['color' => '8e44ad', 'bg' => 'faf0fd'],
    'rar'   => ['color' => '8e44ad', 'bg' => 'faf0fd'],
    '7z'    => ['color' => '8e44ad', 'bg' => 'faf0fd'],
    'tar'   => ['color' => '8e44ad', 'bg' => 'faf0fd'],
    'gz'    => ['color' => '8e44ad', 'bg' => 'faf0fd'],
    'jpg'   => ['color' => '16a085', 'bg' => 'f0fdfa'],
    'jpeg'  => ['color' => '16a085', 'bg' => 'f0fdfa'],
    'png'   => ['color' => '16a085', 'bg' => 'f0fdfa'],
    'gif'   => ['color' => '16a085', 'bg' => 'f0fdfa'],
    'bmp'   => ['color' => '16a085', 'bg' => 'f0fdfa'],
    'svg'   => ['color' => '16a085', 'bg' => 'f0fdfa'],
    'webp'  => ['color' => '16a085', 'bg' => 'f0fdfa'],
    'mp3'   => ['color' => 'd35400', 'bg' => 'fef3ee'],
    'wav'   => ['color' => 'd35400', 'bg' => 'fef3ee'],
    'flac'  => ['color' => 'd35400', 'bg' => 'fef3ee'],
    'mp4'   => ['color' => 'c0392b', 'bg' => 'fdf0f0'],
    'avi'   => ['color' => 'c0392b', 'bg' => 'fdf0f0'],
    'mkv'   => ['color' => 'c0392b', 'bg' => 'fdf0f0'],
    'mov'   => ['color' => 'c0392b', 'bg' => 'fdf0f0'],
    'txt'   => ['color' => '7f8c8d', 'bg' => 'f8f9f9'],
    'csv'   => ['color' => '27ae60', 'bg' => 'f0fdf4'],
    'json'  => ['color' => 'f39c12', 'bg' => 'fef9f0'],
    'xml'   => ['color' => 'f39c12', 'bg' => 'fef9f0'],
    'html'  => ['color' => 'e67e22', 'bg' => 'fef5f0'],
    'css'   => ['color' => '2980b9', 'bg' => 'f0f7fe'],
    'js'    => ['color' => 'f39c12', 'bg' => 'fef9f0'],
    'php'   => ['color' => '8892bf', 'bg' => 'f4f5fa'],
    'rb'    => ['color' => 'cc342d', 'bg' => 'fdf0f0'],
    'py'    => ['color' => '3776ab', 'bg' => 'f0f6fd'],
    'sql'   => ['color' => 'e67e22', 'bg' => 'fef5f0'],
];

// Цвет по умолчанию — серый
$color = '7f8c8d';
$bg = 'f8f9f9';

if (isset($presets[$ext])) {
    $color = $presets[$ext]['color'];
    $bg = $presets[$ext]['bg'];
}

// Расширение для отображения (uppercase, до 5 символов)
$label = strtoupper(substr($ext, 0, 5));

/*
    SVG-шаблон иконки файла.
    Стиль: белый лист бумаги с загнутым углом, цветным лейблом расширения.
    Размер: 48x48 (viewBox), иконка масштабируется через CSS.
*/
$svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 48 48" fill="none">
  <!-- Тень под листом -->
  <rect x="8" y="4" width="32" height="40" rx="3" fill="#{$bg}" stroke="#{$color}" stroke-width="1.5" stroke-opacity="0.3"/>
  <!-- Загнутый угол -->
  <path d="M32 4 L40 12 L32 12 Z" fill="#{$color}" fill-opacity="0.15" stroke="#{$color}" stroke-width="1.5" stroke-opacity="0.3" stroke-linejoin="round"/>
  <!-- Лейбл расширения -->
  <rect x="8" y="28" width="32" height="14" rx="2" fill="#{$color}"/>
  <text x="24" y="38.5" font-family="Arial, sans-serif" font-size="9" font-weight="700" fill="white" text-anchor="middle" letter-spacing="0.5">{$label}</text>
</svg>
SVG;

// Сохраняем в кэш
@file_put_contents($iconPath, $svg);

// Отдаём
header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: public, max-age=31536000, immutable');
header('Content-Length: ' . strlen($svg));
echo $svg;
die();