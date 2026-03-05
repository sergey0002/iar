# IAR — Image Auto Resizer

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D7.4-8892bf.svg)](https://php.net)

**iar** is a self-contained PHP-based image processing engine designed for on-the-fly resizing, cropping, and automatic format conversion (WebP/AVIF). It is built for developers who need a powerful, zero-dependency, and extremely fast solution for modern web applications.

## Usage Example

/products/hero.jpg - original path to the image.

<img src="/iar/products/hero.jpg?w=1200" /> If the image is wider than 1200px, it will be proportionally resized down to 1200px width.

Resizing is done once; the thumbnail is saved in the cache. Subsequent requests are served directly from the cache file. If the original file changes, the cache is automatically updated.

<img src="/iar/products/hero.jpg?w=1200"
     srcset="/iar/products/hero.jpg?w=480 480w,
             /iar/products/hero.jpg?w=800 800w"
     sizes="(max-width: 600px) 480px, 800px"
     alt="SEO Optimized Image">

## 🌟 Why iar?

- **⚡ Speed**: Transparent caching and ETag support. Repeat requests are served in milliseconds without re-processing.
- **🛡️ Security**: Hardened against Path Traversal and DoS. Whitelisted parameters and strict path resolution.
- **📦 Zero System Dependencies**: Only requires PHP + GD. Does not require globally installed `composer` or `imagemagick`.
- **🚀 Modern Standards**: Automatic WebP/AVIF selection based on browser support (`Accept` header).
- **📁 Subdirectory Support**: Works with any nesting depth of your images.

---

## 🚀 Quick Start

### 1. Installation

`iar` requires the **Intervention Image v3** library. You MUST install dependencies via Composer inside the project folder.

#### Method A: Via Git

```bash
git clone https://github.com/sergey0002/iar.git
cd iar
composer install --no-dev --optimize-autoloader
```

#### Method B: Manual (Alternative)

1. **Copy the folder** `iar` into your website root.
2. **Open terminal** and go **INSIDE** the `iar` folder:

   ```bash
   cd path/to/your/site/iar
   ```

3. **Install dependencies**:
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

You can rename the `/iar/` folder in the site root as you wish; everything will work correctly.

### 2. Basic Usage (SEO Links)

Access images directly via their path. The system will understand that a resize is needed.

- **Resize**: `/iar/photo.jpg?w=800`
- **Subdirectory Support**: `/iar/products/2026/iphone15.jpg?w=400` where `/products/2026/iphone15.jpg` is the original path to the image.
- **Any nesting**: `/iar/assets/img/ui/icons/logo.png?w=100`

### 3. All Capabilities

| Feature | Description | Example |
| :--- | :--- | :--- |
| **Resizing** | Proportional resize by width or height | `?w=800` or `?h=600` |
| **Smart Crop** | `fit`, `cover` (with gravity), `resize` modes | `?w=400&h=400&m=cover` |
| **Gravity** | Align crop: `top`, `bottom`, `left`, `right` | `?m=cover&g=top` |
| **Edge Crop** | Crop pixels/percent from edges (`ct`, `cr`, `cb`, `cl`) | `?ct=10p` (10% from top) |
| **Custom Format** | Force conversion to the required type | `?fm=webp` or `?fm=avif` |
| **Auto-Format** | Serves WebP/AVIF to modern browsers | *Enabled by default* |

### 4. Responsive Example (`srcset`)

```html
<img src="/iar/products/hero.jpg?w=1200" 
     srcset="/iar/products/hero.jpg?w=480 480w, 
             /iar/products/hero.jpg?w=800 800w" 
     sizes="(max-width: 600px) 480px, 800px"
     alt="SEO Optimized Image">
```

---

## ✨ Key Capabilities

- **Maximum Portability**: Works on any hosting with PHP + GD. No ImageMagick required on the system.
- **Auto-Format**: Automatically serves WebP or AVIF if the browser supports it (via `Accept` header), while the path remains the same.
- **Smart Caching**: Unique keys based on the path hash and original modification time.
- **Security**: Protection against Path Traversal, DoS attacks (size limits), and XSS in SVG files.
- **Flexibility**: Edge cropping (in px or %), fill modes (`cover`) with focal point selection, and lossless compression support.

---

## 🛠 Documentation

- [**README in Russian (По-русски)**](README_RU.md)
- [Configuration Reference](config.php)

---

## ⚙️ System Requirements

- PHP 7.4 or higher (8.1+ recommended for AVIF support)
- PHP GD extension
- Apache with `mod_rewrite` enabled (for clean links)
- `php-imagick` (optional, only for SVG to raster conversion)

---

## 💳 License

Distributed under the [MIT License](LICENSE).
