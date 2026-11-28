# IAR — Image Auto Resizer

<p align="center">
  <a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License: MIT"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/php-%3E%3D8.1-8892bf.svg" alt="PHP Version"></a>
  <a href="https://github.com/Intervention/image"><img src="https://img.shields.io/badge/Intervention%20Image-v3-blue.svg" alt="Intervention Image v3"></a>
  <img src="https://img.shields.io/badge/WebP%20%2F%20AVIF-Auto-green.svg" alt="WebP AVIF Auto">
</p>

<p align="center">
  <strong>A powerful, secure, and blazing-fast image processing engine for PHP.</strong><br>
  On-the-fly resize, crop, and WebP/AVIF conversion with transparent caching.
</p>

---

## 🌟 Why iar?

| | Advantage |
|:---:|:---|
| ⚡ | **Blazing Fast** — Transparent caching + ETag support + optional 302/301 redirect to static cache files. Repeat requests are served in milliseconds, like static files. |
| 🛡️ | **Secure by Default** — Hardened against Path Traversal and DoS. Whitelisted extensions and strict path resolution. |
| 📦 | **Zero System Dependencies** — Only PHP + GD required. No `imagemagick` or global `composer` needed on the server. |
| 🚀 | **Modern Formats** — Automatically serves WebP or AVIF if the browser supports it (`Accept` header). |
| 📁 | **Full Subdirectory Support** — Works with any folder nesting depth. |
| 🔧 | **Single Config File** — All settings in one `config.php`. No need to touch the core code. |

---

## 🧭 Concept

IAR is a **portable folder**, not a service:

- **Drop-in.** Put the folder into the root of any site and route image requests through it. Nothing but PHP + GD is required; all code and dependencies live inside the folder.
- **The cache is fully disposable.** You may delete `cache/` entirely at any time — variants are rebuilt on the first request to a URL. No warm-up, no mandatory quotas.
- **The log is debug-only.** It is **off by default** (`logging => false`); rotation is not needed. Enable it while diagnosing.
- **Stable URL API.** `w`, `h`, `m`, `g`, `ct/cr/cb/cl`, `fm` are unchanged.

---

## 🚀 Quick Start

### Installation

`iar` requires **Intervention Image v3**. You MUST install dependencies via Composer **inside** the project folder.

**Method A — via Git (recommended):**

```bash
git clone https://github.com/sergey0002/iar.git
cd iar
composer install --no-dev --optimize-autoloader
```

**Method B — manual:**

1. Download and copy the `iar` folder into your website root.
2. Open a terminal and navigate **into** the `iar` folder:

   ```bash
   cd /path/to/your/site/iar
   ```

3. Install dependencies.

   *If global `composer` is installed:*

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

   *If `composer` is not installed globally — download it locally:*

   ```bash
   php -r "copy('https://getcomposer.org/installer', 'cs.php');"
   php cs.php --filename=composer.phar --quiet
   php -r "unlink('cs.php');"
   php composer.phar install --no-dev --optimize-autoloader
   ```

> [!NOTE]
> The `cache/` folder is created **automatically** on the first request. Ensure the PHP process has write permissions in the `iar/` directory.

> [!TIP]
> You can **rename** the `iar/` folder to anything you like — everything will continue to work. The `.htaccess` rules are relative and portable.

---

## 📖 Usage Examples

> **The principle:** access your image by its original path via the `iar/` folder. The system finds the file, processes it, and caches the result transparently.

### Basic Resize

`/products/hero.jpg` — the real path to the image at the site root.

```html
<!-- Resize to 800px wide, height proportionally -->
<img src="/iar/products/hero.jpg?w=800">

<!-- By height only -->
<img src="/iar/products/hero.jpg?h=600">
```

### Responsive Images (`srcset`)

```html
<img
  src="/iar/products/hero.jpg?w=1200"
  srcset="
    /iar/products/hero.jpg?w=480 480w,
    /iar/products/hero.jpg?w=800 800w,
    /iar/products/hero.jpg?w=1200 1200w
  "
  sizes="(max-width: 600px) 480px, (max-width: 1000px) 800px, 1200px"
  alt="SEO-optimized photo"
  loading="lazy">
```

