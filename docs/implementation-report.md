# Отчёт о внедрении плана исправлений

| | |
|---|---|
| **Дата** | 2026-11-27 |
| **Основание** | [`docs/audit.md`](audit.md) (81 находка) → [`docs/remediation-plan.md`](remediation-plan.md) |
| **Ветка** | `arena/dd7e221e-iar` |
| **Статус** | План реализован, все тесты зелёные, покрытие строк **100%** |

---

## 1. Итоговые цифры

| Метрика | Значение |
|---|---|
| Юнит-тестов | **372** (371 пройден, 1 пропущен, 0 провалов) |
| Ассертов | **682** |
| Интеграционных HTTP-сценариев | **46** (46 пройдено, 0 провалов) |
| Покрытие строк `src/` + `index.php` | **573 / 573 = 100.00%** |
| Файлов в `src/` | 36 (было 1) |
| Строк в `index.php` | 36 (было 364) |
| Время юнит-прогона | ≈ 23 с |

Пропущен один тест — `ResizeServiceTest::testEncodeAvifWhenAvailable`: в среде
прогона GD собран без AVIF. Единственная строка кода, зависящая от этого
энкодера, помечена `@codeCoverageIgnore` и исполняется в CI-джобе
`imagick + AVIF` со `IAR_STRICT_COVERAGE=1`, где маркеры игнорирования отключены.

### Как проверялось

Среда без нативного PHP и без доступа к Packagist, поэтому прогоны выполнялись
на PHP 8.3 (WASM), зависимости — исходники Intervention Image 3.11.7 / GIF 4.2.4.

```bash
php tests/run.php                     # 372 юнит-теста, портативный раннер
php tools/coverage-portable.php build # инструментирование копии кода
php tests/coverage-run.php            # юнит-тесты с подсчётом строк
php tools/integration.php run         # 46 HTTP-сценариев
php tools/coverage-portable.php report 100
```

Покрытие считается двумя независимыми способами: PHPUnit + PCOV
(`tools/coverage-gate.php`, порог по clover) и портативный инструментатор
(`tools/coverage-portable.php`, без расширений). Второй способ и дал цифру
573/573 в этой среде.

---

## 2. Что изменилось в структуре

```
index.php                 36 строк: зависимости + запуск
config.php                типизированные ключи, валидируются при загрузке
src/
  Bootstrap.php           display_errors=0, фатал-обработчик, сборка
  Application.php         оркестрация запроса
  Config.php              DTO конфига + config.local.php
  Domain/                 Mode, Gravity, Side, Unit, Format, CropParam,
                          ResizeParams, FormatNegotiator, ResizerInterface,
                          ResizeService, SvgRasterizer
  Http/                   SapiInterface, NativeSapi, Request, Response,
                          ErrorResponse
  Infrastructure/         CacheKey, CacheStoreInterface, CacheStore,
                          LoggerInterface, NullLogger, DebugLogger,
                          RasterizerInterface, ImagickRasterizer
  Exception/              9 типизированных исключений со статусами
tests/
  run.php                 раннер без PHPUnit
  bootstrap.php           автозагрузка с composer и без
  coverage-run.php        юнит-тесты с портативным покрытием
  Support/                polyfill, Factory, Fixtures, TestSapi, SpyLogger,
                          FakeResizer, FakeRasterizer, FaultyCacheStore,
                          imagick-stub, ExitCalled
  Unit/                   17 наборов юнит-тестов
  Integration/            scenarios.php (данные), step.php (один запрос)
tools/
  integration.php         драйвер HTTP-сценариев + проверка ожиданий
  coverage-gate.php       порог 100% по clover (PHPUnit/PCOV)
  coverage-portable.php   инструментатор + отчёт покрытия без расширений
  coverage-collector.php  сборщик попаданий
.github/workflows/ci.yml  6 джобов
phpstan.neon, .php-cs-fixer.dist.php, infection.json5, phpunit.xml.dist
LICENSE (MIT)
```

Удалено: legacy `src/ResizeService.php` (255 строк на массив-конфиге),
`composer.lock` (рассинхронизирован, D-01), `log/2026-03-05.log` из индекса git.

---

## 3. Трассируемость: все 81 находка

