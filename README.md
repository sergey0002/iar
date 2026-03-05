# iar — Portable Image Resizer

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-8892bf.svg)](https://php.net)

**iar** is a self-contained PHP-based image processing engine designed for on-the-fly resizing, cropping, and automatic format conversion (WebP/AVIF). It is built for developers who need a powerful, zero-dependency, and extremely fast solution for modern web applications.

## 🌟 Why iar?

- **⚡ Blazing Fast**: Transparent caching and ETag support. Repeat requests are served in milliseconds without re-processing.
- **🛡️ Secure by Design**: Hardened against Path Traversal and DoS. Whitelisted parameters and strict path resolution.
- **📦 No System-wide Dependencies**: Only requires PHP + GD. You don't need a global `composer` or `imagemagick` installed on your OS.
- **🚀 Modern Features**: Automatic WebP/AVIF content negotiation based on browser support (`Accept` header).
- **📁 Subdirectory Support**: Works seamlessly with images organized in deep folder structures.

---

## 🚀 Quick Start

### 1. Installation

`iar` requires the **Intervention Image v3** library. You MUST install dependencies via Composer inside the project folder.

#### Method A: Git Clone

```bash
git clone https://github.com/sergey0002/iar.git
cd iar
composer install --no-dev --optimize-autoloader
```

#### Method B: Manual (Alternative)

1. **Copy** the `iar` folder into your website root.
2. **Open Terminal** (Command Prompt) and navigate **INTO** the `iar` directory:

   ```bash
   cd path/to/your/site/iar
   ```

3. **Install Dependencies**:
   *If you have global `composer`:*

   ```bash
   composer install --no-dev --optimize-autoloader
   ```

   *If you **DON'T** have global composer:*

   ```bash
   # Download local composer.phar and install
   php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
   php composer-setup.php
   php -r "unlink('composer-setup.php');"
   php composer.phar install --no-dev --optimize-autoloader
   ```

> [!NOTE]
> The `cache/` directory will be created automatically on the first request. Ensure the script has permissions to write in its own directory.

### 2. Basic Usage (Clean SEO URLs)

Access images directly via their path. The system handles resizing and auto-formatting automatically.

- **Auto-Resize**: `/iar/photo.jpg?w=800`
- **Subdirectory Support**: `/iar/products/2026/iphone15.jpg?w=400`
- **Deep Nesting**: `/iar/assets/img/ui/icons/logo.png?w=100`

### 3. All Capabilities

| Feature | Description | Example |
| :--- | :--- | :--- |
| **Resizing** | Proportional resize by width or height | `?w=800` or `?h=600` |
| **Smart Crop** | `fit`, `cover` (with gravity), `resize` (distort) | `?w=400&h=400&m=cover` |
| **Gravity** | Align crop: `top`, `bottom`, `left`, `right`, etc. | `?m=cover&g=top` |
| **Edge Crop** | Crop pixels/percent from edges (`ct`, `cr`, `cb`, `cl`) | `?ct=10p` (crop 10% from top) |
| **Format Conversion** | Explicitly request a specific format | `?fm=webp` or `?fm=avif` |
| **Auto-Format** | Serves WebP/AVIF to supported browsers | *Enabled by default* |

### 4. Responsive Example (`srcset`)

```html
<img src="/iar/products/hero.jpg?w=1200" 
     srcset="/iar/products/hero.jpg?w=480 480w, 
             /iar/products/hero.jpg?w=800 800w" 
     sizes="(max-width: 600px) 480px, 800px"
     alt="SEO Optimized Image">
```

---

## ✨ Features

- **Extreme Portability**: Works on any hosting (Apache/Nginx) with PHP + GD. No ImageMagick binary required.
- **Auto-Format**: Automatically serves WebP or AVIF if the browser supports it (via `Accept` header), keeping the same URL.
- **Smart Caching**: Efficient unique cache keys based on path hash and modification time.
- **Security-First**: Built-in protection against Path Traversal, DoS (dimension limits), and SVG XSS.
- **Flexible UI features**: Edge cropping (pixels or %), gravity-based cover modes, and lossless compression options.

---

## 🛠 Documentation

- [**README in Russian (По-русски)**](README_RU.md)
- [Full User & Developer Guide](.doc/guide.md)
- [Configuration Reference](config.php)

---

## ⚙️ Requirements

- PHP 7.4 or higher (8.1+ recommended for AVIF)
- PHP GD Extension
- Apache with `mod_rewrite` (optional for clean URLs)
- `php-imagick` (optional, for SVG to raster conversion)

---

## 💳 License

Released under the [MIT License](LICENSE).
