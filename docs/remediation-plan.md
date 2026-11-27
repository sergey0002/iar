# Детальный план исправлений IAR + 100% тестовое покрытие

| | |
|---|---|
| **Дата** | 2026-11-27 |
| **Основание** | [`docs/audit.md`](audit.md) — 81 пункт (≈55 actionable, остальные info/позитив) |
| **Ветка** | `arena/dd7e221e-iar` |
| **Цель** | Исправить все находки аудита, сохранив концепцию продукта, и достичь **100% покрытия всего кода проекта тестами** |
| **Инварианты** | URL API не меняется; drop-in портабельность; кеш полностью удаляемый и пересоздаётся по URL; лог только для отладки, по умолчанию выключен, без ротации |

---

## 0. Принципы плана

1. **Сначала тесты, потом рефакторинг.** Characterization-тесты фиксируют *ожидаемое корректное* поведение и падают на текущем коде (доказательство бага). Рефакторинг и фиксы идут под защитой этого набора.
2. **100% покрытие достижимо только после рефакторинга.** Текущий god-script с `die/exit/header/readfile` нетестируем на 100%. Поэтому вводятся абстракции (`SapiInterface`, `Response` как объект, `Application::handle()` без побочных эффектов) — это делает весь код юнит-тестируемым.
3. **Каждая находка → задача → тест.** Матрица трассируемости (§6) связывает все 81 ID аудита с задачами и тест-кейсами. Ни один фикс не принимается без теста.
4. **Покрытие = 100% строк + 100% методов/классов кода проекта** (`src/`, `index.php`), **вендор исключается**. Ветви/пути — цель 100% через Xdebug + fault-injection; качество ассертов контролируется мутационным тестированием (Infection, MSI ≥ 90%).
5. **Обратная совместимость.** Публичный контракт — URL-параметры (`w,h,m,g,fm,ct,cr,cb,cl`) и поведение кеша. Внутренняя структура меняется свободно.
6. **Портабельность.** Никаких фреймворков/DI-контейнеров/внешних сервисов. Всё внутри папки `iar/`, зависимости — только Intervention (как сейчас) + dev-инструменты.

---

## 1. Стратегия тестирования и 100% покрытия

### 1.1 Инструменты (dev-зависимости)

| Инструмент | Версия | Назначение | Совместимость |
|---|---|---|---|
| PHPUnit | `^10.5` | Юнит/интеграционные тесты | PHP ≥ 8.1 (11 требует 8.2, 12 — 8.3; берём 10.5 для матрицы 8.1–8.4) |
| Xdebug | `^3.3` (`mode=coverage`) | Покрытие строк/ветвей/путей | все версии матрицы |
| PCOV | опционально | Быстрое строковое покрытие в CI | альтернатива Xdebug |
| PHPStan | `^2.1`, level max | Статанализ (ловит `$reason`, `$compression`, типы) | PHP ≥ 7.4 |
| PHP-CS-Fixer | `^3.64` | PSR-12 автоформат | PHP ≥ 7.4 |
| Infection | `^0.27` | Мутационное тестирование (качество ассертов) | PHP ≥ 8.1, PHPUnit 10 |
| `composer validate` | — | Синхронность lock | — |

> Версии проверить `composer require --dev` с platform-check при реализации (Packagist из песочницы аудита недоступен). Диапазоны подобраны под PHP 8.1 как минимальную версию проекта.

### 1.2 Пирамида тестов

```
tests/
  Unit/                     # быстрые, изолированные, без сети
    ConfigTest.php
    RequestTest.php
    CropParamTest.php
    FormatTest.php
    CacheKeyTest.php
    FormatNegotiatorTest.php
    ResizeServiceTest.php   # реальный GD на малых фикстурах
    CacheStoreTest.php      # реальный temp-каталог
    ResponseTest.php        # TestSapi (перехват header/readfile/exit)
    DebugLoggerTest.php
    ApplicationTest.php     # моки зависимостей
    ErrorMappingTest.php
  Integration/              # полный стек, реальная ФС, TestSapi
    FrontControllerTest.php # index.php целиком через симуляцию суперглобалов
    CacheLifecycleTest.php  # создание → hit → wipe → пересоздание
    InvalidationTest.php    # mtime/off/static
    RedirectTest.php        # 302/301, Location, custom cache_dir
    HeadersTest.php         # ETag/304/Vary/CSP/nosniff/405/HEAD
    SecurityTest.php        # traversal, symlink, fail-open, массивы, SVG XSS
    DosGuardTest.php        # лимит мегапикселей/байтов (без реального OOM)
  Http/                     # дымовые через встроенный сервер PHP
    SmokeTest.php           # php -S + router.php (эмуляция .htaccess)
  Apache/                   # опциональный CI-job в docker httpd:2.4
    HtaccessTest.php        # rewrite, deny, -Indexes, CSP, [B]
  Fixtures/
    site/                   # тестовый «корень сайта» с iar/
    generate.php            # генератор изображений (GD)
    bin/                    # малые бинарные фикстуры в git
  Support/
    TestSapi.php            # SapiInterface для перехвата вывода
    Router.php              # router для php -S
    CoverageGate.php        # парсер clover, порог 100%
```

### 1.3 Что значит «100% покрытия» (точное определение)