### Images in Subdirectories

```html
<!-- /catalog/phones/2026/iphone15.jpg — original path -->
<img src="/iar/catalog/phones/2026/iphone15.jpg?w=400&h=400&m=cover">

<!-- Deep nesting -->
<img src="/iar/assets/img/ui/icons/logo.png?w=100">
```

### Square Avatar with Smart Crop

```html
<!-- Center crop (default), 200×200 -->
<img src="/iar/users/avatar.jpg?w=200&h=200&m=cover">

<!-- Focus on the upper part (portrait) -->
<img src="/iar/users/profile.jpg?w=300&h=300&m=cover&g=top">
```

### Article Cover with Edge Cropping

```html
<!-- Crop 15% from the bottom (remove watermark), then resize -->
<img src="/iar/blog/cover.jpg?w=1200&h=400&cb=15p&m=cover&g=top">

<!-- Crop 50px from the top, then resize -->
<img src="/iar/photo.jpg?w=640&h=480&ct=50">
```

### Force Format Conversion

```html
<!-- Always serve WebP, regardless of browser -->
<img src="/iar/logo.png?w=200&fm=webp">

<!-- Convert to AVIF -->
<img src="/iar/photo.jpg?w=800&fm=avif">
```

---

## 🔗 URL API — Full Parameter Reference

### Core Parameters

| Parameter | Type | Default | Description |
| :--- | :---: | :---: | :--- |
| *(path in URL)* | string | — | Path to the original, passed as a URL suffix: `/iar/path/to/file.jpg` |
| `w` | int | `0` | Width in px. `0` = no limit |
| `h` | int | `0` | Height in px. `0` = no limit |

### Resize Mode (`m`)

| Value | Behavior |
| :--- | :--- |
| `fit` *(default)* | Proportionally fits into `w×h`. No cropping. Does not upscale small images. |
| `cover` | Fills the entire `w×h` area without distortion. Edges are cropped according to `g`. |
| `resize` | Strictly stretches to `w×h`, ignoring proportions. May distort. |

### Gravity (`g`) — only for `m=cover`

| Value | Anchor Point |
| :--- | :--- |
| `center` *(default)* | Center |
| `top` | Top center |
| `bottom` | Bottom center |
| `left` | Left center |
| `right` | Right center |
| `top-left` | Top-left corner |
| `top-right` | Top-right corner |
| `bottom-left` | Bottom-left corner |
| `bottom-right` | Bottom-right corner |

### Edge Cropping (`ct`, `cr`, `cb`, `cl`)

Crops a given number of pixels or percentage from an edge **before** resizing.

| Parameter | Edge | In pixels | In percent |
| :---: | :--- | :---: | :---: |
| `ct` | Top | `?ct=50` | `?ct=15p` |
| `cr` | Right | `?cr=10` | `?cr=10p` |
| `cb` | Bottom | `?cb=30` | `?cb=20p` |
| `cl` | Left | `?cl=20` | `?cl=5p` |

> [!NOTE]
> The `p` suffix means percentage. Only **one** parameter is active at a time: priority `ct → cr → cb → cl`.

### Output Format (`fm`)

| Value | Description |
| :--- | :--- |
| `webp` | Force conversion to WebP |
| `avif` | Force conversion to AVIF |
| `jpg` / `jpeg` | Save as JPEG |
| `png` | Save as PNG |
| `gif` | Save as GIF |
| `svg` | Only when the source is SVG (raster source → `400`) |

> If `fm` is not passed and `auto_format` is enabled in the config, the script automatically selects the best format based on the browser's `Accept` header.

---

## ⚙️ Configuration `config.php`

The single configuration file. No need to change any other code.

