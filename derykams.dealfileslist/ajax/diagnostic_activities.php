<?php

/**
 * Диагностический скрипт №2 — файлы из дел (CCrmActivity) сделки.
 *
 * Запуск: открой в браузере
 * /local/modules/derykams.dealfileslist/ajax/diagnostic_activities.php?dealId=5725
 *
 * Что делает:
 *   1. Показывает все уникальные ENTITY_TYPE содержащие "Activity" в b_disk_attached_object
 *   2. Получает все дела сделки через CCrmActivity::GetList
 *   3. Для каждого дела показывает STORAGE_TYPE_ID и STORAGE_ELEMENT_IDS
 *   4. Пробует оба способа получения файлов:
 *      А) Через STORAGE_ELEMENT_IDS (десериализация)
 *      Б) Через прямой SQL по коннектору ActivityConnector
 *   5. Сравнивает результаты
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

// === ПРОВЕРКИ ===

global $USER;
if (!$USER || !$USER->IsAuthorized()) {
    die('Требуется авторизация');
}

if (!Loader::includeModule('crm')) {
    die('Модуль CRM не подключился');
}

$diskLoaded = Loader::includeModule('disk');

$dealId = (int)($_GET['dealId'] ?? 0);
if ($dealId <= 0) {
    $dealId = 5725;
}

echo "=== ДИАГНОСТИКА ФАЙЛОВ ИЗ ДЕЛ (CCrmActivity) ===\n";
echo "Сделка ID: {$dealId}\n";
echo "Модуль Disk: " . ($diskLoaded ? "загружен" : "НЕ загружен") . "\n\n";

global $DB;

// === ШАГ 1: Коннекторы с "Activity" в имени ===

echo "--- ШАГ 1: ENTITY_TYPE содержащие 'Activity' в b_disk_attached_object ---\n\n";

$rsTypes = $DB->Query(
    "SELECT ENTITY_TYPE, COUNT(*) as CNT
     FROM b_disk_attached_object
     WHERE ENTITY_TYPE LIKE '%Activity%'
     GROUP BY ENTITY_TYPE
     ORDER BY CNT DESC"
);

$activityConnectors = [];
$found1 = false;
while ($arType = $rsTypes->Fetch()) {
    $found1 = true;
    echo sprintf(
        "  %-70s — %d записей\n",
        $arType['ENTITY_TYPE'],
        (int)$arType['CNT']
    );
    $activityConnectors[] = $arType['ENTITY_TYPE'];
}

if (!$found1) {
    echo "  — коннекторы с 'Activity' не найдены\n";
    // Покажем все коннекторы с CRM для сравнения
    echo "  Все коннекторы с 'Crm' в имени:\n";
    $rsCrm = $DB->Query(
        "SELECT ENTITY_TYPE, COUNT(*) as CNT
         FROM b_disk_attached_object
         WHERE ENTITY_TYPE LIKE '%Crm%'
         GROUP BY ENTITY_TYPE
         ORDER BY CNT DESC"
    );
    while ($arCrm = $rsCrm->Fetch()) {
        echo sprintf(
            "    %-70s — %d записей\n",
            $arCrm['ENTITY_TYPE'],
            (int)$arCrm['CNT']
        );
        $activityConnectors[] = $arCrm['ENTITY_TYPE'];
    }
}

echo "\n";

// === ШАГ 2: Получаем все дела сделки ===

echo "--- ШАГ 2: Дела сделки {$dealId} через CCrmActivity::GetList ---\n\n";

$rsActivities = \CCrmActivity::GetList(
    ['ID' => 'ASC'],
    [
        'OWNER_TYPE_ID' => \CCrmOwnerType::Deal,
        'OWNER_ID' => $dealId,
        'CHECK_PERMISSIONS' => 'N'
    ],
    false, false,
    ['ID', 'TYPE_ID', 'SUBJECT', 'STORAGE_TYPE_ID', 'STORAGE_ELEMENT_IDS', 'COMPLETED']
);

$activityIds = [];
$activityData = [];

while ($arActivity = $rsActivities->Fetch()) {
    $activityId = (int)$arActivity['ID'];
    $activityIds[] = $activityId;

    $storageType = (int)($arActivity['STORAGE_TYPE_ID'] ?? 0);
    $rawIds = $arActivity['STORAGE_ELEMENT_IDS'] ?? '';

    // Пытаемся десериализовать STORAGE_ELEMENT_IDS
    $elementIds = @unserialize($rawIds);
    if (!is_array($elementIds)) {
        $elementIds = [];
    }

    // Тип дела
    $typeNames = [
        1 => 'Встреча',
        2 => 'Звонок',
        3 => 'Задача',
        4 => 'Email',
        7 => 'Комментарий(?)',
    ];
    $typeStr = $typeNames[$arActivity['TYPE_ID']] ?? ('Тип ' . $arActivity['TYPE_ID']);

    echo sprintf(
        "  ID: %-8s | Тип: %-10s | Storage: %d | Элементов: %d | Subject: %s\n",
        $activityId,
        $typeStr,
        $storageType,
        count($elementIds),
        mb_substr((string)($arActivity['SUBJECT'] ?? ''), 0, 50)
    );

    if (!empty($elementIds)) {
        echo "    STORAGE_ELEMENT_IDS: [" . implode(', ', array_map('intval', $elementIds)) . "]\n";
    }

    $activityData[$activityId] = [
        'storage_type' => $storageType,
        'element_ids' => $elementIds,
        'subject' => $arActivity['SUBJECT'] ?? '',
        'type_id' => $arActivity['TYPE_ID'] ?? 0,
    ];
}

echo "\n";
echo "Всего дел: " . count($activityIds) . "\n\n";

if (empty($activityIds)) {
    echo "Дел не найдено — диагностика завершена.\n";
    echo '</pre>';
    die();
}

// === ШАГ 3: Способ А — через STORAGE_ELEMENT_IDS ===

echo "--- ШАГ 3: Способ А — через STORAGE_ELEMENT_IDS (десериализация) ---\n\n";

$storageTypeCount = [];
$methodA_files = [];

foreach ($activityData as $activityId => $data) {
    $storageType = $data['storage_type'];
    $elementIds = $data['element_ids'];

    if (empty($elementIds)) {
        continue;
    }

    $storageTypeCount[$storageType] = ($storageTypeCount[$storageType] ?? 0) + 1;

    echo "  Дело ID {$activityId} (Storage={$storageType}):\n";

    foreach ($elementIds as $elementId) {
        $elementId = (int)$elementId;
        if ($elementId <= 0) {
            continue;
        }

        if ($storageType === 1 && $diskLoaded) {
            // Disk: elementId = ID в b_disk_attached_object
            echo "    Disk attached_object ID: {$elementId}\n";

            $rsAtt = $DB->Query(
                "SELECT a.ID, a.OBJECT_ID, a.ENTITY_TYPE, a.ENTITY_ID,
                        o.FILE_ID, o.NAME as DISK_NAME,
                        f.ORIGINAL_NAME, f.CONTENT_TYPE, f.FILE_SIZE
                 FROM b_disk_attached_object a
                 LEFT JOIN b_disk_object o ON o.ID = a.OBJECT_ID
                 LEFT JOIN b_file f ON f.ID = o.FILE_ID
                 WHERE a.ID = {$elementId}"
            );

            $arAtt = $rsAtt ? $rsAtt->Fetch() : null;
            if ($arAtt) {
                echo sprintf(
                    "      -> ENTITY_TYPE=%s | OBJECT_ID=%s | FILE_ID=%s | %s (%d bytes)\n",
                    $arAtt['ENTITY_TYPE'],
                    $arAtt['OBJECT_ID'],
                    $arAtt['FILE_ID'],
                    $arAtt['ORIGINAL_NAME'] ?: $arAtt['DISK_NAME'] ?: '?',
                    (int)$arAtt['FILE_SIZE']
                );
                $methodA_files[] = $arAtt;
            } else {
                echo "      -> НЕ найден в b_disk_attached_object\n";
            }
        } elseif ($storageType === 3) {
            // File: elementId = ID в b_file
            echo "    Element ID: {$elementId}\n";

            // Сначала проверяем b_file
            $rsFile = \CFile::GetByID($elementId);
            $arFile = $rsFile ? $rsFile->Fetch() : null;
            if ($arFile) {
                echo sprintf(
                    "      -> b_file: %s (%s, %d bytes)\n",
                    $arFile['ORIGINAL_NAME'] ?: $arFile['FILE_NAME'],
                    $arFile['CONTENT_TYPE'],
                    (int)$arFile['FILE_SIZE']
                );
                $methodA_files[] = $arFile;
            } else {
                echo "      -> НЕ найден в b_file, проверяем b_disk_object...\n";

                // Может это ID disk_object?
                $rsObj = $DB->Query(
                    "SELECT o.ID, o.FILE_ID, o.NAME, o.TYPE,
                            f.ORIGINAL_NAME, f.CONTENT_TYPE, f.FILE_SIZE
                     FROM b_disk_object o
                     LEFT JOIN b_file f ON f.ID = o.FILE_ID
                     WHERE o.ID = {$elementId}"
                );
                $arObj = $rsObj ? $rsObj->Fetch() : null;
                if ($arObj) {
                    echo sprintf(
                        "      -> b_disk_object: ID=%s | FILE_ID=%s | NAME=%s | TYPE=%s\n",
                        $arObj['ID'],
                        $arObj['FILE_ID'],
                        $arObj['NAME'],
                        $arObj['TYPE']
                    );
                    if ($arObj['FILE_ID']) {
                        echo sprintf(
                            "         file: %s (%s, %d bytes)\n",
                            $arObj['ORIGINAL_NAME'] ?: '?',
                            $arObj['CONTENT_TYPE'] ?: '?',
                            (int)$arObj['FILE_SIZE']
                        );
                        $methodA_files[] = $arObj;
                    }
                } else {
                    echo "      -> НЕ найден и в b_disk_object\n";

                    // Проверяем b_disk_attached_object
                    $rsAtt2 = $DB->Query(
                        "SELECT a.ID, a.OBJECT_ID, a.ENTITY_TYPE, a.ENTITY_ID,
                                o.FILE_ID, o.NAME as DISK_NAME,
                                f.ORIGINAL_NAME, f.CONTENT_TYPE, f.FILE_SIZE
                         FROM b_disk_attached_object a
                         LEFT JOIN b_disk_object o ON o.ID = a.OBJECT_ID
                         LEFT JOIN b_file f ON f.ID = o.FILE_ID
                         WHERE a.ID = {$elementId}"
                    );
                    $arAtt2 = $rsAtt2 ? $rsAtt2->Fetch() : null;
                    if ($arAtt2) {
                        echo sprintf(
                            "      -> b_disk_attached_object: ID=%s | OBJECT_ID=%s | ENTITY_TYPE=%s | FILE_ID=%s | %s (%d bytes)\n",
                            $arAtt2['ID'],
                            $arAtt2['OBJECT_ID'],
                            $arAtt2['ENTITY_TYPE'],
                            $arAtt2['FILE_ID'],
                            $arAtt2['ORIGINAL_NAME'] ?: $arAtt2['DISK_NAME'] ?: '?',
                            (int)$arAtt2['FILE_SIZE']
                        );
                        $methodA_files[] = $arAtt2;
                    } else {
                        echo "      -> НЕ найден нигде (b_file, b_disk_object, b_disk_attached_object)\n";
                    }
                }
            }
        } else {
            echo "    Неизвестный storage type={$storageType}, elementId={$elementId}\n";
            // Всё равно проверяем все три таблицы
            echo "    Проверяем b_disk_object и b_disk_attached_object...\n";
            $rsObj = $DB->Query(
                "SELECT o.ID, o.FILE_ID, o.NAME
                 FROM b_disk_object o
                 WHERE o.ID = {$elementId}"
            );
            $arObj = $rsObj ? $rsObj->Fetch() : null;
            if ($arObj) {
                echo sprintf("      -> b_disk_object: FILE_ID=%s, NAME=%s\n", $arObj['FILE_ID'], $arObj['NAME']);
            }
        }
    }
}

echo "\n";
echo "Распределение по STORAGE_TYPE_ID:\n";
foreach ($storageTypeCount as $type => $count) {
    $typeDesc = [
        0 => 'не указано',
        1 => 'Disk',
        2 => 'Webdav (устаревший)',
        3 => 'File (b_file напрямую)',
    ];
    echo sprintf("  Type %d (%s): %d дел с файлами\n", $type, $typeDesc[$type] ?? '?', $count);
}

echo sprintf("Способ А: найдено %d файлов\n\n", count($methodA_files));

// === ШАГ 4: Способ Б — прямой SQL по коннекторам Activity ===

echo "--- ШАГ 4: Способ Б — прямой SQL по коннекторам ActivityConnector ---\n\n";

if (!empty($activityIds) && !empty($activityConnectors)) {
    $idsStr = implode(',', array_map('intval', $activityIds));

    foreach ($activityConnectors as $connector) {
        // Экранируем обратные слеши для SQL
        $connectorEscaped = str_replace('\\', '\\\\\\\\', $connector);

        echo "  Коннектор: {$connector}\n";

        $rsSql = $DB->Query(
            "SELECT a.ID as ATTACHED_ID, a.ENTITY_ID as ACTIVITY_ID,
                    o.FILE_ID, o.NAME as DISK_NAME,
                    f.ORIGINAL_NAME, f.CONTENT_TYPE, f.FILE_SIZE
             FROM b_disk_attached_object a
             LEFT JOIN b_disk_object o ON o.ID = a.OBJECT_ID
             LEFT JOIN b_file f ON f.ID = o.FILE_ID
             WHERE a.ENTITY_TYPE = '{$connectorEscaped}'
             AND a.ENTITY_ID IN ({$idsStr})"
        );

        $methodB_count = 0;
        while ($arSql = $rsSql->Fetch()) {
            $methodB_count++;
            echo sprintf(
            "    ATTACHED_ID=%-8s | ACTIVITY_ID=%-8s | FILE_ID=%-8s | %s (%d bytes)\n",
                $arSql['ATTACHED_ID'],
                $arSql['ACTIVITY_ID'],
                $arSql['FILE_ID'],
                $arSql['ORIGINAL_NAME'] ?: $arSql['DISK_NAME'] ?: '?',
                (int)$arSql['FILE_SIZE']
            );
        }

        if ($methodB_count === 0) {
            echo "    — не найдено по этому коннектору\n";
        }
        echo "\n";
    }
} else {
    echo "  — нет ID дел или нет коннекторов для проверки\n\n";
}

// === ШАГ 5: Сводка ===

echo "--- ШАГ 5: СВОДКА ---\n\n";
echo sprintf("  Дел всего: %d\n", count($activityIds));
echo sprintf("  Способ А (STORAGE_ELEMENT_IDS): %d файлов\n", count($methodA_files));
echo sprintf("  Коннекторы Activity найдены: %s\n", !empty($activityConnectors) ? implode(', ', $activityConnectors) : 'нет');
echo "\n";
echo "Рекомендация:\n";
echo "  Если Способ А нашёл файлы — используем его (универсальный, работает с любым storage type)\n";
echo "  Если Способ Б нашёл файлы — используем прямой SQL по коннектору (проще, как с комментариями)\n";
echo "  Если оба не нашли — возможно дела используют другой способ хранения файлов\n";
echo "  или STORAGE_ELEMENT_IDS хранит не attached_object ID, а disk_object ID\n";
echo "\n";
echo "=== ДИАГНОСТИКА ЗАВЕРШЕНА ===\n";
echo "После диагностики УДАЛИ этот файл!\n";
echo '</pre>';