- **Объект покрытия:** `src/**` и `index.php` (код проекта). `vendor/`, `tests/`, `cache/`, `log/` — исключены.
- **Жёсткий гейт:** 100% **строк** и 100% **методов/классов** (clover/XML). CI падает при < 100% (`tools/coverage-gate.php`).
- **Ветви/пути:** цель 100% (Xdebug `mode=coverage` даёт branch/path). Достижимо fault-injection (§1.6). Отчёт публикуется; при недостижимости отдельных защитных ветвей — обоснование в `coverage-exclusions.md` (но цель — без исключений).
- **Качество ассертов:** Infection MSI ≥ 90%, Covered MSI ≥ 95% — чтобы 100% покрытие не было «пустым» (тесты без утверждений).
- **Entry point:** `index.php` после рефакторинга — ~30 строк wiring; покрывается интеграционным `FrontControllerTest` (реальный `require` скрипта с симуляцией `$_GET/$_SERVER` через `TestSapi`).

### 1.4 Как сделать HTTP-код тестируемым (ключ к 100%)

Вводится `SapiInterface`:
```php
interface SapiInterface {
    public function header(string $h, bool $replace = true, ?int $code = null): void;
    public function responseCode(int $code): void;
    public function readFile(string $path): void;
    public function exit(int $code = 0): void;   // в TestSapi — исключение ExitCalled
    public function headersSent(): bool;
}
final class NativeSapi implements SapiInterface { /* header(), readfile(), exit */ }
final class TestSapi implements SapiInterface { /* копит заголовки/тело/код, exit → ExitCalled */ }
```
`Response::send(SapiInterface $sapi)` — чистая логика поверх интерфейса. `Application::handle(Request): Response` — без `exit/header`. Это позволяет юнит-тестировать **все** ветки заголовков, 304, редиректов, CSP, 405, HEAD без реального SAPI.

### 1.5 Матрица окружений CI (для guard-веток)

| Job | PHP | GD | exif | Imagick | Цель |
|---|---|---|---|---|---|
| `default` | 8.1 / 8.2 / 8.3 / 8.4 | webp+avif | да | нет | основная матрица |
| `minimal` | 8.1 | только jpeg/png/gif | нет | нет | guard-ветки `function_exists('imageavif'/'imagewebp')`, exif-ориентация, SVG-fallback |
| `imagick` | 8.3 | webp+avif | да | да | SVG-растеризация, `setDensity`, rename |
| `apache` | 8.3 (docker httpd:2.4) | — | — | — | `.htaccess`: rewrite/deny/-Indexes/CSP/[B] |

`setup-php` позволяет включать/не включать расширения. «Отсутствие» AVIF/WebP тестируется на `minimal`-сборке GD.

### 1.6 Fault-injection для 100% ветвей

| Ветка | Как покрыть |
|---|---|
| `realpath()===false` (S-03) | конфиг с несуществующим `images_base_dir` |
| `mkdir` отказ / гонка | `cache_dir` — файл или read-only каталог |
| `file_put_contents`/`rename` отказ | read-only каталог; cross-device через мок `CacheStore` |
| `flock` конкуренция | два процесса в интеграционном тесте |
| `class_exists('Imagick')` false/true | матрица `minimal`/`imagick` |
| `function_exists('imageavif'/'imagewebp')` | матрица GD-сборок |
| `exif_read_data` отсутствует | матрица `minimal` |
| OOM-предохранитель | фикстура 140 Мп → `getimagesize`-guard отклоняет **до** decode (реальный OOM не тестируем) |
| glob-спецсимволы | фикстура `br[1].jpg` |
| анимированный GIF | фикстура 2 кадра → детект → пропуск авто-формата |
| 304 / 405 / HEAD / Range | соответствующие запросы в `HeadersTest` |
| невалидный конфиг (каждый ключ) | data-provider в `ConfigTest` |
| `ProcessingException` → fallback | битый `.jpg`, SVG без Imagick |
| shutdown-handler (fatal) | симуляция через `register_shutdown_function` в тесте |

### 1.7 Фикстуры

Малые бинарные фикстуры коммитятся в `tests/Fixtures/bin/`:
- `photo.jpg` 1200×800 градиент; `small.png` 100×100 альфа; `wide.png` 400×300;
- `anim.gif` 2 кадра; `exif.jpg` Orientation=6; `corrupt.jpg` (не изображение);
- `icon.svg` со `<script>`; `photo copy.jpg`, `фотка.jpg`, `a&b.jpg`, `br[1].jpg`;
- `nested/www/photo.jpg` — **другое** изображение с тем же mtime (тест коллизии B-03).

Генератор `tests/Fixtures/generate.php` (GD) создаёт большие файлы (48/140 Мп) во время setup — **не коммитятся**. Скрипт идемпотентен, запускается в CI перед тестами.

### 1.8 Coverage-gate (скетч)

```php
// tools/coverage-gate.php — вызывается в CI после phpunit --coverage-clover
$xml = simplexml_load_file($argv[1]);
$tot = 0; $cov = 0;
foreach ($xml->xpath('//file') as $f) {
    $name = (string)$f['name'];
    if (!str_contains($name, '/src/') && !str_ends_with($name, '/index.php')) continue;
    foreach ($f->line as $l) { if ((string)$l['type'] === 'stmt') { $tot++; if ((int)$l['count'] > 0) $cov++; } }
}
$pct = $tot ? $cov / $tot * 100 : 100;
fprintf(STDERR, "Project line coverage: %.2f%% (%d/%d)\n", $pct, $cov, $tot);
exit($pct >= 100.0 ? 0 : 1);
```

---

## 2. Целевая архитектура (портабельная, без фреймворков)