```php
return [
    // === Quality & Dimensions ===
    'max_width'       => 2000,   // Max width (DoS protection)
    'max_height'      => 2000,   // Max height
    'jpeg_quality'    => 82,     // JPEG: 1–100 (82 = industry standard)
    'webp_quality'    => 85,     // WebP: 1–100
    'avif_quality'    => 75,     // AVIF: 1–100

    // === DoS protection: INPUT image limits ===
    'max_input_megapixels' => 40,       // MP, checked before decoding
    'max_input_bytes'      => 20000000, // bytes (~20 MB)

    // === PNG Optimization ===
    'png_indexed'           => true,  // Smart indexed PNG for small images (saves 30–70%)
    'png_indexed_threshold' => 200,   // px width threshold; 0 = always indexed

    // === JPEG Optimization ===
    'jpeg_progressive' => true,       // Progressive JPEG (smooth top-to-bottom loading)

    // === WebP Optimization ===
    'webp_strip_metadata' => true,    // Strip EXIF/XMP metadata from WebP (saves 1–5 KB)

    // === Default Modes ===
    'default_mode'    => 'fit',       // fit | cover | resize
    'default_gravity' => 'center',    // center | top | bottom | left | right | ...

    // === Optimization & Caching ===
    'lossless'           => false,    // true = maximum quality mode (quality 100)
    'auto_format'        => true,     // Auto-select WebP/AVIF by Accept header
    'cache_invalidation' => 'mtime',  // 'mtime' (by source mtime) | 'off' (eternal cache)
    'cache_auto_clean'   => true,     // Delete old cache versions on source update
    'redirect_to_cache'  => true,     // true = 302/301 redirect to cached file (fastest)
    'static_mode'        => false,    // true = skip mtime check, serve cache blindly (301)
    'disable_cache'      => false,    // true = disable cache (always regenerate, for dev)
    'cache_key_config_hash' => true, // quality changes invalidate the cache

    // === Security & Paths ===
    'images_base_dir'    => __DIR__ . '/../',   // validated on load; a bad path is a hard error
    'allowed_extensions' => ['jpg', 'jpeg', 'gif', 'png', 'webp', 'avif', 'svg'],
    'cache_dir'          => __DIR__ . '/cache',

    // === Logging ===
    'logging' => false,   // debug log; OFF by default
    'log_dir' => __DIR__ . '/log',
];
```

### All Configuration Parameters

| Key | Value | Description |
| :--- | :---: | :--- |
| `max_width` / `max_height` | int, px | Upper size limit. Values exceeding the limit are clamped. **DoS protection.** |
| `jpeg_quality` | 1–100 | JPEG quality. `82` — visually lossless with optimal file size. |
| `webp_quality` | 1–100 | WebP lossy quality. `85` — recommended. |
| `avif_quality` | 1–100 | AVIF quality. `75` — excellent balance of quality and size. |
| `png_indexed` | bool | Smart indexed color (256-color palette) for PNG. Saves 30–70% on icons/flat-art. |
| `png_indexed_threshold` | px | Width limit for smart indexed mode; images wider than this stay true-color. `0` = always indexed. |
| `jpeg_progressive` | bool | Progressive JPEG encoding — image loads smoothly top-to-bottom in browsers. |
| `webp_strip_metadata` | bool | Strip EXIF/XMP metadata from WebP output. Saves 1–5 KB per file. |
| `default_mode` | `fit` / `cover` / `resize` | Default resize mode when `m` is not provided in the URL. |
| `default_gravity` | `center` / ... | Default crop anchor for `cover` mode when `g` is not provided. |
| `lossless` | bool | `true` = maximum quality for all formats (JPEG/WebP/AVIF quality=100, PNG no compression). |
| `auto_format` | bool | Automatically serve WebP / AVIF if the browser supports it via `Accept`. |
| `cache_invalidation` | `mtime` / `off` | `mtime` — regenerate cache when source file changes. `off` — eternal cache. |
| `cache_auto_clean` | bool | Delete old cache versions when the source file is updated. |
| `redirect_to_cache` | bool | `true` = PHP issues a 302/301 redirect to the cached static file. `false` = stream via PHP. |
| `static_mode` | bool | `true` = skip `filemtime` checks entirely (maximum I/O savings). Uses 301 redirect. Best for assets that never change. |
| `disable_cache` | bool | `true` = disable all caching (always regenerate). Sends `no-cache` headers. **For development only.** |
| `images_base_dir` | path | Root directory for originals. Requests outside this directory return 403. |
| `allowed_extensions` | array | Whitelist of allowed extensions. Everything else returns 403. |
| `cache_dir` | path | Folder for storing cached thumbnails. Created automatically. |
| `max_input_megapixels` | int, MP | Limit of the **source** image, checked before decoding. Exceeding it → `413`. |
| `max_input_bytes` | int, bytes | Limit of the source file size. Exceeding it → `413`. |
| `cache_key_config_hash` | bool | Include a fingerprint of quality settings in the cache key. |
| `logging` | bool | Debug request log. **`false` by default.** |
| `log_dir` | path | Directory for log files (one file per day). |
| `log_level` | `error` / `info` / `debug` | `error` (default) — failures with a reason only; `info` — plus hit/miss; `debug` — plus request parameters. At `error` no metrics are collected, so the hot path does no extra disk calls. |