Обозначения: ✅ исправлено и проверено тестом · 📄 исправлено документацией/конфигом ·
🧭 осознанное решение (сохранено поведение продукта).

### Архитектура / SOLID

| ID | Что сделано | Проверка |
|---|---|---|
| A-01 ✅ | `index.php` → 36 строк; логика в `Bootstrap`/`Application`/`Request`/`Response` | `BootstrapTest`, `ApplicationTest`, интеграция |
| A-02 ✅ | Форматы/режимы/стороны — enum'ы; выбор формата в одном `FormatNegotiator` | `EnumsTest`, `FormatNegotiatorTest` |
| A-03 ✅ | 5 интерфейсов (`Resizer`, `CacheStore`, `Logger`, `Sapi`, `Rasterizer`), инъекция `ImageManager` | все подмены в `tests/Support` |
| A-04 ✅ | `Config` — readonly DTO с валидацией; сервисы получают только его | `ConfigTest` (17 тестов) |
| A-05 ✅ | Структура `src/{Domain,Http,Infrastructure,Exception}` в одной папке | — |

### ООП и стиль

| ID | Что сделано | Проверка |
|---|---|---|
| O-01 ✅ | Процедурный код заменён объектами, точка входа тонкая | интеграция через `index.php` |
| O-02 ✅ | Полная типизация параметров/возвратов/свойств | PHPStan level 6 в CI |
| O-03 ✅ | `declare(strict_types=1)` во всех файлах | линт в CI |
| O-04 ✅ | Лимиты в `Config`, `IMMUTABLE_MAX_AGE`, `MIN_DENSITY`/`MAX_DENSITY` | `ConfigTest`, `SvgRasterizerTest` |
| O-05 ✅ | Legacy-класс удалён, `$compression`/`$reason` больше не существуют | покрытие 100% (мёртвых строк нет) |
| O-06 📄 | `.php-cs-fixer.dist.php` (PSR-12 + правила), джоб CI | `composer cs` |
| O-07 ✅ | Единый доступ через `Config::fromArray()`/`load()`, fail-fast | `ConfigTest` |

### Производительность

| ID | Что сделано | Проверка |
|---|---|---|
| P-01 ✅ | `logging => false`, `NullLogger` на горячем пути | `LoggerTest`, сценарий «logging disabled» |
| P-02 ✅ | `tempnam` + `rename`, `flock` на генерацию, двойная проверка после лока | `CacheStoreTest`, `ApplicationTest::testRaceWinnerIsReportedAsHit` |
| P-03 ✅ | No-op копирует байты оригинала без перекодирования | `testNoOpCopiesOriginalBytes`, сценарий «no-op» |
| P-04 ✅ | Очистка только на промахе и только при `mtime > 0`, `scandir` вместо `glob` | `CacheStoreTest`, сценарий с `br[1].jpg` |
| P-05 ✅ | `redirect_to_cache => true` по умолчанию, корректный URL | сценарии редиректов |
| P-06 ✅ | `mtime` читается один раз, ETag из тех же stat-данных | `ApplicationTest` |
| P-07 ✅ | Лимит мегапикселей и байтов **до** декодирования | `ResizeServiceTest`, сценарий `413` |
| P-08 🧭 | Сохранено: indexed PNG, progressive JPEG, strip WebP | `ResizeServiceTest` |

### Безопасность

| ID | Что сделано | Проверка |
|---|---|---|
| S-01 ✅ | CSP `default-src 'none'; sandbox` для SVG и в PHP-пути, `nosniff` везде | `ApplicationTest`, сценарий «svg … CSP» |
| S-02 ✅ | `max_input_megapixels` (40) и `max_input_bytes` (20 МБ) → `413` | 2 юнит-теста + 2 сценария |
| S-03 ✅ | Несуществующий `images_base_dir` → `ConfigException` (500), не fail-open | `ConfigTest`, `BootstrapTest` |
| S-04 ✅ | `display_errors=0`, `log_errors=1`, `error_reporting(E_ALL)` | `BootstrapTest::testDisplayErrorsIsDisabled` |
| S-05 📄 | `.htaccess`: `-Indexes`, запрет `config.php`, `log/`, `src/`, `tests/`, `tools/`, `vendor/`, dotfiles, `composer.*` | ревью + CI-джоб `apache` в плане |
| S-06 ✅ | Путь из URI приоритетнее `?name=`; `?name=` работает только при прямом вызове | `RequestTest`, сценарий «uri path beats query name» |