```
iar/
  index.php                  # ~30 строк wiring + shutdown-handler
  config.php                 # массив → Config::fromArray()
  src/
    Config.php               # DTO + валидация, fail-fast
    Application.php          # handle(Request): Response — оркестрация
    Http/
      Request.php            # fromGlobals/fromArray, валидация типов
      Response.php           # объект ответа: file/redirect/notModified/error
      SapiInterface.php
      NativeSapi.php
      ErrorResponse.php      # маппинг исключений → HTTP
    Domain/
      ResizeService.php      # implements ResizerInterface
      ResizerInterface.php
      ResizeParams.php       # DTO (w,h,mode,gravity,crop)
      CropParam.php          # DTO + валидация границ
      Format.php             # enum: mime, isRaster, supportsAnimation
      Mode.php / Gravity.php / Side.php / Unit.php  # enum
      FormatNegotiator.php   # auto_format + animation guard
    Infrastructure/
      CacheStore.php         # implements CacheStoreInterface: ключи, atomic put, flock, cleanOld
      CacheStoreInterface.php
      CacheKey.php           # построение ключа (substr, full md5, cfg-hash)
      DebugLogger.php        # implements LoggerInterface, по умолчанию NullLogger
      NullLogger.php
      LoggerInterface.php
    Exception/
      IarException.php       # base
      BadRequestException.php   # 400
      ForbiddenException.php    # 403
      NotFoundException.php     # 404
      UnsupportedFormatException.php # 400
      ConfigException.php       # 500
      ProcessingException.php   # fallback 302 / 500
```

### 2.1 Спецификация кеш-ключа (исправление B-03, B-19)

```
{filename}__{w}x{h}{cropSuffix}{modeSuffix}__{md5(relativePath)}_{mtime}{cfgHash}.{ext}
```
- `relativePath = substr($filePath, strlen($baseDir))` — **срез префикса**, не `str_replace` (B-03).
- `md5` полный (32 символа), не 10 (B-19).
- `cropSuffix = '_' . side . value . unit` — value валидирован (B-05).
- `modeSuffix` только при `mode != fit`; gravity при `cover && != center`.
- `mtime` по `cache_invalidation`/`static_mode` (как сейчас).
- `cfgHash` — короткий md5 качество-влияющих настроек (quality, lossless, indexed, progressive, strip): смена качества инвалидирует кеш. **Опционально, рекомендуется**; включается флагом конфига.
- Тесты: `CacheKeyTest` — коллизия nested/базового пути (разные ключи), смена качества (разные ключи при cfgHash), стабильность при одинаковых входах.

### 2.2 HTTP-политика (исправление B-02, H-01…H-06)

| Режим | Отдача | Статус | Cache-Control | ETag | Vary |
|---|---|---|---|---|---|
| `disable_cache` | stream | 200 | `no-cache, no-store, must-revalidate` | нет | Accept* |
| `mtime` + stream | stream | 200/304 | `no-cache` (revalidate) | да | Accept* |
| `mtime` + redirect | 302 | 302 | `no-cache` | да | Accept* |
| `static` + stream | stream | 200/304 | `public, max-age=31536000, immutable` | да | Accept* |
| `static` + redirect | 301 | 301 | `public, max-age=31536000` | да | Accept* |

\* `Vary: Accept` только при `auto_format=true` и без явного `fm` (H-03).
- **304** всегда повторяет `ETag`, `Cache-Control`, `Vary` (B-17/H-02).
- `X-Content-Type-Options: nosniff` — всегда (S-01).
- SVG: `Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox` (S-01).
- Методы: только `GET`/`HEAD`, иначе `405` + `Allow: GET, HEAD` (H-05). `HEAD` — без тела.
- `Content-Length` не слать при активном `zlib.output_compression` (H-04).
- ETag: `md5($mtime . ':' . $size)` (B-19/H-06).
- `Location` редиректа вычисляется из реального `cache_dir` относительно `DOCUMENT_ROOT` (B-08); вне webroot — стримить.

### 2.3 Валидация ввода (B-11, B-13, B-05, S-06)

| Параметр | Правило | Ошибка |
|---|---|---|
| `name` | строка, непустая; путь из `REQUEST_URI` (не `$_GET['name']`) | 400 |
| `w`,`h` | `FILTER_VALIDATE_INT`, `0..max` | 400 |
| `m` | enum `fit/cover/resize` (регистронезависимо) | default |
| `g` | enum 9 значений | default |
| `fm` | `strtolower` → enum `jpg/jpeg/png/webp/avif/gif` (svg запрещён как выход для растра) | 400/игнор |
| `ct/cr/cb/cl` | число ≥ 0; px ≤ размер, % ≤ 100; один параметр | 400 |
| массивы | любой параметр-массив → 400 | 400 |

### 2.4 Иерархия ошибок (E-01…E-05, B-01)

- `IarException` → база; наследники с HTTP-кодом (§2 дерево).
- `Application` бросает типизированные исключения; `ErrorResponse::from()` маппит в нейтральный ответ (детали — только в лог).
- `ProcessingException` → **fallback 302 на оригинал** с причиной в debug-лог (чинит B-01: причина есть всегда).
- `display_errors=0`, `log_errors=1`; `register_shutdown_function` → нейтральный 500 при fatal (E-05).
- Никаких `@` на критичных операциях: явная проверка + лог причины (E-04).

### 2.5 Конфиг: DTO + дефолты по концепции (C-01…C-05, L-01)

