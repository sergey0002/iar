# IAR — Image Auto Resizer

<p align="center">
  <a href="https://opensource.org/licenses/MIT"><img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License: MIT"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/php-%3E%3D7.4-8892bf.svg" alt="PHP Version"></a>
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
| ⚡ | **Blazing Fast** — Transparent caching + ETag support. Repeat requests are served in milliseconds, like static files. |
| 🛡️ | **Secure by Default** — Hardened against Path Traversal and DoS. Whitelisted extensions and strict path resolution. |
| 📦 | **Zero System Dependencies** — Only PHP + GD required. No `imagemagick` or global `composer` needed on the server. |
| 🚀 | **Modern Formats** — Automatically serves WebP or AVIF if the browser supports it (`Accept` header). |
| 📁 | **Full Subdirectory Support** — Works with any folder nesting depth. |
| 🔧 | **Single Config File** — All settings in one `config.php`. No need to touch the core code. |

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
> You can **rename** the `iar/` folder to anything you like — everything will continue to work. Just update `RewriteBase` in `.htaccess`.

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
| `jpg` | Save as JPEG |
| `png` | Save as PNG |

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
    'png_compression' => 9,      // PNG zlib: 0–9 (does not affect pixel quality)

    // === Default Modes ===
    'default_mode'    => 'fit',     // fit | cover | resize
    'default_gravity' => 'center',  // center | top | bottom | left | right | ...

    // === Optimization ===
    'lossless'           => false,   // true = maximum quality mode
    'auto_format'        => true,    // Auto-select WebP/AVIF by Accept header
    'cache_invalidation' => 'mtime', // 'mtime' (by date) | 'off' (eternal cache)
    'cache_auto_clean'   => true,    // Delete old cache versions on source update

    // === Security & Paths ===
    'images_base_dir'    => realpath(__DIR__ . '/../'),
    'allowed_extensions' => ['jpg', 'jpeg', 'gif', 'png', 'webp', 'avif', 'svg'],
    'cache_dir'          => __DIR__ . '/cache',
];
```

### All Configuration Parameters

| Key | Value | Description |
| :--- | :---: | :--- |
| `max_width` / `max_height` | int, px | Upper size limit. Values of `w`/`h` exceeding the limit are clamped. **DoS protection.** |
| `jpeg_quality` | 1–100 | JPEG quality. `82` — visually lossless with optimal file size. |
| `webp_quality` | 1–100 | WebP lossy quality. `85` — recommended. |
| `avif_quality` | 1–100 | AVIF quality. `75` — excellent balance of quality and size. |
| `png_compression` | 0–9 | Level of zlib compression for PNG. **Does not affect pixel quality** (lossless). `9` = smallest file size. |
| `default_mode` | `fit` / `cover` / `resize` | Default resize mode when `m` is not provided in the URL. |
| `default_gravity` | `center` / ... | Default crop anchor for `cover` mode when `g` is not provided. |
| `lossless` | bool | `true` = maximum quality for all formats (PNG lossless, JPEG/WebP/AVIF quality=100). |
| `auto_format` | bool | Automatically serve WebP / AVIF if the browser supports it via `Accept`. |
| `cache_invalidation` | `mtime` / `off` | `mtime` — regenerate cache when source file changes. `off` — eternal cache. |
| `cache_auto_clean` | bool | Delete old cache versions when the source file is updated. |
| `images_base_dir` | path | Root directory for originals. Requests outside this directory return 403. |
| `allowed_extensions` | array | Whitelist of allowed extensions. Everything else returns 403. |
| `cache_dir` | path | Folder for storing cached thumbnails. Created automatically. |

---

## ✨ Feature Matrix

| Feature | Description |
| :--- | :--- |
| **Resize** | Proportional resize by width (`w`) or height (`h`). Does not upscale small images. |
| **Smart Crop** | `cover` mode with configurable focal point (`g`). |
| **Edge Crop** | Crop px or % from any edge before processing (`ct`, `cr`, `cb`, `cl`). |
| **Auto WebP/AVIF** | Transparent conversion to modern formats without changing the URL. |
| **Transparent Caching** | First request = processing. All subsequent = instant from cache. |
| **Cache Invalidation** | Automatic when the source file changes (by `mtime`). |
| **ETag / 304** | Full browser caching protocol support. |
| **SVG Support** | SVG is passed through as-is (or rasterized via Imagick). |
| **Path Traversal Protection** | Strict path verification — impossible to escape `images_base_dir`. |
| **DoS Protection** | `max_width` / `max_height` limits prevent generating massive images. |
| **Lossless Mode** | Global maximum quality with a single config flag. |

---

## 📋 System Requirements

| Component | Minimum | Recommended |
| :--- | :---: | :---: |
| PHP | ≥ 7.4 | 8.2+ |
| PHP GD extension | ✅ Required | + WebP + AVIF support |
| Apache `mod_rewrite` | — | ✅ For clean URLs |
| `php-imagick` | — | ✅ For SVG → raster |

---

## 🛠 Documentation

- [**README на русском (По-русски)**](README_RU.md)
- [Configuration Reference](config.php)

---

## 💳 License

Released under the [MIT License](LICENSE).