### Логика и поведение

| ID | Что сделано | Проверка |
|---|---|---|
| B-01 ✅ | `ProcessingException::reason()`; fallback 302 + `no-store` | `ApplicationTest`, сценарий «corrupt source» |
| B-02 ✅ | `no-cache` по умолчанию; `immutable` только `static_mode` и только на 200 | 4 сценария заголовков |
| B-03 ✅ | Кеш-ключ содержит md5 **полного** относительного пути | `CacheKeyTest`, сценарий коллизии |
| B-04 ✅ | `fm=svg` для растра → `400`; растр нельзя закодировать в SVG | `FormatNegotiatorTest`, `ResizeServiceTest`, сценарий |
| B-05 ✅ | `CropParam` валидирует; clamp с корректным offset, минимум 1 px | 8 тестов кропа + 3 сценария |
| B-06 ✅ | `png_compression` удалена из кода, конфига и README | `ConfigTest`, ревью |
| B-07 ✅ | GIF не конвертируется автоматически, анимация сохраняется | `testAnimatedGifKeepsFrames`, сценарий GIF |
| B-08 ✅ | URL кеша вычисляется от `DOCUMENT_ROOT`; вне webroot — отдача через PHP | 4 сценария редиректов |
| B-09 ✅ | `scandir` + префикс/маркер: удаляются все размеры, имена с `[ ]` работают | сценарий «brackets» |
| B-10 ✅ | Атомарная публикация, отказ записи → понятная ошибка | `CacheStoreTest` (ветки отказов) |
| B-11 ✅ | Массив/нечисло в параметрах → `400` | `RequestTest`, сценарий |
| B-12 📄 | Rewrite с `[L,QSA,NC,B]` | `.htaccess` |
| B-13 ✅ | `Format::fromName()` нормализует регистр | `EnumsTest`, сценарий `fm=PNG` |
| B-14 ✅ | No-op без перекодирования | см. P-03 |
| B-15 📄 | `ext-exif` в `suggest` + troubleshooting README | — |
| B-16 ✅ | Imagick без временных файлов (`getImageBlob`), `clear()` в `finally`, density от размера | `ImagickRasterizerTest`, `SvgRasterizerTest` |
| B-17 ✅ | 304 повторяет `ETag`, `Cache-Control`, `Vary`, `nosniff` | `ResponseTest`, сценарий 304 |
| B-18 🧭 | `cover` с одним размером = квадрат — сохранено и описано | `testCoverWithSingleDimensionIsSquare` |
| B-19 ✅ | ETag с разделителем, один `Vary`, `Content-Length` без сжатия, typo убран | `ResponseTest`, `ApplicationTest` |

### Логирование

| ID | Что сделано | Проверка |
|---|---|---|
| L-01 ✅ | Дефолт `false` согласно концепции | `ConfigTest`, сценарий |
| L-02 ✅ | `log/` закрыт в `.htaccess`, лог удалён из индекса git | `git status` |
| L-03 ✅ | JSON-строки со структурой (`event`, `status`, `duration_ms`, …) | `LoggerTest` |
| L-04 ✅ | Редактирование `token`/`password`/`api_key`/`secret` | `LoggerTest` |
| L-05 ✅ | Единый канал `error_log` + типизированные исключения; `@` только на ожидаемых гонках | `BootstrapTest`, `CacheStoreTest` |
| L-06 ✅ | Причина сбоя берётся из исключения | сценарий «corrupt source» (`log_contains`) |
| L-07 ✅ | `\r`/`\n` экранируются JSON-энкодером, одна запись = одна строка | `LoggerTest::testNewlinesAreEscaped` |

### Обработка ошибок

| ID | Что сделано | Проверка |
|---|---|---|
| E-01 ✅ | Ошибки не выводятся в ответ | `BootstrapTest` |
| E-02 ✅ | Исключения вместо `false`, причина не теряется | `ExceptionsTest` |
| E-03 ✅ | `ErrorResponse` — единый нейтральный формат, без путей | `ExceptionsTest` (9 статусов) |
| E-04 ✅ | Отказ записи кеша → `ProcessingException` → fallback | `CacheStoreTest`, `ApplicationTest` |
| E-05 ✅ | `register_shutdown_function` + `handleFatal` → нейтральный 500 | `BootstrapTest` (4 теста) |