| Ключ | Тип | Дефолт | Валидация |
|---|---|---|---|
| `logging` | bool | **`false`** | — |
| `redirect_to_cache` | bool | **`true`** (после B-08/B-02) | — |
| `max_input_megapixels` | int | `40` | > 0 |
| `max_input_bytes` | int | `20_000_000` | > 0 |
| `max_width/max_height` | int | 2000 | > 0 |
| `jpeg/webp/avif_quality` | int | 82/85/75 | 1..100 |
| `png_compression` | — | **удалить** (B-06) или реализовать | — |
| `images_base_dir` | string | `realpath(__DIR__.'/../')` | **обязателен, realpath ≠ false** (S-03) |
| `cache_dir`,`log_dir` | string | `__DIR__.'/cache'`,`/log` | writable check |
| `cache_key_config_hash` | bool | `true` | — |
| остальные | — | как сейчас | по типу |

`Config::fromArray()` бросает `ConfigException` при невалидном значении (fail-fast). Профили dev/prod — через комментарии/env-опцию (C-05, опционально).

### 2.6 Логирование (L-01…L-07)

- `LoggerInterface` + `DebugLogger`/`NullLogger`. При `logging=false` — `NullLogger` (нулевые накладные расходы).
- Формат: одна строка JSON с `ts, level, event(hit/miss/fail), status, duration_ms, orig_bytes, new_bytes, saved_pct, url, params( sanitized)`. Без ротации (концепция).
- `params` — редактирование чувствительных полей; экранирование `\n` во всех полях (L-07).
- Один канал: `error_log` вендора не используется для запросов; ошибки обработки — через `LoggerInterface` (L-05).
- Каталог `log/` закрыт от веб-доступа (L-02/S-05).

### 2.7 Целевой `.htaccess` (S-05, DEP-01…DEP-05, B-12)

```apache
Options -Indexes
<IfModule mod_rewrite.c>
    RewriteEngine On
    # запрет dotfiles (.git, .env и т.п.)
    RewriteRule (^|/)\. - [F]
    # прямая отдача существующего кеша
    RewriteCond %{REQUEST_FILENAME} -f
    RewriteRule ^cache/ - [L]
    # изображения через index.php; [B] экранирует backreference (имена с &)
    RewriteCond $1 !^cache/
    RewriteRule ^(.*)\.(jpg|jpeg|png|gif|webp|avif|svg)$ index.php?name=$1.$2 [L,QSA,NC,B]
</IfModule>
# служебные ресурсы закрыты (Apache 2.4)
<FilesMatch "^(config\.php|composer\.(json|lock|phar))$">
    Require all denied
</FilesMatch>
<IfModule mod_authz_core.c>
    <DirectoryMatch "/(log|vendor|src|cache)/">
        Require all denied
    </DirectoryMatch>
</IfModule>
# CSP для SVG при прямой отдаче Apache
<IfModule mod_headers.c>
    <FilesMatch "\.svg$">
        Header set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; sandbox"
        Header set X-Content-Type-Options "nosniff"
    </FilesMatch>
</IfModule>
```
> `DirectoryMatch` в `.htaccess` не поддерживается — использовать `RewriteRule ^(log|vendor|src)/ - [F]` либо `<FilesMatch>` для файлов. Финальный вариант проверяется в Apache-job. Для Apache 2.2 — fallback-блок `<IfModule !mod_authz_core.c>` с `Order/Deny`.

---

## 3. Фазы и задачи

Оценки — человеко-дни одного разработчика. Зависимости указаны явно.

### Фаза 0 — Фундамент и быстрые победы (≈2 дня)

| Задача | Находки | Файлы | Суть изменения | Тесты | Приёмка |
|---|---|---|---|---|---|
| **T0.1** Каркас тестов | T-01 | `composer.json`, `phpunit.xml`, `tests/` | PHPUnit ^10.5, bootstrap, testsuites, coverage include `src/`+`index.php`, exclude vendor | смоук-тест проходит | `vendor/bin/phpunit` зелёный |
| **T0.2** Фикстуры + генератор | T-01 | `tests/Fixtures/` | малые bin в git, `generate.php` для больших | `FixturesTest` проверяет наличие/валидность | фикстуры создаются идемпотентно |
| **T0.3** TestSapi + Router | T-01, O-01 | `tests/Support/` | `SapiInterface`-заглушка, router для `php -S` | юнит TestSapi | перехват header/body/exit работает |
| **T0.4** Coverage-gate + CI-скелет | T-01 | `tools/coverage-gate.php`, `.github/workflows/ci.yml` | matrix 8.1–8.4, `php -l`, `composer validate`, phpunit+coverage, gate | CI запускается | гейт падает при <100% |
| **T0.5** Characterization-тесты (красные) | S-01,S-02,B-01,B-02,B-03,B-05,B-06,B-07,B-11 | `tests/Integration/` | тесты *ожидаемого корректного* поведения на текущем коде | ~25 красных тестов | каждый фикс имеет падающий тест-доказательство |
| **T0.6** `logging=false` | L-01,P-01,C-03 | `config.php:44` | дефолт по концепции | `ConfigTest`, бенчмарк hit без лога | лог не пишется по умолчанию |
| **T0.7** `display_errors=0` | S-04,E-01 | `index.php:2–4` | убрать вывод ошибок, `log_errors=1` | `SecurityTest` (нет путей в ответе) | warning не уходит в браузер |
| **T0.8** `.htaccess` hardening | S-05,DEP-01,DEP-02,DEP-03,B-12 | `.htaccess` | §2.7: -Indexes, deny, dotfiles, [B], 2.4-синтаксис | Apache-job `HtaccessTest` | логи/.git/vendor закрыты, `&`-имена работают |
| **T0.9** `composer update --lock` | D-01 | `composer.lock` | синхронизация content-hash/platform | `composer validate --strict` | валидатор зелёный |
| **T0.10** LICENSE + убрать лог из git | DOC-02,L-02,R-04 | `LICENSE`, `composer.json`, `git rm --cached log/2026-03-05.log` | MIT-текст, `"license":"MIT"`, лог не в git | `git ls-files log/` пуст | LICENSE есть, лог не отслеживается |

