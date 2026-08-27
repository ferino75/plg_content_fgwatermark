<p align="center">
  <img src="assets/logo.png" alt="fgwatermark logo" width="128" height="128">
</p>

<h1 align="center">FG Watermark</h1>

<p align="center">
  <img src="https://img.shields.io/github/v/release/ferino75/plg_content_fgwatermark?color=FF6B4A&label=release" alt="Latest release">
  <img src="https://img.shields.io/badge/Joomla-3.10%20--%206.x-005E93.svg?logo=joomla&logoColor=white" alt="Joomla">
  <img src="https://img.shields.io/badge/PHP-5.6%2B-777BB4.svg?logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/license-GPL--2.0-green.svg" alt="License">
  <img src="https://img.shields.io/github/downloads/ferino75/plg_content_fgwatermark/total?cacheSeconds=3600" alt="Downloads">
</p>

Automatically watermarks images inside Joomla article content — an image
logo, a text overlay, or both — with cached output so nothing is re-rendered
on every page load. Works unmodified from **Joomla 3.10 through Joomla 6**
via a small dual-compatibility bootstrap (classic `JPlugin` on 3.x,
`SubscriberInterface` on 4/5/6).

## Features

- **Image (logo) watermark** — PNG, JPG, GIF, or **SVG** (rasterized via
  Imagick + rsvg, with graceful fail-safe fallback if Imagick isn't
  available on the server)
- **Text watermark** — with optional TTF font for full diacritics support
- Both can be enabled together, positioned independently
- 9-position placement, configurable margin and opacity for each
- Automatic upscale cap for low-resolution logos, with a log warning instead
  of a silently pixelated result
- Cached, atomically-written output (`rename()`-based publish, safe under
  concurrent requests) — the source article images are **never modified**
- Scope-limited to configured folder(s) (e.g. `images/`), with a minimum
  size threshold and a `no-watermark` CSS class exclusion
- Media-manager picker for the logo path (handles Joomla's
  `#joomlaImage://...` metadata suffix automatically)
- Clean uninstall: optionally removes the generated cache folder
- sk-SK and en-GB translations included

## Requirements

- Joomla 3.10, or Joomla 4/5/6
- PHP with the **GD** extension (required)
- PHP with the **Imagick** extension + SVG/rsvg delegate (optional, only
  needed if you use an SVG logo)

## Installation

Download the latest release ZIP from the
[Releases](https://github.com/ferino75/plg_content_fgwatermark/releases)
page and install it via Joomla's Extension Manager (Upload & Install), then
enable **Content - FG Watermark** in the Plugin Manager and configure it.

## Configuration

All settings are on the plugin's own configuration screen (three tabs:
general, image watermark, text watermark). See the field descriptions in
the admin UI for details on each option.

## Updates

This plugin ships with a Joomla update server, so once installed you'll get
update notifications straight from Joomla's Extension Manager.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Changelog

See [CHANGELOG.md](CHANGELOG.md).