### Cache Mode Comparison

| Mode | Config | Behavior | Best For |
| :--- | :--- | :--- | :--- |
| **Normal** | defaults | mtime check + 302 redirect | Changing assets |
| **Static** | `static_mode: true` | No mtime check + 301 redirect | Immutable assets |
| **Dev** | `disable_cache: true` | Always regenerate, no redirect | Development |

---

## ✨ Feature Matrix

| Feature | Description |
| :--- | :--- |
| **Resize** | Proportional resize by width (`w`) or height (`h`). Does not upscale small images. |
| **Smart Crop** | `cover` mode with configurable focal point (`g`). |
| **Edge Crop** | Crop px or % from any edge before processing (`ct`, `cr`, `cb`, `cl`). |
| **Auto WebP/AVIF** | Transparent conversion to modern formats without changing the URL. |
| **Transparent Caching** | First request = processing. All subsequent = instant from cache. |
| **Cache Redirect** | PHP redirects to the static cached file for Apache to serve directly. |
| **Static Mode** | Zero I/O overhead mode for immutable assets. |
| **Dev Mode** | `disable_cache` for always-fresh results during development. |
| **Cache Invalidation** | Automatic when the source file changes (by `mtime`). |
| **ETag / 304** | Full browser caching protocol support. |
| **Progressive JPEG** | Smooth top-to-bottom loading experience. |
| **Smart PNG Indexed** | Automatic palette mode for small PNG (30–70% savings). |
| **WebP Metadata Strip** | Remove EXIF/XMP from output WebP files. |
| **SVG Support** | SVG is passed through as-is (or rasterized via Imagick). |
| **Path Traversal Protection** | Strict path verification — impossible to escape `images_base_dir`. |
| **DoS Protection** | Output limits (`max_width`/`max_height`) **and input limits** (`max_input_megapixels`/`max_input_bytes`, checked before decoding → `413`). |
| **Lossless Mode** | Global maximum quality with a single config flag. |
| **Debug log** | JSON lines: cache status, size savings, failure reason. Off by default, sensitive keys redacted. |
| **Atomic cache** | Temp file + `rename`, `flock` against stampede. |
| **Safe SVG** | `Content-Security-Policy` and `nosniff` on the PHP path too, not only in Apache. |
| **HEAD and 405** | Proper bodyless `HEAD`, `405` + `Allow` for other methods. |
| **100% test coverage** | Unit plus integration HTTP scenarios, coverage gate in CI. |

---

## 🏗 Architecture and request flow

```
index.php            thin entry point (dependencies + bootstrap)
└── App\Bootstrap    display_errors=0, fatal handler, wiring
    ├── App\Config           typed, validated config (fail-fast)
    ├── App\Http\Request    parameter parsing and validation (400/405)
    ├── App\Application      orchestration: path → key → cache → response
    │   ├── App\Domain\FormatNegotiator  Accept-based format choice
    │   ├── App\Domain\ResizeService     Intervention Image (GD)
    │   │   └── App\Domain\SvgRasterizer → ImagickRasterizer
    │   ├── App\Infrastructure\CacheKey   variant key
    │   ├── App\Infrastructure\CacheStore atomic write + flock
    │   └── App\Infrastructure\DebugLogger|NullLogger
    └── App\Http\Response → SapiInterface (NativeSapi in prod, TestSapi in tests)
```