### Фаза 1 — Рефакторинг тестируемости (≈3 дня)

> Цель — структура §2 без изменения поведения. Characterization-тесты остаются красными для багов, зелёными для сохранённого поведения.

| Задача | Находки | Суть | Тесты | Приёмка |
|---|---|---|---|---|
| **T1.1** Config DTO | A-04,C-01,O-07 | `Config::fromArray` + валидация, fail-fast | `ConfigTest` data-provider (все ключи, невалидные значения) | невалидный конфиг → `ConfigException` |
| **T1.2** Request + enum | O-02,B-11,B-13,S-06 | `Request::fromArray`, типизация, whitelist, путь из URI | `RequestTest` (массивы→400, регистр fm, name override) | все параметры типизированы |
| **T1.3** Format/Mode/Gravity/CropParam DTO | A-02,O-02,B-04,B-05 | enum + DTO, валидация границ кропа | `FormatTest`,`CropParamTest` | fm=svg для растра → исключение; кроп-границы |
| **T1.4** SapiInterface + Response | A-01,O-01,H-* | `Response` объект, `send(SapiInterface)`, `NativeSapi` | `ResponseTest` (все статусы/заголовки) | HTTP-код юнит-тестируем |
| **T1.5** CacheStore + CacheKey | A-01,B-03,B-10,B-09 | ключи §2.1, atomic put, flock, cleanOld | `CacheKeyTest`,`CacheStoreTest` | коллизии нет, запись атомарна |
| **T1.6** ResizeService refactor | A-03,O-02 | `ResizerInterface`, инъекция драйвера, типизация `$image` | `ResizeServiceTest` | поведение сохранено (GIF-анимация, indexed PNG) |
| **T1.7** FormatNegotiator | B-07 | auto_format + animation guard | `FormatNegotiatorTest` | анимированный GIF не конвертируется |
| **T1.8** Application + ErrorResponse | A-01,E-02,E-03,B-01 | `handle(Request):Response`, иерархия исключений, fallback с причиной | `ApplicationTest`,`ErrorMappingTest` | все ветки оркестрации покрыты |
| **T1.9** DebugLogger/NullLogger | L-03,L-05,L-07,A-01 | интерфейс, JSON-формат, sanitize, один канал | `DebugLoggerTest` | NullLogger при logging=false |
| **T1.10** index.php wiring | A-01,O-01,E-05 | ~30 строк + shutdown-handler | `FrontControllerTest` | entry point покрыт на 100% |

### Фаза 2 — Исправление всех находок в новой структуре (≈3 дня)

> Каждый фикс переводит соответствующие characterization-тесты из красных в зелёные.

| Задача | Находки | Суть фикса | Тесты (зелёные) |
|---|---|---|---|
| **T2.1** SVG CSP/nosniff | S-01 | `Response` для svg ставит CSP+sandbox+nosniff; санитизация при копировании | `SecurityTest::svgHasCsp`, `svgScriptNeutralized` |
| **T2.2** Лимит входа | S-02,P-07 | `getimagesize` до read, мегапиксели/байты из конфига | `DosGuardTest` (140 Мп → 400/413 без OOM) |
| **T2.3** fail-fast base_dir | S-03 | `ConfigException` при realpath=false | `ConfigTest`, `SecurityTest::failOpenClosed` |
| **T2.4** fallback с причиной | B-01,L-06,E-02 | `ProcessingException` несёт reason → 302 + debug-log | `IntegrationTest::corruptRedirects`, `svgNoImagickRedirects` |
| **T2.5** HTTP-кеш политика | B-02,H-01,H-02,H-03,H-04,H-06 | §2.2: no-cache для mtime, immutable только static, 304 с заголовками, Vary условный, ETag с разделителем | `HeadersTest` (все режимы) |
| **T2.6** кеш-ключ substr | B-03,B-19 | §2.1 | `CacheKeyTest::noCollisionNested` |
| **T2.7** fm whitelist | B-04,B-13 | отдельный whitelist выходов, strtolower | `RequestTest`, `IntegrationTest::fmSvgRejected` |
| **T2.8** кроп-границы | B-05 | clamp [0,размер]/[0,100%] + offset | `CropParamTest`, `ResizeServiceTest::cropNoBlackBars` |
| **T2.9** png_compression | B-06,C-02 | удалить опцию из конфига/README (или реализовать энкодер) | `ConfigTest::noDeadOption`, README-линк |
| **T2.10** GIF-анимация | B-07 | пропуск авто-формата для анимированных | `FormatNegotiatorTest`, `IntegrationTest::animatedGifStaysGif` |
| **T2.11** redirect cache_dir | B-08 | URL из конфига относительно DOCUMENT_ROOT | `RedirectTest::customCacheDir` |
| **T2.12** auto_clean | B-09,P-04 | экранирование glob, очистка всех размеров | `CacheStoreTest::cleanSpecialChars`, `cleanAllSizes` |
| **T2.13** atomic + flock | B-10,P-02 | temp+rename, lock, @mkdir+recheck | `CacheStoreTest::atomicWrite`, `stampedeSingleGeneration` |
| **T2.14** no-op shortcut | B-14,P-03 | copy/redirect без перекодирования | `IntegrationTest::noopNoReencode` |
| **T2.15** EXIF | B-15,D-03 | ext-exif в require/suggest + проверка | `ResizeServiceTest` (матрица exif) |
| **T2.16** Imagick-мелочи | B-16 | cleanup temp, rename check, setDensity | `ResizeServiceTest` (imagick-job) |
| **T2.17** методы/HEAD/Range | H-05 | 405+Allow, HEAD без тела, Range опц. | `HeadersTest::methodNotAllowed`, `headNoBody` |
| **T2.18** redirect_to_cache=true | P-05,C-03 | дефолт после T2.5/T2.11 | `RedirectTest`, README |
| **T2.19** статика/производительность | P-06 | один stat() | `ResponseTest` |
| **T2.20** log security | L-02,L-04,L-07 | deny log/, sanitize, escape | `DebugLoggerTest`, Apache-job |
| **T2.21** ошибки без @ | E-04 | явные проверки + лог | `CacheStoreTest::writeFailureLogged` |
| **T2.22** magic numbers | O-04,C-04 | константы/конфиг | `ConfigTest` |
| **T2.23** legacy cleanup | O-05 | удалить thubms, мёртвые правила, актуализировать комментарии | grep-тест отсутствия legacy |
| **T2.24** PSR-12 | O-06 | CS-Fixer + .editorconfig | CI `cs-check` |
| **T2.25** strict_types | O-03 | declare во всех файлах | PHPStan |

