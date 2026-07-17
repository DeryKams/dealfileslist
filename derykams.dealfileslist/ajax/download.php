<?php

/**
 * Прокси-эндпоинт для скачивания файлов.
 *
 * Принимает: GET dealId (int), fileId (int), sessid (string)
 *
 * Проверяет что файл принадлежит сделке и отдаёт его с
 * заголовком Content-Disposition: attachment.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

define('NO_KEEP_STATISTIC', 'Y');
define('NO_AGENT_STATISTIC', 'Y');
define('NO_AGENT_CHECK', true);
define('PUBLIC_AJAX_MODE', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Loader;

try {
    // Проверка авторизации
    global $USER;
    if (!$USER || !$USER->IsAuthorized()) {
        http_response_code(401);
        die('Доступ запрещён: требуется авторизация');
    }

    // Проверка сессии
    if (!check_bitrix_sessid()) {
        http_response_code(403);
        die('Доступ запрещён: некорректная сессия');
    }

    // Подключаем модуль CRM
    if (!Loader::includeModule('crm')) {
        http_response_code(500);
        die('Модуль CRM не подключился');
    }

    // Получаем параметры из GET
    $dealId = (int)($_GET['dealId'] ?? 0);
    $fileId = (int)($_GET['fileId'] ?? 0);

    if ($dealId <= 0 || $fileId <= 0) {
        http_response_code(400);
        die('Некорректные параметры');
    }

    // === ПРОВЕРКА ПРИНАДЛЕЖНОСТИ ФАЙЛА СДЕЛКЕ ===

    /*
        Файл может принадлежать сделке через:
        1. UF-поля типа "Файл" — fileId в значениях полей
        2. Дела (активности) — fileId получается через цепочку
           STORAGE_ELEMENT_IDS -> b_disk_object -> FILE_ID

        Проверяем оба источника. Если файл найден в любом из них —
        считаем что он принадлежит сделке.
    */

    $dealFileIds = [];

    // --- Источник 1: UF-поля ---

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

    global $USER_FIELD_MANAGER;
    $dealUserFields = $USER_FIELD_MANAGER->GetUserFields('CRM_DEAL', $dealId);
    if (!is_array($dealUserFields)) {
        $dealUserFields = [];
    }

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
                    $dealFileIds[] = $id;
                }
            }
        } else {
            $id = (int)$value;
            if ($id > 0) {
                $dealFileIds[] = $id;
            }
        }
    }

    // --- Источник 2: Дела (активности) сделки ---

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

    global $DB;

    while ($arActivity = $rsActivities->Fetch()) {
        $rawIds = $arActivity['STORAGE_ELEMENT_IDS'] ?? '';
        $elementIds = @unserialize($rawIds);
        if (!is_array($elementIds) || empty($elementIds)) {
            continue;
        }

        foreach ($elementIds as $elementId) {
            $elementId = (int)$elementId;
            if ($elementId <= 0) {
                continue;
            }

            // elementId = ID в b_disk_object -> FILE_ID
            $rsObj = $DB->Query(
                "SELECT FILE_ID FROM b_disk_object WHERE ID = {$elementId}"
            );
            $arObj = $rsObj ? $rsObj->Fetch() : null;
            if ($arObj && (int)$arObj['FILE_ID'] > 0) {
                $dealFileIds[] = (int)$arObj['FILE_ID'];
            }
        }
    }

    // Проверяем: запрошенный fileId должен быть в списке файлов сделки
    if (!in_array($fileId, $dealFileIds, true)) {
        http_response_code(403);
        die('Файл не принадлежит этой сделке');
    }

    // === ОТДАЧА ФАЙЛА ===

    $rsFile = \CFile::GetByID($fileId);
    $arFile = $rsFile ? $rsFile->Fetch() : null;

    if (!$arFile) {
        http_response_code(404);
        die('Файл не найден');
    }

    $filePath = $_SERVER['DOCUMENT_ROOT'] . \CFile::GetFileSRC($arFile);

    if (!file_exists($filePath) || !is_readable($filePath)) {
        http_response_code(404);
        die('Файл не найден на диске');
    }

    // Заголовки для скачивания
    $originalName = $arFile['ORIGINAL_NAME'] ?? '';
    $fileName     = $arFile['FILE_NAME'] ?? '';
    $displayName   = ($originalName !== '') ? $originalName : $fileName;

    header('Content-Type: ' . ($arFile['CONTENT_TYPE'] ?? 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . basename($displayName) . '"');
    header('Content-Length: ' . (int)($arFile['FILE_SIZE'] ?? 0));
    header('Content-Transfer-Encoding: binary');

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    readfile($filePath);
    die();

} catch (\Throwable $e) {
    http_response_code(500);
    die('Ошибка: ' . $e->getMessage());
}