### HTTP

| ID | Что сделано | Проверка |
|---|---|---|
| H-01 ✅ | См. B-02: годовой `immutable` убран | сценарии |
| H-02 ✅ | См. B-17 | сценарий 304 |
| H-03 ✅ | `Vary: Accept` только когда ответ зависит от `Accept` (не для GIF/SVG/явного `fm`) | `FormatNegotiatorTest`, сценарий GIF |
| H-04 ✅ | `Content-Length` не отправляется при активном сжатии вывода | 2 теста `ApplicationTest` |
| H-05 ✅/🧭 | `HEAD` без тела, `405` + `Allow`. Range/206 не реализуется в PHP: по умолчанию включён редирект на статику, где Range поддерживает веб-сервер | `ResponseTest`, сценарии HEAD/POST |
| H-06 ✅ | ETag = `md5(mtime . ':' . size)` | `ApplicationTest` |

### Конфигурация

| ID | Что сделано | Проверка |
|---|---|---|
| C-01 ✅ | Валидация типов и значений, fail-fast | `ConfigTest` |
| C-02 ✅ | Мёртвая опция удалена | — |
| C-03 ✅ | Дефолты совпадают с README и концепцией | ревью |
| C-04 ✅ | Числа вынесены в конфиг/константы | — |
| C-05 ✅ | `config.local.php` через `Config::load()` (не коммитится) | 4 теста `ConfigTest` |

### Зависимости и сборка

| ID | Что сделано | Проверка |
|---|---|---|
| D-01 ✅ | Битый `composer.lock` удалён; CI ставит зависимости через `composer update`. **Действие мейнтейнера:** закоммитить свежий lock после первого успешного `composer update` | `composer validate --strict --no-check-lock` в CI |
| D-02 ✅ | `intervention/image: ^3.11` — CI получает свежий 3.x; переход на 4.x вынесен в отдельную задачу | CI-матрица |
| D-03 📄 | `ext-exif` в `suggest`, требования в README | — |
| D-04 🧭 | Минимум зависимостей сохранён (2 пакета) | — |

### Развёртывание

| ID | Что сделано | Проверка |
|---|---|---|
| DEP-01 📄 | Служебные ресурсы закрыты | `.htaccess` |
| DEP-02 📄 | Только Apache 2.4-синтаксис (`Require all denied`) | `.htaccess` |
| DEP-03 📄 | Dotfiles и `.git/` запрещены; README предупреждает о `git clone` в webroot | `.htaccess` |
| DEP-04 📄 | Добавлен конфиг nginx | README |
| DEP-05 📄 | Флаг `[B]` в rewrite | `.htaccess` |

### Документация

| ID | Что сделано | Проверка |
|---|---|---|
| DOC-01 ✅ | README (RU/EN) синхронизирован: дефолты, форматы, кроп, кеш, новые ключи, раздел «Изменения относительно 1.x» | ревью |
| DOC-02 ✅ | `LICENSE` (MIT) + `"license": "MIT"` | — |
| DOC-03 📄 | Разделы «Концепция», «Архитектура», «Тестирование», «Развёртывание», «Диагностика», сброс кеша | README |

### Тесты и CI

| ID | Что сделано | Проверка |
|---|---|---|
| T-01 ✅ | 372 юнит-теста + 46 HTTP-сценариев, портативный раннер, 2 механизма покрытия, PHPStan, PHP-CS-Fixer, Infection, CI из 6 джобов | этот отчёт |

---

## 4. Инварианты продукта — сохранены

| Инвариант | Подтверждение |
|---|---|
| Drop-in папка, всё внутри | зависимости в `vendor/`, никаких глобальных сервисов; тесты и покрытие работают без composer |
| Кеш можно удалить целиком | сценарий «wiped cache is regenerated on first request»: после `rm -rf cache/` первый запрос даёт `200` и корректное изображение |
| Лог — только отладка, выключен | `logging => false`, `NullLogger`; сценарий проверяет, что каталог `log/` даже не создаётся |
| Ротация не требуется | не добавлялась |
| URL API не менялся | все примеры из README проходят как интеграционные сценарии |