### Фаза 3 — 100% покрытие, качество, документация (≈2 дня)

| Задача | Находки | Суть | Тесты/гейты |
|---|---|---|---|
| **T3.1** Fault-injection ветвей | все | §1.6: read-only ФС, cross-device, flock, guard-матрица | branch coverage 100% |
| **T3.2** Покрытие entry point | O-01 | `FrontControllerTest` покрывает index.php целиком | line coverage index.php = 100% |
| **T3.3** Infection | T-01 | MSI ≥ 90%, Covered MSI ≥ 95% | мутационный отчёт |
| **T3.4** PHPStan max | O-02,E-02 | level max, 0 ошибок | CI `stan` |
| **T3.5** Зависимости | D-02 | image → 3.11.10, план v4 | `composer validate`, тесты зелёные |
| **T3.6** README синхронизация | DOC-01,DOC-03,C-03 | §2.5 дефолты, концепция, nginx, сброс кеша, troubleshooting | doc-линтер ссылок |
| **T3.7** nginx-пример | DEP-04 | `docs/nginx.conf.example` | ручная проверка/Apache-job аналог |
| **T3.8** Финальные гейты | T-01 | coverage-gate 100%, stan, cs, infection, composer validate | CI полностью зелёный |
| **T3.9** Отчёт о покрытии | T-01 | `docs/coverage.md` — итог, исключения (если есть) | артефакт CI |

---

## 4. Порядок выполнения и зависимости

```
T0.1 → T0.2 → T0.3 → T0.4 → T0.5 (красные тесты)
T0.6…T0.10 (быстрые победы, параллельно)
        ↓
T1.1 → T1.2 → T1.3 → T1.4 → T1.5 → T1.6 → T1.7 → T1.8 → T1.9 → T1.10
        ↓ (структура готова, тесты-доказательства на месте)
T2.1 … T2.25 (фиксы, каждый зелёнит свои тесты; порядок по severity)
        ↓
T3.1 → T3.2 → T3.3 → T3.4 → T3.5 → T3.6 → T3.7 → T3.8 → T3.9
```

Критический путь: **T0.5 → T1.* → T2.* → T3.1/T3.8**. Быстрые победы T0.6–T0.10 не блокируют рефакторинг.

---

## 5. CI/CD (`.github/workflows/ci.yml`, скетч)

```yaml
name: CI
on: [push, pull_request]
jobs:
  test:
    strategy:
      matrix:
        php: ['8.1','8.2','8.3','8.4']
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: gd, mbstring, exif
          coverage: xdebug
          tools: composer
      - run: composer install --no-interaction
      - run: composer validate --strict
      - run: php tests/Fixtures/generate.php
      - run: vendor/bin/phpunit --coverage-clover clover.xml --coverage-text
      - run: php tools/coverage-gate.php clover.xml   # 100% строк проекта
      - run: vendor/bin/phpstan analyse --level=max
      - run: vendor/bin/php-cs-fixer fix --dry-run --diff
  minimal:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.1', extensions: gd, mbstring, coverage: xdebug }
      - run: composer install && php tests/Fixtures/generate.php
      - run: vendor/bin/phpunit --testsuite unit,integration   # guard-ветки без exif/imagick/avif
  imagick:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', extensions: gd, mbstring, exif, imagick, coverage: xdebug }
      - run: composer install && php tests/Fixtures/generate.php && vendor/bin/phpunit
  infection:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', extensions: gd, mbstring, exif, coverage: xdebug }
      - run: composer install && php tests/Fixtures/generate.php
      - run: vendor/bin/infection --min-msi=90 --min-covered-msi=95
  apache:
    runs-on: ubuntu-latest
    container: httpd:2.4
    steps:
      - uses: actions/checkout@v4
      - run: |
          cp -r tests/Fixtures/site /usr/local/apache2/htdocs/
          sed -i 's/#LoadModule rewrite_module/LoadModule rewrite_module/' conf/httpd.conf
          sed -i 's/#LoadModule headers_module/LoadModule headers_module/' conf/httpd.conf
          sed -i 's/AllowOverride None/AllowOverride All/' conf/httpd.conf
          httpd-foreground &
          sleep 2
          bash tests/Apache/run.sh   # curl-проверки rewrite/deny/-Indexes/CSP/[B]
```

---

## 6. Матрица трассируемости: находка → задача → тест

