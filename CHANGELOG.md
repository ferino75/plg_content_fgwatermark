# Changelog - plg_content_fgwatermark

## 2.1.1
- Fix: the new "Also rewrite links to images" field description (added in
  2.1.0) contained literal `<a href="...">` / `<img src>` markup inside the
  language string. Joomla renders field descriptions as raw HTML, so the
  unclosed/malformed tags corrupted the admin form's HTML and broke the
  plugin settings screen's tab layout - same class of bug as the `<script>`-
  in-a-description issue previously hit on fgadminlogincustom. Reworded to
  plain prose ("the img tag's src attribute" / "the href attribute in
  links") with no angle brackets, in both en-GB and sk-SK.

## 2.1.0
- Added lightbox compatibility: the plugin now also rewrites `<a href="...">`
  links that point directly at one of your own in-scope images (not just
  `<img src>`). Lightbox plugins - including FG AutoLightbox - commonly wrap
  a thumbnail `<img>` in a link to the full-size original for the lightbox
  to open; without this, the lightbox's full-size view showed the
  un-watermarked original regardless of plugin execution order. New toggle
  "Also rewrite links to images" (default: on) in the general settings.
  The `no-watermark` CSS class exclusion works independently on `<a>` and
  `<img>` tags, same as before.

## 2.0.0 - Rebranded into the FG series
- Renamed element/folder `watermark` → `fgwatermark` (plugin class
  `PlgContentWatermark` → `PlgContentFgwatermark`, language files renamed to
  the required `plg_content_fgwatermark.*` pattern, default cache folder
  `images/watermark_cache` → `images/fgwatermark_cache`, log category
  renamed to match).
- No functional/behavioral changes - architecture (single dual-compatibility
  bootstrap: classic `JPlugin` on Joomla 3.x, `SubscriberInterface` on
  4/5/6) is unchanged from v1.6.3.
- Added Joomla update server (`<updateservers>` in the manifest + a
  repo-level `updates.xml`, `<client>site</client>` declared, single
  `<targetplatform>` regex covering 3.x-6.x since it's one build).
- Published as a full repo: README.md (with shields.io badges), LICENSE
  (GPL-2.0), .gitignore, assets/logo.png (FG navy/coral squircle style).
- Display name changed to "Content - FG Watermark" in the Plugin Manager.

## 1.6.3
- Cache file writes are now atomic: the watermarked image is rendered into a
  temp file in the same folder, then published with `rename()`. Previously,
  writing directly to the final cache path meant a request could race the
  render and be served a half-written (corrupt) image - not just duplicate
  work, but a genuinely broken file, since the browser fetches the cache URL
  as a plain static file served directly by the webserver, entirely outside
  PHP (so no in-PHP lock could have protected that read anyway). `rename()`
  on the same filesystem is atomic on Linux, so readers now always see either
  the previous state or the fully-written new file.

## 1.6.2
- Host comparison for full https:// image URLs (deciding whether an image is
  "our own" vs externally hosted) now goes through Joomla's Uri object
  instead of reading `$_SERVER['HTTP_HOST']` directly. Joomla's Uri already
  resolves the correct host behind reverse proxies / load balancers
  (X-Forwarded-Host, "behind_loadbalancer" config); a raw `$_SERVER` read
  does not account for that and could mis-detect the site's own images as
  external on such setups.

## 1.6.1
- The cache filename hash now includes a `WatermarkEngine::VERSION` constant
  (bumped on every release). Previously, updating the plugin's *code* without
  also changing a user-facing parameter (e.g. the 1.6.0 SVG rendering fix)
  left old cached images in place, since the hash was only ever derived from
  the settings values themselves. Now every release automatically invalidates
  old cache on its own - no more manually clearing `images/watermark_cache/`
  or nudging a slider by 1 after an update just to force a rebuild.

## 1.6.0
- Added SVG support for the image (logo) watermark. GD itself cannot read
  SVG, so this rasterizes it via the Imagick PHP extension (needs the
  SVG/rsvg delegate, i.e. `librsvg2-bin` on the server) at a resolution well
  above the final placement size for crisp downscaling. SVG width/height/
  viewBox is parsed directly from the file to get the correct aspect ratio.
  If Imagick isn't available, the logo is silently skipped (fail-safe) and a
  clear warning is written to the Joomla log explaining why, with a
  suggestion to install php-imagick or convert the logo to PNG.

## 1.5.1
- Fix: image paths from `<img src>` were not URL-decoded before being used as
  filesystem paths, so files with spaces or diacritics in their name (e.g.
  `recyklacia firma banner.png` → `recyklacia%20firma%20banner.png`) were
  silently never watermarked (fail-safe fallback left the tag unchanged).

## 1.5.0
- Added `script.php` installer script (`PlgContentWatermarkInstallerScript`)
  that removes the watermark cache folder on plugin **uninstall** (not on
  disable). Reads the actually configured `cache_folder` from `#__extensions`
  params, with a safety guard requiring "watermark_cache" in the path before
  deleting anything.

## 1.4.0
- Fix: image watermark compositing used `imagecopymerge()`, which ignores the
  source PNG's alpha channel entirely, causing a dark/black halo behind
  semi-transparent logos. Replaced with per-pixel alpha scaling
  (`scaleAlphaChannel()`) + native `imagecopy()` alpha blending, which
  correctly respects the logo's own transparency.

## 1.3.0
- Added `image_max_upscale` parameter (default `3`): caps how far a logo can
  be scaled up relative to its native resolution, to avoid silently producing
  a pixelated watermark when `image_scale` (%) would otherwise blow up a
  low-resolution logo file. Logs a warning (Joomla log, category
  `plg_content_watermark`) whenever the cap kicks in.

## 1.2.1
- Fix: `image_path` value from the media field includes Joomla's
  `#joomlaImage://...` metadata suffix (added by the media picker). This was
  never stripped before building the filesystem path, so the logo was never
  found. Now stripped in `applyImageWatermark()`.

## 1.2.0
- Changed `image_path` field type from plain `text` to `media`, giving a
  Media Manager browse button + preview instead of requiring a hand-typed path.

## 1.1.1
- Fix: `image_margin` / `image_opacity` / `text_margin` / `text_opacity`
  fields referenced generic language keys
  (`PLG_CONTENT_FGWATERMARK_MARGIN_LABEL` / `_OPACITY_LABEL`) in the XML, but
  the `.ini` files only defined `IMAGE_`/`TEXT_`-prefixed variants - so the
  raw key was shown in the UI instead of a translated label. Fixed and
  reworded to neutral "Margin (px)" / "Opacity (%)" since the label is shared
  across both the image and text fieldsets.

## 1.1.0
- Restructured into a dual-compatibility architecture so the same package
  works from Joomla 3.10 through Joomla 6:
  - `watermark.php` - bootstrap, branches on `interface_exists('Joomla\Event\SubscriberInterface')`.
  - `src/legacy.php` - classic `extends JPlugin` wrapper (Joomla 3.x).
  - `src/modern.php` - `implements SubscriberInterface` wrapper (Joomla 4/5/6;
    required from 6.0 onward since `CMSPlugin`'s legacy on*-method
    auto-registration is removed in 6.0).
  - `src/engine.php` - shared GD watermarking logic, Joomla-version agnostic.

## 1.0.0
- Initial release: automatic image (logo) and/or text watermarking of images
  inside article content (`onContentPrepare`), with cached output, configurable
  scope folder, minimum size threshold, 9-position placement, opacity, and a
  `no-watermark` CSS class exclusion.
