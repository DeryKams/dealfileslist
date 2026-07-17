(function () {
  'use strict';

  /*
    Уникальный ID кнопки в карточке сделки.
    Используется чтобы не вставить кнопку дважды.
  */
  var ACTION_BUTTON_ID = 'derykams-dealfileslist-button';

  /*
    Уникальный ID popup.
  */
  var POPUP_ID = 'derykams-dealfileslist-popup';

  /*
    Кандидаты на область вставки кнопки.
    Первый подходящий DOM-узел используется.
    .crm-entity-bizproc-container — контейнер кнопки бизнес-процессов,
    который всегда есть в карточке сделки.
  */
  var TARGET_SELECTORS = [
    '.crm-entity-bizproc-container'
  ];

  /*
    URL AJAX-эндпоинтов.
  */
  var AJAX_URL = '/local/modules/derykams.dealfileslist/ajax/get_deal_files.php';
  var ICON_URL = '/local/modules/derykams.dealfileslist/ajax/get_icon.php';

  /*
    Получаем ID сделки из URL.
    Ожидаем путь вида /crm/deal/details/123/
  */
  function getDealIdFromUrl() {
    var match = window.location.pathname.match(/\/crm\/deal\/details\/(\d+)\/?/i);
    if (match && match[1]) {
      return parseInt(match[1], 10);
    }
    return 0;
  }

  /*
    Ищем целевой контейнер для кнопки.
    Перебираем селекторы, возвращаем первый найденный.
  */
  function findTargetNode() {
    for (var i = 0; i < TARGET_SELECTORS.length; i++) {
      var node = document.querySelector(TARGET_SELECTORS[i]);
      if (node) {
        return node;
      }
    }
    return null;
  }

  /*
    Универсальное уведомление через BX UI Notification.
    Если BX UI недоступен — fallback на alert().
  */
  function showMessage(text) {
    if (window.BX && BX.UI && BX.UI.Notification && BX.UI.Notification.Center) {
      BX.UI.Notification.Center.notify({ content: text });
      return;
    }
    alert(text);
  }

  /*
    Загружаем список файлов сделки с сервера через AJAX.
    Возвращает Promise с JSON-ответом.
    При HTTP-ошибке (500 и т.д.) сервер может отдать пустое тело —
    поэтому сначала проверяем есть ли что парсить.
  */
  function loadDealFiles(dealId) {
    var sessid = (window.BX && BX.bitrix_sessid) ? BX.bitrix_sessid() : '';
    var body = new URLSearchParams();

    body.append('sessid', sessid);
    body.append('dealId', String(dealId));

    return fetch(AJAX_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: body.toString()
    }).then(function (response) {
      /*
        При 500 сервер (nginx/PHP) может отдать пустое тело.
        response.json() на пустой строке выбросит SyntaxError.
        Поэтому сначала читаем текст, потом пытаемся парсить.
      */
      return response.text().then(function (text) {
        if (!text || text.length === 0) {
          if (!response.ok) {
            throw new Error('Сервер вернул HTTP ' + response.status + ' без тела ответа');
          }
          throw new Error('Сервер вернул пустой ответ');
        }

        try {
          var data = JSON.parse(text);
        } catch (e) {
          throw new Error('Сервер вернул некорректный JSON: ' + text.substring(0, 200));
        }

        if (!response.ok) {
          throw new Error(
            (data && data.message) ? data.message : ('HTTP ' + response.status)
          );
        }

        return data;
      });
    });
  }

  /*
    Строит URL для получения SVG-иконки файла.
    Иконки кэшируются на сервере — для каждого расширения
    генерация происходит один раз, дальше отдаётся готовый SVG.
    Браузер также кэширует ответ через Cache-Control: immutable.

    ext — расширение файла в нижнем регистре (pdf, xlsx, rb)
  */
  function getIconUrl(ext) {
    if (!ext) {
      ext = 'file';
    }
    return ICON_URL + '?ext=' + encodeURIComponent(ext);
  }

  /*
    Создаёт <img> с SVG-иконкой файла.
    Размер 48x48 — вписывается в строку файла.
  */
  function createFileIcon(ext) {
    return BX.create('img', {
      attrs: {
        src: getIconUrl(ext),
        alt: ext ? ext.toUpperCase() : 'FILE'
      },
      style: {
        width: '48px',
        height: '48px',
        flexShrink: '0',
        borderRadius: '4px',
        objectFit: 'contain'
      }
    });
  }

  /*
    Проверяем, является ли файл изображением (по MIME-типу).
    Используется для показа миниатюры вместо иконки.
  */
  function isImage(mime) {
    return mime && mime.indexOf('image/') === 0;
  }

  /*
    Собираем DOM-содержимое popup со списком файлов.

    items — массив файлов от бэкенда, каждый элемент:
    {
      id:        int,       — ID файла в b_file
      name:      string,    — оригинальное имя файла
      size:      string,    — человекочитаемый размер ("1.2 MB")
      mime:      string,    — MIME-тип ("application/pdf")
      date:      string,    — дата ("16.07.2026")
      url:       string,    — ссылка для скачивания
      source:    string,    — источник: "Поле сделки" или "Комментарий"
      extension: string,    — расширение в нижнем регистре ("pdf", "xlsx", "rb")
    }
  */
  function buildPopupContent(items) {
    // Обёртка с отступами и прокруткой
    var wrapper = BX.create('div', {
      style: {
        padding: '16px',
        minWidth: '520px',
        maxWidth: '700px',
        maxHeight: '70vh',
        overflowY: 'auto',
        boxSizing: 'border-box'
      }
    });

    // Заголовок popup
    wrapper.appendChild(
      BX.create('div', {
        text: 'Файлы, прикреплённые к сделке',
        style: {
          marginBottom: '12px',
          fontWeight: '600',
          fontSize: '15px'
        }
      })
    );

    // Если файлов нет — показываем сообщение
    if (!items || !items.length) {
      wrapper.appendChild(
        BX.create('div', {
          text: 'Файлов не найдено',
          style: {
            color: '#777',
            padding: '20px 0',
            textAlign: 'center'
          }
        })
      );
      return wrapper;
    }

    // Список файлов — каждый файл отдельным блоком
    items.forEach(function (fileItem) {
      var fileBlock = BX.create('div', {
        style: {
          marginBottom: '10px',
          padding: '10px',
          border: '1px solid #e5e7eb',
          borderRadius: '6px',
          display: 'flex',
          alignItems: 'center',
          gap: '10px'
        }
      });

      /*
        Левая часть: иконка файла (SVG) или миниатюра.
        Для картинок — показываем <img> через ссылку на download.php.
        Для остальных — SVG-иконку через get_icon.php (с кэшированием на сервере).
      */
      var fileName = fileItem.name || 'Без названия';
      var fileUrl = fileItem.url || '';
      var fileMime = fileItem.mime || '';
      var fileExt = fileItem.extension || '';

      if (isImage(fileMime) && fileUrl) {
        // Для картинок — миниатюра (браузер сам отмасштабирует)
        fileBlock.appendChild(
          BX.create('img', {
            attrs: {
              src: fileUrl,
              alt: fileName
            },
            style: {
              width: '48px',
              height: '48px',
              objectFit: 'cover',
              borderRadius: '4px',
              flexShrink: '0',
              cursor: 'pointer'
            },
            events: {
              // Клик на миниатюру — открыть в новой вкладке
              click: function () {
                window.open(fileUrl, '_blank');
              }
            }
          })
        );
      } else {
        // Для не-картинок — SVG-иконка с кэшированием
        fileBlock.appendChild(createFileIcon(fileExt));
      }

      // Блок с названием и метаинфо
      var infoBlock = BX.create('div', {
        style: {
          flexGrow: '1',
          minWidth: '0'
        }
      });

      // Название файла — как ссылка для скачивания
      if (fileUrl) {
        infoBlock.appendChild(
          BX.create('a', {
            text: fileName,
            attrs: {
              href: fileUrl,
              target: '_blank',
              download: fileName
            },
            style: {
              display: 'block',
              fontWeight: '600',
              color: '#2b6cb0',
              textDecoration: 'none',
              overflow: 'hidden',
              textOverflow: 'ellipsis',
              whiteSpace: 'nowrap'
            }
          })
        );
      } else {
        // Если URL почему-то нет — показываем имя как текст
        infoBlock.appendChild(
          BX.create('span', {
            text: fileName,
            style: {
              display: 'block',
              fontWeight: '600',
              overflow: 'hidden',
              textOverflow: 'ellipsis',
              whiteSpace: 'nowrap'
            }
          })
        );
      }

      // Размер + дата
      var metaParts = [];
      if (fileItem.size) {
        metaParts.push(fileItem.size);
      }
      if (fileItem.date) {
        metaParts.push(fileItem.date);
      }
      if (metaParts.length) {
        infoBlock.appendChild(
          BX.create('div', {
            text: metaParts.join(' · '),
            style: {
              color: '#999',
              fontSize: '12px',
              marginTop: '2px'
            }
          })
        );
      }

      // Бейдж источника файла (Поле сделки / Комментарий)
      if (fileItem.source) {
        infoBlock.appendChild(
          BX.create('span', {
            text: fileItem.source,
            style: {
              display: 'inline-block',
              marginTop: '4px',
              padding: '1px 8px',
              fontSize: '11px',
              color: '#666',
              backgroundColor: '#f0f0f0',
              borderRadius: '10px'
            }
          })
        );
      }

      fileBlock.appendChild(infoBlock);

      /*
        Кнопка "Скачать" — отдельная, справа.
        Дублирует ссылку-имя, но делает интерфейс очевиднее.
      */
      if (fileUrl) {
        fileBlock.appendChild(
          BX.create('a', {
            text: 'Скачать',
            attrs: {
              href: fileUrl,
              target: '_blank',
              download: fileName
            },
            style: {
              flexShrink: '0',
              padding: '4px 12px',
              fontSize: '13px',
              color: '#2b6cb0',
              border: '1px solid #2b6cb0',
              borderRadius: '4px',
              textDecoration: 'none',
              whiteSpace: 'nowrap'
            }
          })
        );
      }

      wrapper.appendChild(fileBlock);
    });

    return wrapper;
  }

  /*
    Открываем popup со списком файлов.
    items — массив файлов от AJAX-ответа.
  */
  function showFilesPopup(items) {
    // Если popup уже существует — уничтожаем, чтобы открыть "чистый"
    var existingPopup = BX.PopupWindowManager.getPopupById(POPUP_ID);
    if (existingPopup) {
      existingPopup.destroy();
    }

    // Собираем контент заранее — popup сразу знает размеры
    var contentNode = buildPopupContent(items);

    // Создаём popup (второй аргумент null = по центру, не привязан к элементу)
    var popup = BX.PopupWindowManager.create(POPUP_ID, null, {
      autoHide: true,
      closeIcon: true,
      closeByEsc: true,
      overlay: true,
      lightShadow: true,
      draggable: false,
      titleBar: 'Файлы сделки',
      content: contentNode,
      offsetLeft: 0,
      offsetTop: 0,

      // Кнопка "Закрыть" — единственная кнопка, так как popup только для просмотра
      buttons: [
        new BX.PopupWindowButton({
          text: 'Закрыть',
          className: 'popup-window-button-decline',
          events: {
            click: function () {
              popup.close();
            }
          }
        })
      ],

      events: {
        // После показа — пересчитываем позицию
        onAfterPopupShow: function () {
          this.adjustPosition();
          if (this.overlay) {
            this.resizeOverlay();
          }
        },
        // После закрытия — уничтожаем popup
        onPopupClose: function () {
          this.destroy();
        }
      }
    });

    popup.show();
  }

  /*
    Обработка клика по кнопке.
    Загружаем список файлов и открываем popup.
  */
  function handleButtonClick(buttonNode) {
    var dealId = getDealIdFromUrl();

    if (!dealId) {
      showMessage('Не удалось определить ID сделки');
      return;
    }

    // Блокируем кнопку и меняем текст на время загрузки
    buttonNode.disabled = true;
    var oldText = buttonNode.textContent;
    buttonNode.textContent = 'Загрузка...';

    loadDealFiles(dealId)
      .then(function (data) {
        // Если сервер вернул ошибку — показываем сообщение, popup не открываем
        if (!data || data.status !== 'success') {
          showMessage(
            (data && data.message) ? data.message : 'Не удалось загрузить файлы сделки'
          );
          return;
        }

        // Успех — открываем popup с файлами
        showFilesPopup(data.files || []);
      })
      .catch(function (error) {
        console.error('Ошибка загрузки файлов сделки:', error);
        showMessage(error.message || 'Ошибка загрузки файлов сделки');
      })
      .finally(function () {
        // Всегда возвращаем кнопку в исходное состояние
        buttonNode.disabled = false;
        buttonNode.textContent = oldText;
      });
  }

  /*
    Создаём кнопку, которая открывает popup со списком файлов.
    Используем стандартные классы Битрикс UI для оформления.
  */
  function createButton() {
    var button = document.createElement('button');

    button.id = ACTION_BUTTON_ID;
    button.type = 'button';
    // ui-btn-primary — синяя кнопка, вписывается в стиль карточки сделки
    button.className = 'ui-btn ui-btn-sm ui-btn-round ui-btn-no-caps ui-btn-primary';
    button.textContent = 'Файлы сделки';

    button.addEventListener('click', function () {
      handleButtonClick(button);
    });

    return button;
  }

  /*
    Вставляем кнопку в карточку сделки, если:
    1. Мы на странице сделки (есть dealId в URL)
    2. Нашли целевой контейнер
    3. Кнопка ещё не вставлена
  */
  function ensureButtonInserted() {
    var dealId = getDealIdFromUrl();
    if (!dealId) {
      return;
    }

    // Если кнопка уже есть — не дублируем
    if (document.getElementById(ACTION_BUTTON_ID)) {
      return;
    }

    var targetNode = findTargetNode();
    if (!targetNode) {
      return;
    }

    // Вставляем кнопку в начало контейнера
    targetNode.prepend(createButton());
  }

  /*
    MutationObserver — следит за изменениями DOM.
    Карточка сделки — SPA, DOM строится динамически,
    поэтому простой DOMContentLoaded не гарантирует
    что контейнер уже существует. Observer решает это.
  */
  function startObserver() {
    var observer = new MutationObserver(function () {
      ensureButtonInserted();
    });

    observer.observe(document.body, {
      childList: true,
      subtree: true
    });
  }

  /*
    Точка входа.
  */
  function bootstrap() {
    ensureButtonInserted();
    startObserver();
  }

  // Запуск после готовности DOM
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
  } else {
    bootstrap();
  }
})();