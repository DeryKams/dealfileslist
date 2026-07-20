<?php

/**
 * AJAX-эндпоинт для получения списка файлов, прикреплённых к сделке.
 *
 * Принимает: POST dealId (int), sessid (string)
 * Возвращает: JSON { status, dealId, files: [...] }
 *
 * Источники файлов:
 *   1. Пользовательские поля сделки типа "Файл" (UF_CRM_*)
 *   2. Комментарии в таймлайне сделки (Disk\CommentConnector)
 *   3. Дела (активности) сделки (STORAGE_ELEMENT_IDS → b_disk_object)
 *   4. Сгенерированные документы (модуль documentgenerator)
 *
 * Каждый источник обёрнут в свой try-catch — если один падает,
 * остальные продолжают работать.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('NO_KEEP_STATISTIC', 'Y');
define('NO_AGENT_STATISTIC', 'Y');
define('NO_AGENT_CHECK', true);
define('PUBLIC_AJAX_MODE', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

header('Content-Type: application/json; charset=UTF-8');

/**
 * Отправляет JSON-ответ и завершает выполнение.
 */
function dflSendJson(array $data, int $httpStatus = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    die();
}

/**
 * Форматирует размер файла в человекочитаемый вид.
 */
function dflFormatFileSize(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $pow = floor(log($bytes) / log(1024));
    $pow = min($pow, count($units) - 1);

    $size = $bytes / pow(1024, $pow);
    $precision = $pow > 0 ? 1 : 0;

    return round($size, $precision) . ' ' . $units[$pow];
}

/**
 * Форматирует дату из формата Битрикс (YYYY-MM-DD HH:MM:SS)
 * в простой формат DD.MM.YYYY.
 */
function dflFormatDate(string $bitrixDate): string
{
    $timestamp = strtotime($bitrixDate);
    if ($timestamp === false) {
        return '';
    }

    return date('d.m.Y', $timestamp);
}

/**
 * Извлекает расширение файла из имени.
 * Возвращает в нижнем регистре, до 5 символов.
 */
function dflGetFileExtension(string $fileName): string
{
    $dotPos = strrpos($fileName, '.');
    if ($dotPos === false) {
        return '';
    }

    $ext = strtolower(substr($fileName, $dotPos + 1));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext);

    return ($ext !== '' && strlen($ext) <= 5) ? $ext : '';
}

// === ОСНОВНАЯ ЛОГИКА ===