Flow: `resolveSource` (realpath + whitelist) → params → target format → `mtime`
→ cache key → file exists? serve : lock → generate → atomic publish → serve
(or redirect to the static file).

The cache key includes the **full relative path** of the source, dimensions,
mode, gravity, crop, `mtime` and a fingerprint of quality settings — collisions
between `/photo.jpg` and `/www/photo.jpg` are no longer possible.

---

## 🧪 Testing

Tests need neither PHPUnit nor Xdebug — that is part of the portability concept.

```bash
# Unit tests without composer and PHPUnit
php tests/run.php

# Integration HTTP scenarios (built-in PHP server, real headers)
php tools/integration.php run

# Line coverage without Xdebug/PCOV + 100% gate
php tools/coverage-portable.php build
php tests/coverage-run.php
php tools/integration.php run
php tools/coverage-portable.php report 100

# Compatibility with PHP 7.0 … 8.4 (no composer, no PHPUnit)
php tests/legacy-compat.php

# Measure disk operations on a cache hit
php tools/io-probe.php build
php tools/integration.php setup 0 /tmp/iar-probe
php tools/integration.php payload 0 0 /tmp/iar-probe "" probe > /tmp/p.json
php tests/Integration/step.php /tmp/p.json   # MISS
php tools/io-probe.php reset
php tests/Integration/step.php /tmp/p.json   # HIT
php tools/io-probe.php report
```

With Composer the usual commands are available:

```bash
composer test              # PHPUnit
composer test-all          # portable runner + integration
composer coverage          # clover report
composer coverage-gate     # 100% threshold on clover
composer coverage-portable # independent coverage check
composer stan              # PHPStan
composer cs                # PHP-CS-Fixer (dry-run)
composer mutation          # Infection, MSI >= 90
```

Covered: parameter validation, crop boundaries, cache-key collisions, format
whitelists, GIF animation, headers/304/redirects, invalidation, **full cache
wipe → regeneration**, DoS limits, SVG CSP, cache write failures, worker races,
fatal errors. Report: [`docs/implementation-report.md`](docs/implementation-report.md).

---

## 🚢 Deployment

### Apache

`.htaccess` is already configured: `-Indexes`, denial of service files and
directories (`config.php`, `log/`, `vendor/`, `src/`, `tests/`, dotfiles,
`.git/`), cache passthrough, `[B]` in the rewrite rule (names with `&`),
`nosniff` and a CSP for SVG. Requires `mod_rewrite` and `AllowOverride All`.

### nginx

```nginx
location /iar/ {
    try_files $uri @iar;
}

location @iar {
    rewrite ^/iar/(.*)$ /iar/index.php?name=$1 last;
}

location = /iar/index.php {
    fastcgi_pass unix:/run/php/php-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
}

location ~ ^/iar/(config\.php|composer\.|src/|tests/|tools/|log/|vendor/|\.) {
    deny all;
}
```

### Clearing the cache — selectively, without wiping the folder

```bash
php tools/cache.php stats                      # files, weight, number of originals
php tools/cache.php clean --source=photo.jpg   # every variant of one original
php tools/cache.php clean --orphans            # variants of deleted originals
php tools/cache.php clean --older-than=30d     # variants not created recently
php tools/cache.php clean --max-size=500M      # oldest until the weight fits
php tools/cache.php wipe                       # everything
```

Every command accepts `--dry-run`. The tool runs on PHP 7.0+ with no
dependencies and never reads file contents: the variant name carries a hash
of the original's relative path, so the search is targeted.

A full flush is fine too:

```bash
rm -rf /path/to/site/iar/cache/*
```

No warm-up is needed: the next request to a URL rebuilds the variant.

