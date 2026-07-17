<?php

/**
 * Диагностический скрипт для определения коннекторов Disk\AttachedObject.
 *
 * Запуск: открой в браузере
 * /local/modules/derykams.dealfileslist/ajax/diagnostic.php?dealId=5725
 *
 * Что делает:
 *   1. Показывает все уникальные ENTITY_TYPE из b_disk_attached_object
 *   2. Получает записи таймлайна сделки (TYPE_ID=7 — комментарии)
 *   3. Для каждого комментария ищет attached objects по его ID
 *   4. Показывает что найдено — коннектор, file_id, имя файла
 *
 * После диагностики УДАЛИ этот файл — он не должен оставаться на проде.
 */

define('NO_KEEP_STATISTIC', 'Y');
define('NO_AGENT_STATISTIC', 'Y');
define('NO_AGENT_CHECK', true);
define('PUBLIC_AJAX_MODE', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;
use Bitrix\Crm\Timeline\Entity\TimelineTable;
use Bitrix\Disk\Internals\AttachedObjectTable;

header('Content-Type: text/html; charset=UTF-8');

echo '<pre style="font-family: monospace; font-size: 14px; line-height: 1.5;">';

// === ПРОВЕРКИ ===

global $USER;
if (!$USER || !$USER->IsAuthorized()) {
    die('Требуется авторизация');
}

if (!Loader::includeModule('crm')) {
    die('Модуль CRM не подключился');
}

if (!Loader::includeModule('disk')) {
    die('Модуль Disk не подключился');
}

$dealId = (int)($_GET['dealId'] ?? 0);
if ($dealId <= 0) {
    $dealId = 5725; // по умолчанию — сделка из логов
}

echo "=== ДИАГНОСТИКА DISK ATTACHED OBJECTS ===\n";
echo "Сделка ID: {$dealId}\n\n";

// === ШАГ 1: Все уникальные ENTITY_TYPE в b_disk_attached_object ===

echo "--- ШАГ 1: Все уникальные ENTITY_TYPE в b_disk_attached_object ---\n\n";

global $DB;
$rsTypes = $DB->Query(
    "SELECT ENTITY_TYPE, COUNT(*) as CNT FROM b_disk_attached_object GROUP BY ENTITY_TYPE ORDER BY CNT DESC"
);

while ($arType = $rsTypes->Fetch()) {
    echo sprintf(
        "  %-60s — %d записей\n",
        $arType['ENTITY_TYPE'],
        (int)$arType['CNT']
    );
}

echo "\n";

// === ШАГ 2: Записи таймлайна сделки (комментарии) ===

echo "--- ШАГ 2: Комментарии в таймлайне сделки {$dealId} ---\n\n";

$timelineEntries = TimelineTable::getList([
    'filter' => [
        '=TYPE_ID' => 7, // TimelineType::COMMENT
        'BINDINGS.ENTITY_ID' => $dealId,
        'BINDINGS.ENTITY_TYPE_ID' => \CCrmOwnerType::Deal
    ],
    'select' => [
        'ID', 'COMMENT', 'CREATED', 'AUTHOR_ID', 'SETTINGS'
    ],
    'order' => ['ID' => 'DESC']
]);

$commentIds = [];
while ($ar = $timelineEntries->Fetch()) {
    $settings = $ar['SETTINGS'];
    if (!is_array($settings)) {
        $settings = [];
    }

    $hasFiles = $settings['HAS_FILES'] ?? 'N';

    // CREATED — это Bitrix\Main\Type\DateTime, приводим к строке
    $createdStr = '';
    if ($ar['CREATED'] instanceof \Bitrix\Main\Type\DateTime) {
        $createdStr = $ar['CREATED']->format('d.m.Y H:i:s');
    }

    echo sprintf(
        "  ID: %-8s | HAS_FILES: %-3s | CREATED: %-19s | COMMENT: %s\n",
        $ar['ID'],
        $hasFiles,
        $createdStr,
        mb_substr((string)$ar['COMMENT'], 0, 60)
    );

    $commentIds[] = (int)$ar['ID'];
}

echo "\n";
echo "Всего комментариев: " . count($commentIds) . "\n\n";

if (empty($commentIds)) {
    echo "Комментариев не найдено — диагностика завершена.\n";
    echo '</pre>';
    die();
}

// === ШАГ 3: Поиск attached objects по коннектору CommentConnector ===

echo "--- ШАГ 3: Поиск attached objects для сделки {$dealId} ---\n\n";

/*
    Коннектор для комментариев CRM: Bitrix\Crm\Integration\Disk\CommentConnector
    ENTITY_ID в b_disk_attached_object = ID записи в TimelineTable

    Сначала получаем список полей AttachedObjectTable,
    чтобы узнать правильное имя поля для file id.
*/

// Узнаём поля таблицы через getMap()
echo "  Поля AttachedObjectTable:\n";
$map = AttachedObjectTable::getMap();
foreach ($map as $fieldName => $fieldInfo) {
    // В D7 ORM getMap() может возвращать массив полей
    // где ключ — имя поля, значение — объект Field или строка
    echo "    {$fieldName}\n";
}
echo "\n";

// Получаем ID комментариев сделки из TimelineTable
// и ищем attached objects по коннектору CommentConnector

$commentConnector = 'Bitrix\\Crm\\Integration\\Disk\\CommentConnector';

foreach ($commentIds as $commentId) {
    echo "  Комментарий ID {$commentId}:\n";

    // Пробуем найти attached objects
    // ENTITY_ID = ID записи таймлайна (комментария)
    try {
        $rsAttached = AttachedObjectTable::getList([
            'select' => ['ID', 'OBJECT_ID', 'ENTITY_TYPE', 'ENTITY_ID', 'MODULE_ID'],
            'filter' => [
                '=ENTITY_TYPE' => $commentConnector,
                '=ENTITY_ID'   => $commentId
            ]
        ]);

        $found = false;
        while ($arAttached = $rsAttached->Fetch()) {
            $found = true;
            echo sprintf(
                "    FOUND! attachedId=%-8s | OBJECT_ID=%-8s | ENTITY_ID=%-8s\n",
                $arAttached['ID'],
                $arAttached['OBJECT_ID'],
                $arAttached['ENTITY_ID']
            );

            // OBJECT_ID -> b_disk_object -> FILE_ID -> b_file
            $objectId = (int)$arAttached['OBJECT_ID'];
            if ($objectId > 0) {
                $rsObj = $DB->Query(
                    "SELECT ID, FILE_ID, NAME FROM b_disk_object WHERE ID = {$objectId}"
                );
                $arObj = $rsObj ? $rsObj->Fetch() : null;
                if ($arObj) {
                    echo sprintf(
                        "      disk_object: ID=%-8s | FILE_ID=%-8s | NAME=%s\n",
                        $arObj['ID'],
                        $arObj['FILE_ID'],
                        $arObj['NAME']
                    );

                    if ($arObj['FILE_ID']) {
                        $rsFile = \CFile::GetByID((int)$arObj['FILE_ID']);
                        $arFile = $rsFile ? $rsFile->Fetch() : null;
                        if ($arFile) {
                            echo sprintf(
                                "      FILE: %s (%s, %d bytes)\n",
                                $arFile['ORIGINAL_NAME'],
                                $arFile['CONTENT_TYPE'],
                                (int)$arFile['FILE_SIZE']
                            );
                        }
                    }
                }
            }
        }

        if (!$found) {
            echo "    — attached objects не найдены по ENTITY_ID={$commentId}\n";
        }
    } catch (\Throwable $e) {
        echo "    ОШИБКА: " . $e->getMessage() . "\n";
    }

    echo "\n";
}

// === ШАГ 3b: Прямой SQL — правильная цепочка ===

echo "--- ШАГ 3b: SQL по коннектору CommentConnector для сделки {$dealId} ---\n\n";

$commentIdsStr = implode(',', array_map('intval', $commentIds));

if ($commentIdsStr !== '') {
    // Цепочка: b_disk_attached_object -> b_disk_object -> b_file
    $rsSql = $DB->Query(
        "SELECT a.ID, a.ENTITY_ID, a.MODULE_ID, a.OBJECT_ID,
                o.FILE_ID, o.NAME as DISK_NAME,
                f.ORIGINAL_NAME, f.CONTENT_TYPE, f.FILE_SIZE
         FROM b_disk_attached_object a
         LEFT JOIN b_disk_object o ON o.ID = a.OBJECT_ID
         LEFT JOIN b_file f ON f.ID = o.FILE_ID
         WHERE a.ENTITY_TYPE = 'Bitrix\\\\Crm\\\\Integration\\\\Disk\\\\CommentConnector'
         AND a.ENTITY_ID IN ({$commentIdsStr})"
    );

    $sqlFound = false;
    while ($arSql = $rsSql->Fetch()) {
        $sqlFound = true;
        echo sprintf(
            "  attachedId=%-8s | OBJECT_ID=%-8s | FILE_ID=%-8s | %s (%s, %d bytes)\n",
            $arSql['ID'],
            $arSql['OBJECT_ID'],
            $arSql['FILE_ID'],
            $arSql['ORIGINAL_NAME'] ?: $arSql['DISK_NAME'],
            $arSql['CONTENT_TYPE'],
            (int)$arSql['FILE_SIZE']
        );
    }

    if (!$sqlFound) {
        echo "  — не найдено по ID комментариев сделки {$dealId}\n\n";

        // Последние 10 с этим коннектором — с JOIN для полноты
        echo "  Последние 10 записей CommentConnector (с JOIN b_disk_object + b_file):\n";
        $rsSample = $DB->Query(
            "SELECT a.ID, a.ENTITY_ID, a.OBJECT_ID,
                    o.FILE_ID, o.NAME as DISK_NAME,
                    f.ORIGINAL_NAME, f.CONTENT_TYPE, f.FILE_SIZE
             FROM b_disk_attached_object a
             LEFT JOIN b_disk_object o ON o.ID = a.OBJECT_ID
             LEFT JOIN b_file f ON f.ID = o.FILE_ID
             WHERE a.ENTITY_TYPE = 'Bitrix\\\\Crm\\\\Integration\\\\Disk\\\\CommentConnector'
             ORDER BY a.ID DESC
             LIMIT 10"
        );
        while ($arSample = $rsSample->Fetch()) {
            echo sprintf(
                "    attachedId=%-8s | ENTITY_ID=%-8s | OBJECT_ID=%-8s | FILE_ID=%-8s | %s (%d bytes)\n",
                $arSample['ID'],
                $arSample['ENTITY_ID'],
                $arSample['OBJECT_ID'],
                $arSample['FILE_ID'],
                $arSample['ORIGINAL_NAME'] ?: $arSample['DISK_NAME'] ?: '?',
                (int)$arSample['FILE_SIZE']
            );
        }
    }
}

echo "\n";

// === ШАГ 4: Последние 10 с коннектором CommentConnector + JOIN ===

echo "--- ШАГ 4: Последние 10 CommentConnector с JOIN (для анализа структуры) ---\n\n";

$rsCrm = $DB->Query(
    "SELECT a.ID, a.ENTITY_TYPE, a.ENTITY_ID, a.OBJECT_ID,
            o.FILE_ID, o.NAME as DISK_NAME,
            f.ORIGINAL_NAME, f.CONTENT_TYPE, f.FILE_SIZE
     FROM b_disk_attached_object a
     LEFT JOIN b_disk_object o ON o.ID = a.OBJECT_ID
     LEFT JOIN b_file f ON f.ID = o.FILE_ID
     WHERE a.ENTITY_TYPE = 'Bitrix\\\\Crm\\\\Integration\\\\Disk\\\\CommentConnector'
     ORDER BY a.ID DESC
     LIMIT 10"
);

while ($arCrm = $rsCrm->Fetch()) {
    echo sprintf(
        "  attachedId=%-8s | ENTITY_ID=%-8s | OBJECT_ID=%-8s | FILE_ID=%-8s | %s (%d bytes)\n",
        $arCrm['ID'],
        $arCrm['ENTITY_ID'],
        $arCrm['OBJECT_ID'],
        $arCrm['FILE_ID'],
        $arCrm['ORIGINAL_NAME'] ?: $arCrm['DISK_NAME'] ?: '?',
        (int)$arCrm['FILE_SIZE']
    );
}

echo "\n=== ДИАГНОСТИКА ЗАВЕРШЕНА ===\n";
echo "После диагностики УДАЛИ этот файл!\n";
echo '</pre>';