| ID аудита | Задача(и) | Тест-кейс(ы) |
|---|---|---|
| A-01 | T1.4,T1.8,T1.10 | `ApplicationTest`, `FrontControllerTest` |
| A-02 | T1.3,T1.7 | `FormatTest`, `FormatNegotiatorTest` |
| A-03 | T1.6 | `ResizeServiceTest` (инъекция драйвера/мока) |
| A-04 | T1.1 | `ConfigTest` |
| A-05 | T1.* (blueprint) | структурный тест наличия классов |
| O-01 | T1.4,T1.10 | `FrontControllerTest` |
| O-02 | T1.2,T1.3,T1.6,T3.4 | PHPStan max + юнит-тесты DTO |
| O-03 | T2.25 | PHPStan + `RequestTest` |
| O-04 | T2.22 | `ConfigTest` |
| O-05 | T2.23 | grep-тест отсутствия legacy |
| O-06 | T2.24 | CI cs-check |
| O-07 | T1.1 | `ConfigTest` (отсутствующий ключ → исключение) |
| P-01 | T0.6,T1.9 | `DebugLoggerTest`, бенчмарк |
| P-02 | T2.13 | `CacheStoreTest::stampedeSingleGeneration` |
| P-03 | T2.14 | `IntegrationTest::noopNoReencode` |
| P-04 | T2.12 | `CacheStoreTest::cleanAllSizes` |
| P-05 | T2.18 | `RedirectTest` |
| P-06 | T2.19 | `ResponseTest` |
| P-07 | T2.2 | `DosGuardTest` |
| P-08 | — (позитив, сохранить) | регресс-тесты кеша |
| S-01 | T2.1 | `SecurityTest::svgHasCsp` |
| S-02 | T2.2 | `DosGuardTest` |
| S-03 | T2.3 | `SecurityTest::failOpenClosed` |
| S-04 | T0.7 | `SecurityTest::noPathDisclosure` |
| S-05 | T0.8 | Apache `HtaccessTest` |
| S-06 | T1.2 | `RequestTest::nameFromUriNotQuery` |
| B-01 | T2.4 | `IntegrationTest::corruptRedirects` |
| B-02 | T2.5 | `HeadersTest::mtimeNoImmutable` |
| B-03 | T2.6 | `CacheKeyTest::noCollisionNested` |
| B-04 | T2.7 | `IntegrationTest::fmSvgRejected` |
| B-05 | T2.8 | `ResizeServiceTest::cropNoBlackBars` |
| B-06 | T2.9 | `ConfigTest::noDeadOption` |
| B-07 | T2.10 | `IntegrationTest::animatedGifStaysGif` |
| B-08 | T2.11 | `RedirectTest::customCacheDir` |
| B-09 | T2.12 | `CacheStoreTest::cleanSpecialChars` |
| B-10 | T2.13 | `CacheStoreTest::atomicWrite` |
| B-11 | T1.2 | `RequestTest::arrayParamRejected` |
| B-12 | T0.8 | Apache `HtaccessTest::ampersandName` |
| B-13 | T1.2,T2.7 | `RequestTest::fmCaseInsensitive` |
| B-14 | T2.14 | `IntegrationTest::noopNoReencode` |
| B-15 | T2.15 | `ResizeServiceTest` (exif-матрица) |
| B-16 | T2.16 | `ResizeServiceTest` (imagick-job) |
| B-17 | T2.5 | `HeadersTest::notModifiedHasHeaders` |
| B-18 | T3.6 (документировать) | `IntegrationTest::coverSingleDimSquare` |
| B-19 | T2.6,T2.5 | `CacheKeyTest`, `HeadersTest` |
| L-01 | T0.6 | `ConfigTest::loggingDefaultFalse` |
| L-02 | T0.10,T2.20 | Apache `HtaccessTest::logDenied`, `git ls-files` |
| L-03 | T1.9 | `DebugLoggerTest::jsonFormat` |
| L-04 | T2.20 | `DebugLoggerTest::sanitizesParams` |
| L-05 | T1.9 | `DebugLoggerTest::singleChannel` |
| L-06 | T2.4 | `IntegrationTest::failLogsReason` |
| L-07 | T2.20 | `DebugLoggerTest::escapesNewlines` |
| E-01 | T0.7 | `SecurityTest::noPathDisclosure` |
| E-02 | T1.8,T2.4 | `ErrorMappingTest` |
| E-03 | T1.8 | `ErrorMappingTest::neutralMessages` |
| E-04 | T2.21 | `CacheStoreTest::writeFailureLogged` |
| E-05 | T1.10 | `FrontControllerTest::fatalShutdown` |
| C-01 | T1.1 | `ConfigTest` |
| C-02 | T2.9 | `ConfigTest::noDeadOption` |
| C-03 | T0.6,T2.18,T3.6 | `ConfigTest`, README-линтер |
| C-04 | T2.22 | `ConfigTest` |
| C-05 | T3.6 (опция) | документация env |
| H-01 | T2.5 | `HeadersTest` |
| H-02 | T2.5 | `HeadersTest::notModifiedHasHeaders` |
| H-03 | T2.5 | `HeadersTest::varyConditional` |
| H-04 | T2.5 | `HeadersTest::contentLengthNoCompression` |
| H-05 | T2.17 | `HeadersTest::methodNotAllowed` |
| H-06 | T2.5 | `HeadersTest::etagSeparator` |
| D-01 | T0.9 | `composer validate --strict` |
| D-02 | T3.5 | тесты на 3.11.10 |
| D-03 | T2.15 | `ResizeServiceTest` exif |
| D-04 | — (позитив) | регресс |
| T-01 | T0.1…T0.4,T3.* | весь набор + гейты |
| DOC-01 | T3.6 | README-линтер |
| DOC-02 | T0.10 | наличие LICENSE |
| DOC-03 | T3.6,T3.7 | nginx-пример, раздел сброса кеша |
| DEP-01 | T0.8 | Apache `HtaccessTest` |
| DEP-02 | T0.8 | Apache 2.4 job |
| DEP-03 | T0.8 | Apache `HtaccessTest::dotfilesDenied` |
| DEP-04 | T3.7 | nginx-пример |
| DEP-05 | T0.8 | Apache `HtaccessTest::ampersandName` |