### Troubleshooting

| Symptom | Cause | Fix |
| :--- | :--- | :--- |
| `X-Iar-Engine: legacy` | PHP < 8.1 or no `vendor/` | expected: the dependency-free engine is serving; for the full one use PHP 8.1+ and `composer install --no-dev -o` |
| `500 IAR installation is incomplete` | neither `vendor/` nor `legacy.php` present | unpack the whole IAR folder |
| `500 Configuration error.` | bad `images_base_dir` or value type | check `config.php`; the error is intentionally explicit |
| `302` to the original instead of a thumbnail (`X-Iar-Fallback: original`) | GD could not process the file, or conversion would drop animation frames | set `logging => true` and read `reason` in `log/` |
| `X-Iar-Cache: miss` on every request | `disable_cache => true` or the original keeps changing | check the config and the original's mtime |
| `413` | source exceeds `max_input_megapixels` / `max_input_bytes` | shrink the source or raise the limit |
| Photo rotated incorrectly | `ext-exif` missing | install `php-exif` |
| SVG not rasterized | `ext-imagick` missing | install `php-imagick` or serve SVG as is |
| No AVIF output | GD built without AVIF | update GD; WebP is selected automatically |

---

## ⚠️ Changes since 1.x

| Item | Before | Now |
| :--- | :--- | :--- |
| `logging` | `true` | **`false`** (debug-only by design) |
| `png_compression` | documented | **removed** — it never worked |
| `fm=svg` for raster | JPEG named `.svg` | `400` |
| GIF + `Accept: image/webp` | animation lost | GIF preserved |
| `ct=-50`, `ct=150p` | bars / black image | `400` or a correct clamp |
| `Cache-Control` | `immutable` for a year, always | `no-cache`; `immutable` only in `static_mode` |
| `304` | no headers | with `ETag`, `Cache-Control`, `Vary` |
| POST/PUT | handled as GET | `405` + `Allow` |
| Array in `w`/`h` | `500` | `400` |
| 140 MP source | fatal OOM | `413` before decoding |
| Request without parameters | re-encoded | byte copy of the original |

The URL API is unchanged: existing links keep working.

---

## 📋 System Requirements

| Component | Minimum | Recommended |
| :--- | :---: | :---: |
| PHP | **≥ 7.0** (`legacy.php` engine) | 8.1+ (full `src/` engine) |
| PHP GD extension | ✅ Required | + WebP + AVIF support |
| Apache `mod_rewrite` | — | ✅ For clean URLs |
| `php-exif` | — | ✅ Correct JPEG orientation |
| `php-imagick` | — | ✅ For SVG → raster |
| Composer | **not required** | ✅ For the full engine (Intervention Image) |

### Two engines — one URL API, one cache

| | `legacy.php` | `src/` (Intervention) |
| :--- | :--- | :--- |
| Selected when | PHP 7.0–8.0 **or** no `vendor/` | PHP ≥ 8.1 with Composer installed |
| Dependencies | none (GD only) | `intervention/image` |
| Cache | **same directory, same file names** | **same directory, same file names** |
| URL API, headers, ETag, 304 | identical | identical |
| Animated GIF | original is served | resized with frames preserved; format change → original |
| Animated WebP | original is served | original is served |
| SVG → raster | requires `ext-imagick` | requires `ext-imagick` |
| AVIF | if GD supports it | if GD supports it |

Selection is automatic and invisible to the browser: cache file names match
byte for byte (verified by `LegacyTest`), so upgrading from PHP 7 to PHP 8
needs neither a cache flush nor new links. The engine that answered is
reported in the `X-Iar-Engine` header.

---

## 🛠 Documentation

- [**README на русском (По-русски)**](README_RU.md)
- [Configuration Reference](config.php)
- [Project audit](docs/audit.md)
- [Remediation plan](docs/remediation-plan.md)
- [Implementation & test report](docs/implementation-report.md)
- [Product policy audit](docs/policies-audit.md)

---

## 💳 License

Released under the [MIT License](LICENSE).