try {
    // Проверка авторизации
    global $USER;
    if (!$USER || !$USER->IsAuthorized()) {
        dflSendJson([
            'status'  => 'error',
            'message' => 'Пользователь не авторизован',
        ], 401);
    }

    // Проверка сессии
    if (!check_bitrix_sessid()) {
        dflSendJson([
            'status'  => 'error',
            'message' => 'Некорректная сессия',
        ], 403);
    }

    // Подключаем модуль CRM
    if (!Loader::includeModule('crm')) {
        dflSendJson([
            'status'  => 'error',
            'message' => 'Модуль CRM не подключился',
        ], 500);
    }

    $dealId = (int)($_POST['dealId'] ?? 0);

    if ($dealId <= 0) {
        dflSendJson([
            'status'  => 'error',
            'message' => 'Не передан ID сделки',
        ], 400);
    }

    // Проверяем существование сделки
    $deal = \CCrmDeal::GetByID($dealId, false);
    if (!$deal || !is_array($deal)) {
        dflSendJson([
            'status'  => 'error',
            'message' => 'Сделка не найдена',
        ], 404);
    }

    $files = [];

    // ========================================
    // ИСТОЧНИК 1: UF-поля сделки типа "Файл"
    // ========================================

    try {
        $fileFieldCodes = [];

        $rsFields = \CUserTypeEntity::GetList(
            ['SORT' => 'ASC'],
            ['ENTITY_ID' => 'CRM_DEAL']
        );

        if ($rsFields) {
            while ($arField = $rsFields->Fetch()) {
                if (($arField['USER_TYPE_ID'] ?? '') === 'file') {
                    $fileFieldCodes[] = $arField['FIELD_NAME'];
                }
            }
        }

        if (!empty($fileFieldCodes)) {
            global $USER_FIELD_MANAGER;

            $dealUserFields = $USER_FIELD_MANAGER->GetUserFields('CRM_DEAL', $dealId);
            if (!is_array($dealUserFields)) {
                $dealUserFields = [];
            }

            $ufFileIds = [];
            foreach ($fileFieldCodes as $code) {
                if (!isset($dealUserFields[$code])) {
                    continue;
                }

                $value = $dealUserFields[$code]['VALUE'] ?? null;

                if ($value === null || $value === '' || $value === false) {
                    continue;
                }

                if (is_array($value)) {
                    foreach ($value as $singleId) {
                        $id = (int)$singleId;
                        if ($id > 0) {
                            $ufFileIds[] = $id;
                        }
                    }
                } else {
                    $id = (int)$value;
                    if ($id > 0) {
                        $ufFileIds[] = $id;
                    }
                }
            }

            $ufFileIds = array_values(array_unique($ufFileIds));

            foreach ($ufFileIds as $fileId) {
                $rsFile = \CFile::GetByID($fileId);
                $arFile = $rsFile ? $rsFile->Fetch() : null;

                if (!$arFile) {
                    continue;
                }

                $originalName = $arFile['ORIGINAL_NAME'] ?? '';
                $fileName     = $arFile['FILE_NAME'] ?? '';
                $displayName  = ($originalName !== '') ? $originalName : $fileName;

                $downloadUrl = '/local/modules/derykams.dealfileslist/ajax/download.php'
                    . '?dealId=' . $dealId
                    . '&fileId=' . $fileId
                    . '&sessid=' . bitrix_sessid();

                $files[] = [
                    'id'        => $fileId,
                    'name'      => $displayName,
                    'size'      => dflFormatFileSize((int)($arFile['FILE_SIZE'] ?? 0)),
                    'mime'      => $arFile['CONTENT_TYPE'] ?? 'application/octet-stream',
                    'date'      => dflFormatDate($arFile['TIMESTAMP_X'] ?? ''),
                    'url'       => $downloadUrl,
                    'source'    => 'Поле сделки',
                    'extension' => dflGetFileExtension($displayName),
                ];
            }
        }
    } catch (\Throwable $e) {
        // Ошибка в источнике 1 — не убиваем остальные
    }

    // ========================================
    // ИСТОЧНИК 2: Файлы из комментариев таймлайна
    // ========================================

    try {
        if (Loader::includeModule('disk')) {
            $timelineEntries = \Bitrix\Crm\Timeline\Entity\TimelineTable::getList([
                'filter' => [
                    '=TYPE_ID' => 7,
                    'BINDINGS.ENTITY_ID' => $dealId,
                    'BINDINGS.ENTITY_TYPE_ID' => \CCrmOwnerType::Deal
                ],
                'select' => ['ID', 'CREATED'],
                'order' => ['ID' => 'DESC']
            ]);

            $commentIds = [];
            while ($ar = $timelineEntries->Fetch()) {
                $commentIds[] = (int)$ar['ID'];
            }

            if (!empty($commentIds)) {
                global $DB;

                $commentIdsStr = implode(',', array_map('intval', $commentIds));

                $rsSql = $DB->Query(
                    "SELECT a.ID as ATTACHED_ID, a.ENTITY_ID as COMMENT_ID,
                            o.FILE_ID, o.NAME as DISK_NAME,
                            f.ORIGINAL_NAME, f.CONTENT_TYPE, f.FILE_SIZE,
                            f.TIMESTAMP_X
                     FROM b_disk_attached_object a
                     LEFT JOIN b_disk_object o ON o.ID = a.OBJECT_ID
                     LEFT JOIN b_file f ON f.ID = o.FILE_ID
                     WHERE a.ENTITY_TYPE = 'Bitrix\\\\Crm\\\\Integration\\\\Disk\\\\CommentConnector'
                     AND a.ENTITY_ID IN ({$commentIdsStr})"
                );

                while ($arSql = $rsSql->Fetch()) {
                    $fileId = (int)($arSql['FILE_ID'] ?? 0);
                    $attachedId = (int)$arSql['ATTACHED_ID'];

                    if ($fileId <= 0) {
                        continue;
                    }

                    $displayName = $arSql['ORIGINAL_NAME'] ?? '';
                    if ($displayName === '') {
                        $displayName = $arSql['DISK_NAME'] ?? 'Без названия';
                    }

                    $downloadUrl = '/bitrix/tools/disk/uf.php'
                        . '?action=download'
                        . '&ncc=1'
                        . '&attachedId=' . $attachedId;

                    $files[] = [
                        'id'        => $fileId,
                        'name'      => $displayName,
                        'size'      => dflFormatFileSize((int)($arSql['FILE_SIZE'] ?? 0)),
                        'mime'      => $arSql['CONTENT_TYPE'] ?? 'application/octet-stream',
                        'date'      => dflFormatDate($arSql['TIMESTAMP_X'] ?? ''),
                        'url'       => $downloadUrl,
                        'source'    => 'Комментарий',
                        'extension' => dflGetFileExtension($displayName),
                    ];
                }
            }
        }
    } catch (\Throwable $e) {
        // Ошибка в источнике 2 — не убиваем остальные
    }

    // ========================================
    // ИСТОЧНИК 3: Файлы из дел (активностей) сделки
    // ========================================

    try {
        $rsActivities = \CCrmActivity::GetList(
            ['ID' => 'ASC'],
            [
                'OWNER_TYPE_ID' => \CCrmOwnerType::Deal,
                'OWNER_ID' => $dealId,
                'CHECK_PERMISSIONS' => 'N'
            ],
            false, false,
            ['ID', 'STORAGE_TYPE_ID', 'STORAGE_ELEMENT_IDS']
        );

        while ($arActivity = $rsActivities->Fetch()) {
            $rawIds = $arActivity['STORAGE_ELEMENT_IDS'] ?? '';

            $elementIds = @unserialize($rawIds);
            if (!is_array($elementIds) || empty($elementIds)) {
                continue;
            }

            global $DB;

            foreach ($elementIds as $elementId) {
                $elementId = (int)$elementId;
                if ($elementId <= 0) {
                    continue;
                }

                $rsSql = $DB->Query(
                    "SELECT o.FILE_ID, o.NAME as DISK_NAME,
                            f.ORIGINAL_NAME, f.CONTENT_TYPE, f.FILE_SIZE,
                            f.TIMESTAMP_X
                     FROM b_disk_object o
                     LEFT JOIN b_file f ON f.ID = o.FILE_ID
                     WHERE o.ID = {$elementId}"
                );

                $arSql = $rsSql ? $rsSql->Fetch() : null;
                if (!$arSql) {
                    continue;
                }

                $fileId = (int)($arSql['FILE_ID'] ?? 0);
                if ($fileId <= 0) {
                    continue;
                }

                $displayName = $arSql['ORIGINAL_NAME'] ?? '';
                if ($displayName === '') {
                    $displayName = $arSql['DISK_NAME'] ?? 'Без названия';
                }

                $downloadUrl = '/local/modules/derykams.dealfileslist/ajax/download.php'
                    . '?dealId=' . $dealId
                    . '&fileId=' . $fileId
                    . '&source=activity'
                    . '&sessid=' . bitrix_sessid();

                $files[] = [
                    'id'        => $fileId,
                    'name'      => $displayName,
                    'size'      => dflFormatFileSize((int)($arSql['FILE_SIZE'] ?? 0)),
                    'mime'      => $arSql['CONTENT_TYPE'] ?? 'application/octet-stream',
                    'date'      => dflFormatDate($arSql['TIMESTAMP_X'] ?? ''),
                    'url'       => $downloadUrl,
                    'source'    => 'Дело',
                    'extension' => dflGetFileExtension($displayName),
                ];
            }
        }
    } catch (\Throwable $e) {
        // Ошибка в источнике 3 — не убиваем остальные
    }

    // ========================================
    // ИСТОЧНИК 4: Сгенерированные документы
    // ========================================

    try {
        $docModuleLoaded = Loader::includeModule('documentgenerator');

        if ($docModuleLoaded) {
            /*
                Прямой SQL к b_documentgenerator_document.
            */
            global $DB;

            $dealIdInt = (int)$dealId;

            // Пробуем БЕЗ фильтра PROVIDER — только VALUE
            $rsDocs = $DB->Query(
                "SELECT ID, TITLE, NUMBER, FILE_ID, PDF_ID, IMAGE_ID,
                        TEMPLATE_ID, CREATE_TIME, PROVIDER, VALUE
                 FROM b_documentgenerator_document
                 WHERE VALUE = {$dealIdInt}
                 ORDER BY ID DESC"
            );

            while ($arDoc = $rsDocs->Fetch()) {
                $docId     = (int)$arDoc['ID'];
                $docTitle  = (string)($arDoc['TITLE'] ?? '');
                $docNumber = (string)($arDoc['NUMBER'] ?? '');
                $fileId    = (int)($arDoc['FILE_ID'] ?? 0);
                $pdfId     = (int)($arDoc['PDF_ID'] ?? 0);

                // Формируем название документа
                $displayName = '';
                if ($docTitle !== '' && $docNumber !== '') {
                    $displayName = $docTitle . ' №' . $docNumber;
                } elseif ($docTitle !== '') {
                    $displayName = $docTitle;
                } elseif ($docNumber !== '') {
                    $displayName = 'Документ №' . $docNumber;
                } else {
                    $displayName = 'Документ #' . $docId;
                }

                // Приоритет: PDF (если готов), иначе DOCX
                $usePdf = ($pdfId > 0);
                $effectiveFileId = $usePdf ? $pdfId : $fileId;

                if ($effectiveFileId <= 0) {
                    continue;
                }

                /*
                    FILE_ID и PDF_ID в b_documentgenerator_document — это не ID
                    в b_file, а внутренние ID модуля documentgenerator.
                    CFile::GetByID для них не работает.

                    Не запрашиваем размер/MIME через CFile — вместо этого
                    используем фиксированные значения по типу файла.
                    Размер не показываем (пустая строка), MIME — по расширению.
                */
                $fileExt = $usePdf ? 'pdf' : 'docx';
                $fileName = $displayName . '.' . $fileExt;

                // Ссылка для скачивания через AJAX-endpoint Битрикс
                $action = $usePdf
                    ? 'crm.documentgenerator.document.getPdf'
                    : 'crm.documentgenerator.document.download';

                $downloadUrl = '/bitrix/services/main/ajax.php'
                    . '?action=' . $action
                    . '&SITE_ID=s1'
                    . '&id=' . $docId;

                $files[] = [
                    'id'        => $docId,
                    'name'      => $fileName,
                    'size'      => '',
                    'mime'      => $usePdf ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    'date'      => dflFormatDate($arDoc['CREATE_TIME'] ?? ''),
                    'url'       => $downloadUrl,
                    'source'    => 'Документ',
                    'extension' => $fileExt,
                ];
            }
        }
    } catch (\Throwable $e) {
        // Ошибка в источнике 4 — не убиваем остальные
    }

    dflSendJson([
        'status'  => 'success',
        'dealId'  => $dealId,
        'files'   => $files,
    ]);

} catch (\Throwable $e) {
    dflSendJson([
        'status'  => 'error',
        'message' => 'Исключение: ' . $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ], 500);
}