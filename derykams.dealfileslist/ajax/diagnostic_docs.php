<?php

/**
 * Диагностический скрипт №3 — структура таблицы b_documentgenerator_document.
 *
 * Запуск: открой в браузере
 * /local/modules/derykams.dealfileslist/ajax/diagnostic_docs.php?dealId=5739
 *
 * Что делает:
 *   1. Показывает структуру таблицы b_documentgenerator_document (все колонки)
 *   2. Показывает последние 10 документов с ENTITY_ID = dealId (без фильтра по типу)
 *   3. Показывает последние 10 документов вообще (для анализа структуры)
 *   4. Ищет колонки содержащие "entity" или "type" в имени
 *
 * После диагностики УДАЛИ этот файл.
 */

define('NO_KEEP_STATISTIC', 'Y');
define('NO_AGENT_STATISTIC', 'Y');
define('NO_AGENT_CHECK', true);
define('PUBLIC_AJAX_MODE', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

header('Content-Type: text/html; charset=UTF-8');

echo '<pre style="font-family: monospace; font-size: 14px; line-height: 1.5;">';

global $USER;
if (!$USER || !$USER->IsAuthorized()) {
    die('Требуется авторизация');
}

if (!Loader::includeModule('documentgenerator')) {
    die('Модуль documentgenerator не установлен');
}

global $DB;

$dealId = (int)($_GET['dealId'] ?? 0);
if ($dealId <= 0) {
    $dealId = 5739;
}

echo "=== ДИАГНОСТИКА ГЕНЕРАТОРА ДОКУМЕНТОВ ===\n";
echo "Сделка ID: {$dealId}\n\n";

// === ШАГ 1: Структура таблицы ===

echo "--- ШАГ 1: Колонки таблицы b_documentgenerator_document ---\n\n";

$rsColumns = $DB->Query("SHOW COLUMNS FROM b_documentgenerator_document");

$columns = [];
while ($arCol = $rsColumns->Fetch()) {
    $columns[$arCol['Field']] = $arCol;
    echo sprintf(
        "  %-30s %-20s %s\n",
        $arCol['Field'],
        $arCol['Type'],
        $arCol['Null'] === 'YES' ? 'NULL' : 'NOT NULL'
    );
}

echo "\nВсего колонок: " . count($columns) . "\n\n";

// === ШАГ 2: Колонки с "entity" или "type" в имени ===

echo "--- ШАГ 2: Колонки связанные с entity/type ---\n\n";

foreach ($columns as $name => $info) {
    if (stripos($name, 'entity') !== false || stripos($name, 'type') !== false) {
        echo sprintf("  %s (%s)\n", $name, $info['Type']);
    }
}

echo "\n";

// === ШАГ 3: Последние 10 документов по сделке (без фильтра типа) ===

echo "--- ШАГ 3: Документы где ENTITY_ID = {$dealId} (любой тип) ---\n\n";

// Проверяем есть ли колонка ENTITY_ID
$hasEntityId = isset($columns['ENTITY_ID']);

if ($hasEntityId) {
    $rsDocs = $DB->Query(
        "SELECT * FROM b_documentgenerator_document
         WHERE ENTITY_ID = {$dealId}
         ORDER BY ID DESC
         LIMIT 10"
    );

    $count = 0;
    while ($arDoc = $rsDocs->Fetch()) {
        $count++;
        echo "  Документ ID={$arDoc['ID']}:\n";
        foreach ($arDoc as $key => $val) {
            if ($val !== null && $val !== '') {
                echo "    {$key} = " . mb_substr((string)$val, 0, 80) . "\n";
            }
        }
        echo "\n";
    }

    if ($count === 0) {
        echo "  — документы не найдены по ENTITY_ID={$dealId}\n\n";
    }
} else {
    echo "  Колонки ENTITY_ID нет в таблице!\n";
    echo "  Проверяем все колонки с 'entity' в имени...\n\n";

    // Пробуем найти по любой колонке содержащей 'entity' и совпадающей с dealId
    foreach ($columns as $name => $info) {
        if (stripos($name, 'entity') !== false && stripos($name, 'entity_id') !== false) {
            echo "  Пробуем {$name} = {$dealId}:\n";
            $colName = $DB->ForSql($name);
            $rsTry = $DB->Query(
                "SELECT ID, TITLE, NUMBER FROM b_documentgenerator_document
                 WHERE {$colName} = {$dealId}
                 LIMIT 5"
            );
            while ($arTry = $rsTry->Fetch()) {
                echo sprintf("    ID=%s | TITLE=%s | NUMBER=%s\n", $arTry['ID'], $arTry['TITLE'], $arTry['NUMBER']);
            }
            echo "\n";
        }
    }
}

// === ШАГ 4: Последние 10 документов вообще ===

echo "--- ШАГ 4: Последние 10 документов (для анализа структуры) ---\n\n";

$rsAll = $DB->Query(
    "SELECT * FROM b_documentgenerator_document
     ORDER BY ID DESC
     LIMIT 10"
);

while ($arDoc = $rsAll->Fetch()) {
    echo "  ID={$arDoc['ID']}:\n";
    // Показываем все поля
    foreach ($arDoc as $key => $val) {
        if ($val !== null && $val !== '') {
            $display = mb_substr((string)$val, 0, 100);
            echo "    {$key} = {$display}\n";
        }
    }
    echo "\n";
}

echo "=== ДИАГНОСТИКА ЗАВЕРШЕНА ===\n";
echo "После диагностики УДАЛИ этот файл!\n";
echo '</pre>';