**Все 81 ID покрыты задачами и тестами** (info/позитив — документацией/регрессом).

---

## 7. Достижение 100% покрытия: инвентаризация путей

| Файл/класс | Ключевые ветки | Как покрыть |
|---|---|---|
| `Config` | каждый ключ: валидный/невалидный/отсутствует | data-provider `ConfigTest` |
| `Request` | каждый параметр: тип/границы/массив/регистр | data-provider `RequestTest` |
| `CropParam` | px/%/negative/>100/>размер/несколько сторон | `CropParamTest` |
| `Format`/enum | все значения + недопустимые | `FormatTest` |
| `CacheKey` | nested-коллизия, cfgHash, mtime-режимы | `CacheKeyTest` |
| `CacheStore` | hit/miss/atomic/flock/clean/read-only/cross-device | `CacheStoreTest` + fault-injection |
| `FormatNegotiator` | Accept webp/avif/нет, fm explicit, animated gif, svg | `FormatNegotiatorTest` + GD-матрица |
| `ResizeService` | fit/cover/resize, gravity, crop, все форматы, svg copy/imagick/fallback, exif | `ResizeServiceTest` + матрица окружений |
| `Response` | 200/304/302/301/400/403/404/405/500, HEAD, CSP, Vary, Content-Length | `ResponseTest` (TestSapi) |
| `Application` | hit/miss/fail/fallback/invalid | `ApplicationTest` (моки) |
| `DebugLogger`/`NullLogger` | on/off, sanitize, escape, write-fail | `DebugLoggerTest` |
| `ErrorResponse` | все исключения → коды | `ErrorMappingTest` |
| `index.php` | wiring + shutdown | `FrontControllerTest` |

Fault-injection (§1.6) закрывает защитные ветки (отказы ФС, guard-функции, гонки). Матрица окружений (§1.5) закрывает ветки зависимости от расширений. Infection (§1.1) гарантирует, что покрытие не «пустое».

---

## 8. Риски и откат

| Риск | Митигация |
|---|---|
| Рефакторинг ломает URL-контракт | Characterization-тесты URL API до рефакторинга; контрактные тесты после |
| Смена дефолта `redirect_to_cache` меняет поведение | Флаг конфига; документация; тесты обоих режимов; поэтапное включение |
| 100% branch недостижимо на защитных ветках | Fault-injection + матрица; при остатке — обоснованные исключения в `coverage-exclusions.md` (цель — 0) |
| GD-сборки без AVIF/WebP в CI | `minimal`-job явно тестирует guard-ветки; не требовать AVIF для зелёного CI |
| Apache-job сложен в CI | Опциональный job; `.htaccess` дополнительно проверяется статически |
| Мутационное тестирование медленное | Отдельный job, не блокирует основной; порог MSI 90% |
| Откат фикса | Каждая задача — отдельный коммит; `git revert` точечно |

---

## 9. Definition of Done

**Проект считается исправленным, когда:**
1. Все 81 ID аудита закрыты задачами (§6) и подтверждены тестами.
2. `vendor/bin/phpunit` зелёный на матрице PHP 8.1–8.4 + minimal + imagick.
3. **Coverage-gate: 100% строк и методов кода проекта** (`src/`, `index.php`); branch/path отчёт = 100% (или обоснованные исключения).
4. Infection MSI ≥ 90%, Covered MSI ≥ 95%.
5. PHPStan level max — 0 ошибок; PHP-CS-Fixer — 0 диффов.
6. `composer validate --strict` — зелёный; lock синхронизирован.
7. Apache-job: `.htaccess` закрывает служебные ресурсы, rewrite с `[B]` работает.
8. README синхронизирован с кодом и концепцией; LICENSE на месте; лог не в git.
9. Концепция подтверждена тестами: drop-in, полный сброс кеша → пересоздание по URL, `logging=false` по умолчанию.
10. CI полностью зелёный; отчёт о покрытии в `docs/coverage.md`.

**Оценка суммарно:** ≈10 человеко-дней (Фаза 0 ≈2, Фаза 1 ≈3, Фаза 2 ≈3, Фаза 3 ≈2). Критический путь — рефакторинг тестируемости (Фаза 1), без которого 100% покрытие недостижимо.

---

## 10. Приложение: артефакты, создаваемые планом

| Артефакт | Путь |
|---|---|
| Каркас тестов | `phpunit.xml`, `tests/` |
| Фикстуры + генератор | `tests/Fixtures/` |
| TestSapi / Router / Coverage-gate | `tests/Support/`, `tools/coverage-gate.php` |
| CI | `.github/workflows/ci.yml` |
| Целевая архитектура | `src/` (§2) |
| Конфиг-дефолты | `config.php` (§2.5) |
| `.htaccess` | `.htaccess` (§2.7) |
| LICENSE | `LICENSE` |
| nginx-пример | `docs/nginx.conf.example` |
| Отчёт покрытия | `docs/coverage.md` |
| План (этот документ) | `docs/remediation-plan.md` |