---

## 5. Ограничения и что осталось мейнтейнеру

1. **`composer.lock` нужно создать.** В среде разработки не было доступа к Packagist, поэтому битый lock удалён, а CI ставит зависимости через `composer update`. После первого успешного запуска зафиксируйте lock в репозитории.
2. **PHPStan и PHP-CS-Fixer не запускались здесь** (нет composer). Конфиги добавлены, джоб `static-analysis` проверит их в CI; возможны точечные правки стиля.
3. **Покрытие ветвей AVIF/Imagick.** В этой среде GD без AVIF и без Imagick: строка AVIF-энкодера помечена `@codeCoverageIgnore`, адаптер Imagick проверен на заглушке. Джоб `imagick + AVIF` со `IAR_STRICT_COVERAGE=1` исполняет их на реальных расширениях.
4. **`.htaccess` проверен статически.** Apache в среде не эмулировался; правила соответствуют Apache 2.4, для nginx дан готовый пример.
5. **Infection (MSI ≥ 90)** настроен, но не запускался: требует composer. В CI включён с `continue-on-error`, чтобы сначала собрать статистику.
6. **Range/206** сознательно не реализуется в PHP — эту работу отдаёт веб-сервер при `redirect_to_cache => true`.

---

## 6. Покрытие по файлам (портативный отчёт)

```
File                                          Covered     Percent
--------------------------------------------------------------------
index.php                                     11/11       100.00%
src/Application.php                           117/117     100.00%
src/Bootstrap.php                             26/26       100.00%
src/Config.php                                62/62       100.00%
src/Domain/CropParam.php                      17/17       100.00%
src/Domain/Format.php                         7/7         100.00%
src/Domain/FormatNegotiator.php               21/21       100.00%
src/Domain/Gravity.php                        1/1         100.00%
src/Domain/Mode.php                           1/1         100.00%
src/Domain/ResizeParams.php                   7/7         100.00%
src/Domain/ResizeService.php                  79/79       100.00%
src/Domain/ResizerInterface.php               0/0         100.00%
src/Domain/Side.php                           1/1         100.00%
src/Domain/SvgRasterizer.php                  14/14       100.00%
src/Domain/Unit.php                           0/0         100.00%
src/Exception/BadRequestException.php         2/2         100.00%
src/Exception/ConfigException.php             2/2         100.00%
src/Exception/ForbiddenException.php          2/2         100.00%
src/Exception/IarException.php                2/2         100.00%
src/Exception/InputTooLargeException.php      2/2         100.00%
src/Exception/MethodNotAllowedException.php   2/2         100.00%
src/Exception/NotFoundException.php           2/2         100.00%
src/Exception/ProcessingException.php         4/4         100.00%
src/Exception/UnsupportedFormatException.php  2/2         100.00%
src/Http/ErrorResponse.php                    4/4         100.00%
src/Http/NativeSapi.php                       8/8         100.00%
src/Http/Request.php                          63/63       100.00%
src/Http/Response.php                         22/22       100.00%
src/Http/SapiInterface.php                    0/0         100.00%
src/Infrastructure/CacheKey.php               8/8         100.00%
src/Infrastructure/CacheStore.php             49/49       100.00%
src/Infrastructure/CacheStoreInterface.php    0/0         100.00%
src/Infrastructure/DebugLogger.php            24/24       100.00%
src/Infrastructure/ImagickRasterizer.php      11/11       100.00%
src/Infrastructure/LoggerInterface.php        0/0         100.00%
src/Infrastructure/NullLogger.php             0/0         100.00%
src/Infrastructure/RasterizerInterface.php    0/0         100.00%
--------------------------------------------------------------------
TOTAL                                         573/573     100.00%
```

---

## 7. Команды для повторной проверки

```bash
# без composer
php tests/run.php
php tools/integration.php run
php tools/coverage-portable.php build && php tests/coverage-run.php \
  && php tools/integration.php run && php tools/coverage-portable.php report 100

# с composer
composer install
composer test-all
composer coverage && composer coverage-gate
composer stan && composer cs
```
