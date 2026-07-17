<?php

/**
 * install/index.php — класс модуля derykams.dealfileslist.
 *
 * Имя класса: derykams.dealfileslist -> derykams_dealfileslist
 * (В Bitrix24 Коробка точка заменяется на подчёркивание)
 */

use Bitrix\Main\EventManager;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

$arModuleVersion = [];
$versionFile = __DIR__ . '/version.php';
if (file_exists($versionFile)) {
    include $versionFile;
}

class derykams_dealfileslist extends CModule
{
    public const MODULE_ID = 'derykams.dealfileslist';

    /** Класс и метод обработчика события OnProlog */
    public const EVENT_CLASS  = 'Derykams\\DealFilesList\\EventHandler';
    public const EVENT_METHOD = 'onProlog';

    public $MODULE_ID;
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI;

    public function __construct()
    {
        $this->MODULE_ID = self::MODULE_ID;

        $this->MODULE_VERSION      = $arModuleVersion['VERSION']      ?? '1.0.0';
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'] ?? date('Y-m-d H:i:s');

        $this->MODULE_NAME        = Loc::getMessage('DERYKAMS_DFL_MODULE_NAME')        ?: 'Файлы сделки';
        $this->MODULE_DESCRIPTION = Loc::getMessage('DERYKAMS_DFL_MODULE_DESCRIPTION') ?: 'Кнопка в карточке сделки для просмотра всех загруженных файлов';

        $this->PARTNER_NAME = Loc::getMessage('DERYKAMS_DFL_PARTNER_NAME') ?: 'DeryKams';
        $this->PARTNER_URI  = Loc::getMessage('DERYKAMS_DFL_PARTNER_URI')  ?: 'https://github.com/DeryKams';
    }

    /**
     * Установка модуля.
     */
    public function DoInstall()
    {
        global $APPLICATION;

        try {
            if (!ModuleManager::isModuleInstalled($this->MODULE_ID)) {
                ModuleManager::registerModule($this->MODULE_ID);
            }

            $this->InstallFiles();
            $this->UnInstallEvents();
            $this->InstallEvents();

            LocalRedirect(
                '/bitrix/admin/partner_modules.php?lang=' . LANGUAGE_ID
                . '&mid=' . urlencode($this->MODULE_ID)
                . '&install=ok'
            );

        } catch (\Throwable $e) {
            if (ModuleManager::isModuleInstalled($this->MODULE_ID)) {
                try { $this->UnInstallEvents(); } catch (\Throwable $inner) {}
                ModuleManager::unRegisterModule($this->MODULE_ID);
            }
            $APPLICATION->ThrowException($e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Удаление модуля.
     */
    public function DoUninstall()
    {
        global $APPLICATION;

        try {
            $this->UnInstallEvents();
            $this->UnInstallFiles();

            if (ModuleManager::isModuleInstalled($this->MODULE_ID)) {
                ModuleManager::unRegisterModule($this->MODULE_ID);
            }

            LocalRedirect(
                '/bitrix/admin/partner_modules.php?lang=' . LANGUAGE_ID
                . '&uninstall=ok'
            );

        } catch (\Throwable $e) {
            $APPLICATION->ThrowException($e->getMessage());
            return false;
        }

        return true;
    }

    /**
     * Копирование файлов при установке — пока не требуется,
     * JS подключается напрямую из папки модуля через Asset::addJs.
     */
    public function InstallFiles()
    {
        return true;
    }

    /**
     * Удаление файлов при деинсталляции.
     * Очищаем папку с кэшированными SVG-иконками.
     */
    public function UnInstallFiles()
    {
        $iconsDir = __DIR__ . '/../icons';

        if (is_dir($iconsDir)) {
            // Удаляем все SVG-файлы из папки
            $files = glob($iconsDir . '/*.svg');
            if (is_array($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
            // Удаляем саму папку
            @rmdir($iconsDir);
        }

        return true;
    }

    /**
     * Регистрация обработчиков событий.
     *
     * Подписываемся на main:OnProlog — вызывается на каждой странице.
     * В EventHandler::onProlog проверяем URL и подключаем JS только
     * на странице детальной карточки сделки.
     */
    public function InstallEvents()
    {
        EventManager::getInstance()->registerEventHandler(
            'main',
            'OnProlog',
            $this->MODULE_ID,
            self::EVENT_CLASS,
            self::EVENT_METHOD
        );

        return true;
    }

    /**
     * Снятие обработчиков событий.
     *
     * EventManager::unRegisterEventHandler принимает FQN без ведущего слеша.
     * Вариант с ведущим backslash — наследие старого RegisterModuleDependences,
     * здесь мы используем D7 EventManager, так что он не нужен.
     */
    public function UnInstallEvents()
    {
        EventManager::getInstance()->unRegisterEventHandler(
            'main',
            'OnProlog',
            $this->MODULE_ID,
            self::EVENT_CLASS,
            self::EVENT_METHOD
        );

        return true;
    }
}