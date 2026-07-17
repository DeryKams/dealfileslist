<?php

/**
 * EventHandler — обработчик событий модуля derykams.dealfileslist.
 *
 * Регистрируется на событие main:OnProlog, которое вызывается
 * на каждой странице Битрикс. В onProlog проверяем, находимся ли мы
 * на странице детальной карточки сделки (/crm/deal/details/ID/),
 * и если да — подключаем наш JS-файл с кнопкой и popup.
 */

namespace Derykams\DealFilesList;

use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Page\Asset;

class EventHandler
{
    /**
     * Обработчик события OnProlog.
     * Вызывается на каждой странице — поэтому первым делом
     * проверяем URL, чтобы не подключать JS везде подряд.
     */
    public static function onProlog(): void
    {
        // Проверяем что наш модуль установлен и активен
        if (!Loader::includeModule('derykams.dealfileslist')) {
            return;
        }

        // Без модуля CRM карточка сделки не существует — выходим
        if (!Loader::includeModule('crm')) {
            return;
        }

        // Получаем текущий HTTP-запрос и его путь
        $request = Context::getCurrent()->getRequest();
        $currentPage = $request->getRequestedPage();

        // Проверяем, находимся ли мы на странице детальной карточки сделки
        // URL имеет вид /crm/deal/details/123/ (число — ID сделки)
        if (strpos($currentPage, '/crm/deal/details/') === false) {
            return;
        }

        // Извлекаем ID сделки из URL с помощью регулярки
        preg_match('/\/crm\/deal\/details\/(\d+)/', $currentPage, $matches);
        $dealId = !empty($matches[1]) ? (int)$matches[1] : 0;

        if ($dealId <= 0) {
            return;
        }

        // Подключаем наш JavaScript-файл.
        // Путь: /local/modules/derykams.dealfileslist/js/dealfiles.js
        // (при установке в /local/modules/ — стандартное место для кастомных модулей)
        Asset::getInstance()->addJs(
            '/local/modules/derykams.dealfileslist/js/dealfiles.js'
        );
    }
}