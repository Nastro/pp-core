# Шаблонизатор: DI и миграция со Smarty 2 на Twig

Пользовательский (клиентский) движок шаблонов выбирается через DI-контейнер.
По умолчанию, без какой-либо конфигурации, используется легаси-слой
`PXUserHTMLLayout` (Smarty 2) — полная обратная совместимость.

## Подключение Twig в проекте

В `app/config/services.yml` проекта определите **публичный** сервис
с id `PP\Lib\Html\Layout\LayoutInterface`:

```yaml
services:
    PP\Lib\Html\Layout\LayoutInterface:
        class: PP\Lib\Html\Layout\TwigLayout
        public: true
```

`PXEngineIndex::initLayout()` заберёт сервис из контейнера; если сервис
не определён — создаст `PXUserHTMLLayout` (Smarty 2). Сервис обязан
реализовывать `PP\Lib\Html\Layout\UserLayoutInterface`.

Не забудьте сбросить кэш контейнера (`CACHE_PATH/container.php`) после
изменения `services.yml`.

## TwigLayout

- Шаблоны ищутся в тех же каталогах, что и Smarty: `local/templates/`,
  затем `libpp/templates/`, но с расширением `.twig`. Имена `.tmpl`,
  приходящие из PHP-кода (`$layout->html('misc/pager/pages.tmpl')`),
  прозрачно переписываются в `.twig`.
- Автоэкранирование выключено, неопределённые переменные не являются
  ошибкой — так же, как в Smarty 2.
- Неизвестные фильтры и функции Twig резолвятся в глобальные PHP-функции
  (`{{ x|quot }}`, `{{ smarty_file_exists('a.twig') }}`) — аналог
  фолбэка модификаторов Smarty 2 на PHP-функции.
- Smarty-совместимые фильтры (`smarty_escape`, `smarty_default`,
  `smarty_replace`, `cat`, `strip`, `date_format`, `regex_replace`,
  `truncate`, `isset`, `empty`, ...) живут в
  `PP\Lib\Html\Twig\SmartyCompatExtension`.
- Функции ядра зарегистрированы из коробки: `property`, `lang`, `pager`,
  `autopager`, `createpath`, `img`, `html_import`, `jquery`,
  `layout_var`, `pager_href`; модификаторы `property`, `lang`,
  `date_to_time`.
- Проектные функции/модификаторы регистрируются так же, как раньше:
  `$layout->addTemplateFunction('name', $callback)` /
  `addTemplateModifier()` — колбэки в Smarty-стиле
  `($params, &$smarty)` работают без изменений.
- Пагинация: `{{ pager({objects: ..., format: ...}) }}` и
  `{{ autopager({format: ...}) }}` рендерят `misc/pager/pages.twig`
  (совместимо со Smarty-версией байт-в-байт).

## Конвертация шаблонов: `pp templates:convert`

```bash
pp templates:convert                      # BASEPATH/local/templates рекурсивно
pp templates:convert local/templates/lt   # только поддерево
pp templates:convert --dry-run            # отчёт без записи файлов
pp templates:convert --force              # перезаписать существующие .twig
pp templates:convert --verify             # после конвертации сравнить рендер
```

Каждый `foo.tmpl` конвертируется в `foo.twig` рядом с исходником.
Статусы в отчёте:

- `OK` — конвертирован полностью;
- `CHECK` — конвертирован, но есть предупреждения (например,
  `{assign}` внутри цикла: в Twig `{% set %}` локален для цикла,
  а в Smarty присваивание «протекает» наружу — если переменная
  читается после цикла, нужен ручной рефакторинг);
- `MANUAL` — есть конструкции без автоматического аналога
  (`{php}`, `{break}`/`{continue}`, `{section step=...}`,
  несуществующие модификаторы). Такие места остаются в выводе
  в виде `{# UNCONVERTED: ... #}` с указанием файла и строки в отчёте.

Конвертер сохраняет байтовую совместимость вывода, включая тонкости
Smarty 2 с переносами строк после тегов и семантику `{strip}`.

## Проверка результата: `pp templates:verify`

```bash
pp templates:verify                        # все пары .tmpl/.twig
pp templates:verify local/templates/lt
pp templates:verify --context=fixture.json # добавить тестовые переменные
pp templates:verify --strict               # байтовое сравнение (по умолчанию
                                           # сравнение без учёта whitespace)
```

Каждая пара рендерится обоими движками (Smarty 2 и Twig) с одинаковым
набором переменных (стандартный набор `fillLayout` + фикстуры из
`--context`), HTML сравнивается, в консоль выводится статус
`OK` / `DIFF` / `SMARTY_ERROR` / `TWIG_ERROR` / `BOTH_ERROR` по каждому
шаблону и итоговая сводка. Для `DIFF` печатается позиция и фрагмент
первого расхождения.

## Известные ограничения автоконвертации

| Конструкция | Что делать |
|---|---|
| `{php}...{/php}` | переносить логику в модуль/функцию вручную |
| `{break}`, `{continue}` | в Twig нет; переписать через `filter`/условие |
| `{section step=... / max=...}` | переписать на `{% for %}` вручную |
| `{assign}` в цикле, читаемый после цикла | вынести из цикла или собрать через `layout_var` |
| `===`, `!==` | конвертируются в `==`/`!=` с предупреждением |
| `{cycle}`, `{counter}`, `{eval}`, `{fetch}`, `{html_*}` | ручной порт (используются крайне редко) |
