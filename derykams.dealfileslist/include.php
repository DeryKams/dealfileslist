<?php
/**
 * include.php — подключаемый файл модуля derykams.dealfileslist.
 *
 * Битрикс автоматически подключает этот файл при каждом вызове
 * модуля через CModule::IncludeModule('derykams.dealfileslist').
 *
 * Здесь регистрируем автозагрузку классов модуля (autoload).
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

// Защита от прямого вызова
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

/**
 * Регистрируем автозагрузку классов модуля.
 *
 * Префикс Derykams\DealFilesList маппится на папку lib/.
 * Пример: Derykams\DealFilesList\EventHandler -> lib/EventHandler.php
 */
Loader::registerAutoLoadClasses(
    'derykams.dealfileslist',
    [
        'Derykams\DealFilesList\EventHandler' => 'lib/EventHandler.php',
    ]
);