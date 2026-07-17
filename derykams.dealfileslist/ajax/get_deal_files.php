<?php

/**
 * AJAX-эндпоинт для получения списка файлов, прикреплённых к сделке.
 *
 * Принимает: POST dealId (int), sessid (string)
 * Возвращает: JSON { status, dealId, files: [...] }
 *
 * Источники файлов:
 *   1. Пользовательские поля сделки типа "Файл" (UF_CRM_*)
 *      Файлы хранятся в b_file, в поле записан ID файла.
 *      Скачивание через наш прокси download.php.
 *
 *   2. Комментарии в таймлайне сделки
 *      Файлы хранятся через модуль Disk: b_disk_attached_object ->
 *      b_disk_object -> b_file.
 *      Коннектор: Bitrix\Crm\Integration\Disk\CommentConnector
 *      Скачивание через стандартный /bitrix/tools/disk/uf.php (проверяет права).
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
    // Если в имени есть точка — берём часть после последней точки
    $dotPos = strrpos($fileName, '.');
    if ($dotPos === false) {
        return '';
    }

    $ext = strtolower(substr($fileName, $dotPos + 1));
    // Очищаем: только буквы/цифры, до 5 символов
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

    /*
        Получаем схему UF-полей через CUserTypeEntity::GetList,
        фильтруем типа 'file', затем получаем значения для сделки.
    */
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

        // Извлекаем ID файлов из UF-полей
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

        // Убираем дубли
        $ufFileIds = array_values(array_unique($ufFileIds));

        // Получаем информацию о файлах через CFile::GetByID
        foreach ($ufFileIds as $fileId) {
            $rsFile = \CFile::GetByID($fileId);
            $arFile = $rsFile ? $rsFile->Fetch() : null;

            if (!$arFile) {
                continue;
            }

            $originalName = $arFile['ORIGINAL_NAME'] ?? '';
            $fileName     = $arFile['FILE_NAME'] ?? '';
            $displayName  = ($originalName !== '') ? $originalName : $fileName;

            // Скачивание через наш прокси download.php
            $downloadUrl = '/local/modules/derykams.dealfileslist/ajax/download.php'
                . '?dealId=' . $dealId
                . '&fileId=' . $fileId
                . '&sessid=' . bitrix_sessid();

            $files[] = [
                'id'     => $fileId,
                'name'   => $displayName,
                'size'   => dflFormatFileSize((int)($arFile['FILE_SIZE'] ?? 0)),
                'mime'   => $arFile['CONTENT_TYPE'] ?? 'application/octet-stream',
                'date'   => dflFormatDate($arFile['TIMESTAMP_X'] ?? ''),
                'url'    => $downloadUrl,
                'source' => 'Поле сделки',
                'extension' => dflGetFileExtension($displayName),
            ];
        }
    }

    // ========================================
    // ИСТОЧНИК 2: Файлы из комментариев таймлайна
    // ========================================

    /*
        1. Получаем записи таймлайна сделки (TYPE_ID = 7 — комментарий)
           через TimelineTable::getList с фильтром по сделке.
        2. Для каждого комментария ищем attached objects в
           b_disk_attached_object через прямой SQL с JOIN
           b_disk_object + b_file.
        3. Ссылка для скачивания — стандартный endpoint Битрикс:
           /bitrix/tools/disk/uf.php?action=download&ncc=1&attachedId=XX
           Он сам проверяет права доступа.
    */
    if (Loader::includeModule('disk')) {
        // Получаем ID комментариев сделки из TimelineTable
        $timelineEntries = \Bitrix\Crm\Timeline\Entity\TimelineTable::getList([
            'filter' => [
                '=TYPE_ID' => 7, // TimelineType::COMMENT
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

            // Прямой SQL с JOIN — получаем файлы из комментариев
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

                // Пропускаем если нет файла
                if ($fileId <= 0) {
                    continue;
                }

                $displayName = $arSql['ORIGINAL_NAME'] ?? '';
                if ($displayName === '') {
                    $displayName = $arSql['DISK_NAME'] ?? 'Без названия';
                }

                // Стандартный endpoint Битрикс для скачивания attached-файла
                // ncc=1 — отключает композитный кеш
                $downloadUrl = '/bitrix/tools/disk/uf.php'
                    . '?action=download'
                    . '&ncc=1'
                    . '&attachedId=' . $attachedId;

                $files[] = [
                    'id'     => $fileId,
                    'name'   => $displayName,
                    'size'   => dflFormatFileSize((int)($arSql['FILE_SIZE'] ?? 0)),
                    'mime'   => $arSql['CONTENT_TYPE'] ?? 'application/octet-stream',
                    'date'   => dflFormatDate($arSql['TIMESTAMP_X'] ?? ''),
                    'url'    => $downloadUrl,
                    'source' => 'Комментарий',
                    'extension' => dflGetFileExtension($displayName),
                ];
            }
        }
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