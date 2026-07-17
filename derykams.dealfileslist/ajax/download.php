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

    // Получаем коды файловых полей через CUserTypeEntity::GetList
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

    // Значения полей для конкретной сделки
    global $USER_FIELD_MANAGER;
    $dealUserFields = $USER_FIELD_MANAGER->GetUserFields('CRM_DEAL', $dealId);
    if (!is_array($dealUserFields)) {
        $dealUserFields = [];
    }

    // Собираем все ID файлов из всех файловых полей сделки
    $dealFileIds = [];
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