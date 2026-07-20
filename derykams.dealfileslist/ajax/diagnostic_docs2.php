<?php

/**
 * Диагностический скрипт №4 — проверка SQL-запроса документов.
 *
 * Запуск: открой в браузере
 * /local/modules/derykams.dealfileslist/ajax/diagnostic_docs2.php?dealId=5739
 *
 * Проверяет разные варианты SQL-запроса и показывает какой работает.
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

echo "=== ДИАГНОСТИКА SQL-ЗАПРОСА ДОКУМЕНТОВ ===\n";
echo "Сделка ID: {$dealId}\n\n";

// === Тест 1: Только VALUE = dealId (без фильтра PROVIDER) ===

echo "--- Тест 1: VALUE = {$dealId} (без фильтра PROVIDER) ---\n\n";

$rs1 = $DB->Query(
    "SELECT ID, TITLE, NUMBER, FILE_ID, PDF_ID, PROVIDER, VALUE, CREATE_TIME
     FROM b_documentgenerator_document
     WHERE VALUE = {$dealId}
     ORDER BY ID DESC"
);

$count1 = 0;
while ($ar1 = $rs1->Fetch()) {
    $count1++;
    echo sprintf(
        "  ID=%-5s | TITLE=%s | NUMBER=%s | FILE_ID=%s | PDF_ID=%s | PROVIDER=%s\n",
        $ar1['ID'],
        $ar1['TITLE'],
        $ar1['NUMBER'],
        $ar1['FILE_ID'],
        $ar1['PDF_ID'] ?? 'NULL',
        $ar1['PROVIDER']
    );
}

if ($count1 === 0) {
    echo "  — не найдено по VALUE={$dealId}\n";
}
echo "\nНайдено: {$count1}\n\n";

// === Тест 2: С фильтром PROVIDER LIKE (текущий вариант) ===

echo "--- Тест 2: VALUE={$dealId} AND PROVIDER LIKE '%\\\\deal' ---\n\n";

$rs2 = $DB->Query(
    "SELECT ID, TITLE, NUMBER, FILE_ID, PDF_ID, PROVIDER
     FROM b_documentgenerator_document
     WHERE VALUE = {$dealId}
     AND PROVIDER LIKE '%\\\\deal'
     ORDER BY ID DESC"
);

$count2 = 0;
while ($ar2 = $rs2->Fetch()) {
    $count2++;
    echo sprintf("  ID=%-5s | TITLE=%s | PROVIDER=%s\n", $ar2['ID'], $ar2['TITLE'], $ar2['PROVIDER']);
}

if ($count2 === 0) {
    echo "  — не найдено\n";
}
echo "\nНайдено: {$count2}\n\n";

// === Тест 3: С фильтром PROVIDER без LIKE (точное совпадение) ===

echo "--- Тест 3: VALUE={$dealId} AND PROVIDER = 'bitrix\\crm\\integration\\documentgenerator\\dataprovider\\deal' ---\n\n";

$rs3 = $DB->Query(
    "SELECT ID, TITLE, NUMBER, FILE_ID, PDF_ID, PROVIDER
     FROM b_documentgenerator_document
     WHERE VALUE = {$dealId}
     AND PROVIDER = 'bitrix\\\\crm\\\\integration\\\\documentgenerator\\\\dataprovider\\\\deal'
     ORDER BY ID DESC"
);

$count3 = 0;
while ($ar3 = $rs3->Fetch()) {
    $count3++;
    echo sprintf("  ID=%-5s | TITLE=%s | PROVIDER=%s\n", $ar3['ID'], $ar3['TITLE'], $ar3['PROVIDER']);
}

if ($count3 === 0) {
    echo "  — не найдено\n";
}
echo "\nНайдено: {$count3}\n\n";

// === Тест 4: Все уникальные PROVIDER в таблице ===

echo "--- Тест 4: Все уникальные PROVIDER ---\n\n";

$rs4 = $DB->Query(
    "SELECT PROVIDER, COUNT(*) as CNT
     FROM b_documentgenerator_document
     GROUP BY PROVIDER
     ORDER BY CNT DESC"
);

while ($ar4 = $rs4->Fetch()) {
    echo sprintf("  %-80s — %d документов\n", $ar4['PROVIDER'], (int)$ar4['CNT']);
}

echo "\n";

// === Тест 5: Все документы с VALUE = dealId, любой PROVIDER ===

echo "--- Тест 5: Все документы где VALUE = {$dealId} (raw hex PROVIDER) ---\n\n";

$rs5 = $DB->Query(
    "SELECT ID, TITLE, NUMBER, FILE_ID, PDF_ID, PROVIDER, HEX(PROVIDER) as PROVIDER_HEX
     FROM b_documentgenerator_document
     WHERE VALUE = {$dealId}
     ORDER BY ID DESC"
);

$count5 = 0;
while ($ar5 = $rs5->Fetch()) {
    $count5++;
    echo sprintf("  ID=%-5s | TITLE=%s | FILE_ID=%s | PDF_ID=%s\n", $ar5['ID'], $ar5['TITLE'], $ar5['FILE_ID'], $ar5['PDF_ID'] ?? 'NULL');
    echo "    PROVIDER = {$ar5['PROVIDER']}\n";
    echo "    HEX      = {$ar5['PROVIDER_HEX']}\n";
}

if ($count5 === 0) {
    echo "  — документы не найдены по VALUE={$dealId}\n";
    echo "  Возможно документ привязан к другому ID сделки\n";
}

echo "\nНайдено: {$count5}\n\n";

// === Тест 6: Проверяем VALUE как строку ===

echo "--- Тест 6: VALUE = '{$dealId}' как строка ---\n\n";

$rs6 = $DB->Query(
    "SELECT ID, TITLE, NUMBER, VALUE
     FROM b_documentgenerator_document
     WHERE VALUE = '{$dealId}'
     ORDER BY ID DESC
     LIMIT 5"
);

$count6 = 0;
while ($ar6 = $rs6->Fetch()) {
    $count6++;
    echo sprintf("  ID=%-5s | TITLE=%s | VALUE=%s\n", $ar6['ID'], $ar6['TITLE'], $ar6['VALUE']);
}

if ($count6 === 0) {
    echo "  — не найдено по строковому VALUE='{$dealId}'\n";
}
echo "\nНайдено: {$count6}\n\n";

echo "=== ДИАГНОСТИКА ЗАВЕРШЕНА ===\n";
echo "После диагностики УДАЛИ этот файл!\n";
echo '</pre>';