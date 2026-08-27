<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.Watermark
 *
 * Shared GD-based watermarking engine. Deliberately Joomla-version agnostic:
 * only touches JPATH_ROOT (a core constant defined identically since Joomla 1.5)
 * and plain PHP file functions, so the exact same class works whether it's
 * driven by the legacy JPlugin wrapper (Joomla 3) or the modern
 * SubscriberInterface wrapper (Joomla 4/5/6).
 */

defined('_JEXEC') or die;

class WatermarkEngine
{
	/**
	 * Bumped alongside the plugin version on every release. Included in the
	 * cache hash so updating the plugin automatically invalidates old cached
	 * output, even when no user-facing parameter actually changed (e.g. a bug
	 * fix in the rendering code itself, like SVG support in 1.6.0).
	 */
	const VERSION = '2.1.1';

	/** @var object  Joomla Registry (or JRegistry) instance - both expose ->get() identically */
	protected $params;

	public function __construct($params)
	{
		$this->params = $params;
	}

	/**
	 * Find <img> tags (and, if enabled, <a href> links pointing directly at an
	 * image - e.g. a lightbox's "full size" link) in HTML and rewrite them to
	 * the watermarked/cached version.
	 */
	public function processHtml($html)
	{
		if (empty($html)) {
			return $html;
		}

		$engine = $this;

		if (stripos($html, '<img') !== false) {
			$html = preg_replace_callback(
				'/<img\s+[^>]*>/i',
				function ($matches) use ($engine) {
					return $engine->processImgTag($matches[0]);
				},
				$html
			);
		}

		if ((int) $this->params->get('rewrite_links', 1) && stripos($html, '<a') !== false) {
			$html = preg_replace_callback(
				'/<a\s+[^>]*>/i',
				function ($matches) use ($engine) {
					return $engine->processAnchorTag($matches[0]);
				},
				$html
			);
		}

		return $html;
	}

	/**
	 * Process a single <img ...> tag: decide scope, build/fetch cached watermarked
	 * image, rewrite the src attribute. Returns the tag unchanged if out of scope
	 * or on any failure (fail-safe: never break the page).
	 */
	public function processImgTag($tag)
	{
		if (!preg_match('/src\s*=\s*["\']([^"\']+)["\']/i', $tag, $srcMatch)) {
			return $tag;
		}

		if ($this->hasNoWatermarkClass($tag)) {
			return $tag;
		}

		$cachedUrl = $this->resolveWatermarkedUrl($srcMatch[1]);

		if ($cachedUrl === null) {
			return $tag;
		}

		return preg_replace(
			'/src\s*=\s*["\']([^"\']+)["\']/i',
			'src="' . $cachedUrl . '"',
			$tag,
			1
		);
	}

	/**
	 * Process a single <a ...> opening tag: if its href points directly at one
	 * of our own in-scope images (the common lightbox pattern - a thumbnail
	 * wrapped in a link to the full-size original, e.g. FG AutoLightbox and
	 * most other lightbox plugins), rewrite href to the same cached/watermarked
	 * copy used for the <img src>. Left unchanged for any other link (ordinary
	 * page links never match the image-extension check below).
	 */
	public function processAnchorTag($tag)
	{
		if (!preg_match('/href\s*=\s*["\']([^"\']+)["\']/i', $tag, $hrefMatch)) {
			return $tag;
		}

		if ($this->hasNoWatermarkClass($tag)) {
			return $tag;
		}

		$cachedUrl = $this->resolveWatermarkedUrl($hrefMatch[1]);

		if ($cachedUrl === null) {
			return $tag;
		}

		return preg_replace(
			'/href\s*=\s*["\']([^"\']+)["\']/i',
			'href="' . $cachedUrl . '"',
			$tag,
			1
		);
	}

	protected function hasNoWatermarkClass($tag)
	{
		if (preg_match('/class\s*=\s*["\']([^"\']*)["\']/i', $tag, $classMatch)) {
			$classes = preg_split('/\s+/', $classMatch[1]);

			return in_array('no-watermark', $classes, true);
		}

		return false;
	}

	/**
	 * Shared resolution logic for both processImgTag (src) and processAnchorTag
	 * (href): given a raw attribute value, decide whether it's one of our own
	 * in-scope images and, if so, return the URL of its cached/watermarked
	 * copy (building it first if needed). Returns null for anything out of
	 * scope or on any failure - callers already treat null as "leave as-is".
	 */
	protected function resolveWatermarkedUrl($rawValue)
	{
		if (!extension_loaded('gd')) {
			return null;
		}

		$relPath = $this->toRelativePath($rawValue);

		if ($relPath === null) {
			return null;
		}

		$cacheFolder = trim((string) $this->params->get('cache_folder', 'images/fgwatermark_cache'), '/');

		if (strpos($relPath, $cacheFolder) === 0) {
			return null;
		}

		if (!$this->inScope($relPath)) {
			return null;
		}

		$ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));

		if (!in_array($ext, array('jpg', 'jpeg', 'png', 'gif'), true)) {
			return null;
		}

		$sourceFullPath = JPATH_ROOT . '/' . $relPath;

		if (!is_file($sourceFullPath)) {
			return null;
		}

		$minWidth = (int) $this->params->get('min_width', 150);
		$size     = @getimagesize($sourceFullPath);

		if ($size === false) {
			return null;
		}

		if ($minWidth > 0 && $size[0] < $minWidth && $size[1] < $minWidth) {
			return null;
		}

		$cachedRelPath = $this->getCachedImage($relPath, $sourceFullPath, $ext, $size);

		if ($cachedRelPath === null) {
			return null;
		}

		return $this->getRootPath() . '/' . $cachedRelPath;
	}

	/**
	 * Root-relative site path (e.g. "" for root install, "/sub" for a subfolder
	 * install). Tries the modern namespaced Uri class first (always present from
	 * Joomla 4 onward without relying on any deprecated alias), falls back to the
	 * classic JUri for Joomla 3.
	 */
	protected function getRootPath()
	{
		if (class_exists('Joomla\\CMS\\Uri\\Uri')) {
			return rtrim(\Joomla\CMS\Uri\Uri::root(true), '/');
		}

		if (class_exists('JUri')) {
			return rtrim(JUri::root(true), '/');
		}

		return '';
	}

	/**
	 * The site's own hostname, used to tell "one of our own images" apart from
	 * an externally-hosted one when an <img src> is a full https:// URL.
	 * Goes through Joomla's Uri object rather than reading $_SERVER['HTTP_HOST']
	 * directly, since Joomla already resolves the correct host in reverse-proxy
	 * / load-balancer setups (X-Forwarded-Host and the "behind_loadbalancer"
	 * config), which a raw $_SERVER read does not account for.
	 */
	protected function getSiteHost()
	{
		if (class_exists('Joomla\\CMS\\Uri\\Uri')) {
			return \Joomla\CMS\Uri\Uri::getInstance()->getHost();
		}

		if (class_exists('JUri')) {
			return JUri::getInstance()->getHost();
		}

		return isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
	}

	/**
	 * Convert an <img src> value (relative, root-relative, or absolute-with-domain)
	 * into a path relative to JPATH_ROOT. Returns null if it can't be resolved
	 * locally (external domain, data: URI, etc).
	 */
	protected function toRelativePath($src)
	{
		if (stripos($src, 'data:') === 0) {
			return null;
		}

		$src = preg_replace('/[?#].*$/', '', $src);

		if (preg_match('#^https?://#i', $src)) {
			$host   = $this->getSiteHost();
			$parsed = parse_url($src);

			if (!isset($parsed['host']) || strcasecmp($parsed['host'], $host) !== 0) {
				return null;
			}

			$path = isset($parsed['path']) ? $parsed['path'] : '';
		} else {
			$path = $src;
		}

		$root = $this->getRootPath();

		if ($root !== '' && strpos($path, $root . '/') === 0) {
			$path = substr($path, strlen($root) + 1);
		}

		$path = ltrim($path, '/');

		// The <img src> is URL-encoded (e.g. spaces as %20), but the filesystem
		// path we build from it must use the real, decoded characters - otherwise
		// files with spaces or diacritics in their name are silently never found.
		$path = rawurldecode($path);

		return $path !== '' ? $path : null;
	}

	/**
	 * Check whether a relative path falls under the configured scope folder(s).
	 * Comma-separated list supported, e.g. "images/,media/uploads/"
	 */
	protected function inScope($relPath)
	{
		$scopeRaw = (string) $this->params->get('scope_folder', 'images/');
		$folders  = array_filter(array_map('trim', explode(',', $scopeRaw)));

		if (empty($folders)) {
			return true;
		}

		foreach ($folders as $folder) {
			$folder = trim($folder, '/') . '/';

			if (strpos($relPath, $folder) === 0) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the relative path to a valid, watermarked, cached copy of the image,
	 * generating it first if necessary. Returns null on failure.
	 */
	protected function getCachedImage($relPath, $sourceFullPath, $ext, $size)
	{
		$cacheFolder = trim((string) $this->params->get('cache_folder', 'images/fgwatermark_cache'), '/');
		$cacheFullFolder = JPATH_ROOT . '/' . $cacheFolder;

		if (!is_dir($cacheFullFolder)) {
			if (!@mkdir($cacheFullFolder, 0755, true) && !is_dir($cacheFullFolder)) {
				return null;
			}
		}

		$settingsHash = md5(serialize(array(
			self::VERSION,
			$this->params->get('image_enable'),
			$this->params->get('image_path'),
			$this->params->get('image_position'),
			$this->params->get('image_margin'),
			$this->params->get('image_scale'),
			$this->params->get('image_max_upscale'),
			$this->params->get('image_opacity'),
			$this->params->get('text_enable'),
			$this->params->get('text_content'),
			$this->params->get('text_font'),
			$this->params->get('text_size'),
			$this->params->get('text_color'),
			$this->params->get('text_position'),
			$this->params->get('text_margin'),
			$this->params->get('text_opacity'),
		)));

		$pathHash   = md5($relPath);
		$cacheFile  = $pathHash . '_' . substr($settingsHash, 0, 8) . '.' . ($ext === 'gif' ? 'png' : $ext);
		$cacheFullPath = $cacheFullFolder . '/' . $cacheFile;
		$cacheRelPath  = $cacheFolder . '/' . $cacheFile;

		$expiry = (int) $this->params->get('cache_expiry', 0);

		if (is_file($cacheFullPath)) {
			$isExpired = $expiry > 0 && (time() - filemtime($cacheFullPath)) > $expiry;
			$isStale   = filemtime($sourceFullPath) > filemtime($cacheFullPath);

			if (!$isExpired && !$isStale) {
				return $cacheRelPath;
			}
		}

		if ($this->renderWatermark($sourceFullPath, $cacheFullPath, $ext, $size)) {
			return $cacheRelPath;
		}

		return null;
	}

	/**
	 * Build the watermarked image with GD and save it to disk.
	 */
	protected function renderWatermark($sourceFullPath, $destFullPath, $ext, $size)
	{
		list($width, $height) = $size;

		switch ($ext) {
			case 'jpg':
			case 'jpeg':
				$src = @imagecreatefromjpeg($sourceFullPath);
				break;
			case 'png':
				$src = @imagecreatefrompng($sourceFullPath);
				break;
			case 'gif':
				$src = @imagecreatefromgif($sourceFullPath);
				break;
			default:
				return false;
		}

		if (!$src) {
			return false;
		}

		imagesavealpha($src, true);
		imagealphablending($src, true);

		if ((int) $this->params->get('image_enable', 0)) {
			$this->applyImageWatermark($src, $width, $height);
		}

		if ((int) $this->params->get('text_enable', 0)) {
			$this->applyTextWatermark($src, $width, $height);
		}

		$quality = (int) $this->params->get('jpeg_quality', 85);

		// Render into a temp file in the same folder, then atomically publish
		// it with rename(). rename() on the same filesystem is atomic on
		// Linux/POSIX, so a concurrent request re-building the same cache
		// entry (or the webserver directly serving the cache URL as a static
		// file, which happens entirely outside PHP) always sees either the
		// old state or the fully written new file - never a half-written one.
		$tmpPath = $destFullPath . '.' . getmypid() . '.' . mt_rand(1000, 9999) . '.tmp';

		if ($ext === 'jpg' || $ext === 'jpeg') {
			$saved = imagejpeg($src, $tmpPath, $quality);
		} else {
			imagesavealpha($src, true);
			$saved = imagepng($src, $tmpPath, 6);
		}

		imagedestroy($src);

		if (!$saved) {
			@unlink($tmpPath);

			return false;
		}

		if (!@rename($tmpPath, $destFullPath)) {
			@unlink($tmpPath);

			return false;
		}

		return true;
	}

	/**
	 * Write a warning to the Joomla log (category "plg_content_fgwatermark") when
	 * available, falling back to the PHP error log. Never throws - logging must
	 * not be able to break page rendering.
	 */
	protected function logWarning($message)
	{
		try {
			if (class_exists('Joomla\\CMS\\Log\\Log')) {
				\Joomla\CMS\Log\Log::add($message, \Joomla\CMS\Log\Log::WARNING, 'plg_content_fgwatermark');
				return;
			}

			if (class_exists('JLog')) {
				JLog::add($message, JLog::WARNING, 'plg_content_fgwatermark');
				return;
			}
		} catch (\Exception $e) {
			// fall through to error_log
		}

		error_log('[plg_content_fgwatermark] ' . $message);
	}

	/**
	 * Rasterize an SVG logo into a GD truecolor image. GD itself cannot read
	 * SVG at all, so this requires the Imagick extension with SVG (rsvg/librsvg
	 * or MSVG) support compiled in. Returns [gdResource, [width, height]] on
	 * success, or null (after logging a warning) if SVG can't be handled on
	 * this server - callers already treat null the same as "no logo configured".
	 */
	protected function rasterizeSvg($svgFullPath)
	{
		if (!class_exists('Imagick')) {
			$this->logWarning(
				'Logo is an SVG file, but the Imagick PHP extension is not available on this server, ' .
				'so it can\'t be rasterized. Either install/enable php-imagick, or convert the logo to a PNG.'
			);

			return null;
		}

		list($svgW, $svgH) = $this->getSvgDimensions($svgFullPath);

		try {
			$imagick = new \Imagick();

			// Render well above the size we'll actually need (watermark placement
			// scales it down later), so the downsample stays crisp instead of the
			// SVG being rasterized small and then blown up.
			$renderWidth = max(64, min(2000, $svgW * 4));
			$renderHeight = (int) round($renderWidth * ($svgH / $svgW));

			$imagick->setBackgroundColor(new \ImagickPixel('transparent'));
			$imagick->setResolution(300, 300);
			$imagick->readImage($svgFullPath);
			$imagick->setImageFormat('png32');
			$imagick->resizeImage($renderWidth, $renderHeight, \Imagick::FILTER_LANCZOS, 1, false);

			$blob = $imagick->getImageBlob();
			$imagick->clear();

			$logo = @imagecreatefromstring($blob);

			if (!$logo) {
				return null;
			}

			return array($logo, array($renderWidth, $renderHeight));
		} catch (\Exception $e) {
			$this->logWarning('Failed to rasterize SVG logo: ' . $e->getMessage());

			return null;
		}
	}

	/**
	 * Parse width/height (or viewBox as a fallback) straight out of the SVG's
	 * XML, since getimagesize() doesn't understand SVG at all. Falls back to a
	 * square aspect ratio if nothing usable is found.
	 */
	protected function getSvgDimensions($svgFullPath)
	{
		$content = @file_get_contents($svgFullPath, false, null, 0, 8192);

		if ($content !== false && preg_match('/<svg\b[^>]*>/i', $content, $tagMatch)) {
			$tag = $tagMatch[0];

			if (preg_match('/width\s*=\s*["\']?([\d.]+)/i', $tag, $wMatch)
				&& preg_match('/height\s*=\s*["\']?([\d.]+)/i', $tag, $hMatch)) {
				$w = (float) $wMatch[1];
				$h = (float) $hMatch[1];

				if ($w > 0 && $h > 0) {
					return array($w, $h);
				}
			}

			if (preg_match('/viewBox\s*=\s*["\']?\s*[\d.\-]+\s+[\d.\-]+\s+([\d.]+)\s+([\d.]+)/i', $tag, $vbMatch)) {
				$w = (float) $vbMatch[1];
				$h = (float) $vbMatch[2];

				if ($w > 0 && $h > 0) {
					return array($w, $h);
				}
			}
		}

		return array(300, 300);
	}

	/**
	 * Composite the configured logo image onto the canvas.
	 */
	protected function applyImageWatermark(&$canvas, $width, $height)
	{
		$logoRel = (string) $this->params->get('image_path', '');

		// Joomla's media field appends metadata after '#', e.g.
		// "images/logo.png#joomlaImage://local-images/logo.png?width=54&height=117"
		// - strip it, we only want the actual file path.
		$hashPos = strpos($logoRel, '#');

		if ($hashPos !== false) {
			$logoRel = substr($logoRel, 0, $hashPos);
		}

		if ($logoRel === '') {
			return;
		}

		$logoFullPath = JPATH_ROOT . '/' . ltrim($logoRel, '/');

		if (!is_file($logoFullPath)) {
			return;
		}

		$logoExt = strtolower(pathinfo($logoFullPath, PATHINFO_EXTENSION));

		if ($logoExt === 'svg') {
			$logoInfo = $this->rasterizeSvg($logoFullPath);

			if ($logoInfo === null) {
				return;
			}

			list($logo, $logoInfo) = $logoInfo;
		} else {
			$rawInfo = @getimagesize($logoFullPath);

			if (!$rawInfo) {
				return;
			}

			$logoInfo = $rawInfo;

			switch ($logoExt) {
				case 'png':
					$logo = @imagecreatefrompng($logoFullPath);
					break;
				case 'jpg':
				case 'jpeg':
					$logo = @imagecreatefromjpeg($logoFullPath);
					break;
				case 'gif':
					$logo = @imagecreatefromgif($logoFullPath);
					break;
				default:
					return;
			}
		}

		if (!$logo) {
			return;
		}

		imagesavealpha($logo, true);
		imagealphablending($logo, true);

		$scalePct = max(1, min(100, (int) $this->params->get('image_scale', 20)));
		$targetW  = (int) round($width * ($scalePct / 100));
		$ratio    = $logoInfo[1] / $logoInfo[0];
		$targetH  = (int) round($targetW * $ratio);

		$maxUpscale = (float) $this->params->get('image_max_upscale', 3);

		if ($maxUpscale > 0 && $targetW > $logoInfo[0] * $maxUpscale) {
			$cappedW = (int) round($logoInfo[0] * $maxUpscale);

			$this->logWarning(sprintf(
				'Watermark logo upscaled beyond %.1fx native size (native %dpx, requested %dpx) - capped to %dpx. Consider uploading a higher-resolution logo.',
				$maxUpscale,
				$logoInfo[0],
				$targetW,
				$cappedW
			));

			$targetW = $cappedW;
			$targetH = (int) round($targetW * $ratio);
		}

		if ($targetW < 1 || $targetH < 1) {
			imagedestroy($logo);
			return;
		}

		$resized = imagecreatetruecolor($targetW, $targetH);
		imagesavealpha($resized, true);
		imagealphablending($resized, false);
		$transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
		imagefill($resized, 0, 0, $transparent);
		imagealphablending($resized, true);
		imagecopyresampled($resized, $logo, 0, 0, 0, 0, $targetW, $targetH, $logoInfo[0], $logoInfo[1]);
		imagedestroy($logo);

		$opacity  = max(0, min(100, (int) $this->params->get('image_opacity', 60)));
		$margin   = (int) $this->params->get('image_margin', 10);
		$position = (string) $this->params->get('image_position', 'br');

		list($x, $y) = $this->calculatePosition($position, $width, $height, $targetW, $targetH, $margin);

		if ($opacity < 100) {
			$this->scaleAlphaChannel($resized, $opacity);
		}

		// Plain imagecopy (not imagecopymerge!) correctly respects the source's
		// own per-pixel alpha channel as long as alpha blending is enabled on the
		// destination canvas - imagecopymerge does NOT honour source alpha at all,
		// which is what previously caused a dark box behind transparent PNG logos.
		imagealphablending($canvas, true);
		imagecopy($canvas, $resized, $x, $y, 0, 0, $targetW, $targetH);

		imagedestroy($resized);
	}

	/**
	 * Uniformly scale down a truecolor image's alpha channel (i.e. reduce its
	 * overall opacity) while fully preserving its existing per-pixel transparency
	 * (e.g. a logo's rounded corners or soft edges stay correctly transparent,
	 * they just also become more see-through where they were already visible).
	 */
	protected function scaleAlphaChannel(&$img, $opacityPct)
	{
		$w = imagesx($img);
		$h = imagesy($img);
		$factor = max(0, min(100, $opacityPct)) / 100;

		imagealphablending($img, false);

		for ($px = 0; $px < $w; $px++) {
			for ($py = 0; $py < $h; $py++) {
				$rgba  = imagecolorat($img, $px, $py);
				$alpha = ($rgba >> 24) & 0x7F;
				$r     = ($rgba >> 16) & 0xFF;
				$g     = ($rgba >> 8) & 0xFF;
				$b     = $rgba & 0xFF;

				// GD alpha: 0 = fully opaque, 127 = fully transparent.
				$newAlpha = (int) round($alpha + (127 - $alpha) * (1 - $factor));
				$newAlpha = max(0, min(127, $newAlpha));

				$color = imagecolorallocatealpha($img, $r, $g, $b, $newAlpha);
				imagesetpixel($img, $px, $py, $color);
			}
		}

		imagealphablending($img, true);
	}

	/**
	 * Draw the configured text watermark onto the canvas.
	 */
	protected function applyTextWatermark(&$canvas, $width, $height)
	{
		$text = (string) $this->params->get('text_content', '');

		if (trim($text) === '') {
			return;
		}

		$size     = max(6, (int) $this->params->get('text_size', 16));
		$opacity  = max(0, min(100, (int) $this->params->get('text_opacity', 70)));
		$margin   = (int) $this->params->get('text_margin', 10);
		$position = (string) $this->params->get('text_position', 'br');
		$colorHex = (string) $this->params->get('text_color', '#FFFFFF');
		$fontRel  = trim((string) $this->params->get('text_font', ''));

		list($r, $g, $b) = $this->hexToRgb($colorHex);
		$alpha = (int) round((100 - $opacity) * 127 / 100);
		$color = imagecolorallocatealpha($canvas, $r, $g, $b, $alpha);

		$fontFullPath = $fontRel !== '' ? JPATH_ROOT . '/' . ltrim($fontRel, '/') : '';
		$useTtf = $fontFullPath !== '' && is_file($fontFullPath) && function_exists('imagettfbbox');

		if ($useTtf) {
			$box = imagettfbbox($size, 0, $fontFullPath, $text);
			$textW = abs($box[4] - $box[0]);
			$textH = abs($box[5] - $box[1]);
		} else {
			$gdFont = 5;
			$textW = imagefontwidth($gdFont) * strlen($text);
			$textH = imagefontheight($gdFont);
		}

		list($x, $y) = $this->calculatePosition($position, $width, $height, $textW, $textH, $margin);

		if ($useTtf) {
			imagettftext($canvas, $size, 0, $x, $y + $textH, $color, $fontFullPath, $text);
		} else {
			imagestring($canvas, 5, $x, $y, $text, $color);
		}
	}

	protected function hexToRgb($hex)
	{
		$hex = ltrim($hex, '#');

		if (strlen($hex) === 3) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		if (strlen($hex) !== 6) {
			return array(255, 255, 255);
		}

		return array(
			hexdec(substr($hex, 0, 2)),
			hexdec(substr($hex, 2, 2)),
			hexdec(substr($hex, 4, 2)),
		);
	}

	/**
	 * Calculate top-left x,y for placing a $elW x $elH element within a
	 * $canvasW x $canvasH canvas, at one of 9 named positions with margin.
	 */
	protected function calculatePosition($position, $canvasW, $canvasH, $elW, $elH, $margin)
	{
		switch ($position) {
			case 'tl':
				return array($margin, $margin);
			case 'tc':
				return array((int) (($canvasW - $elW) / 2), $margin);
			case 'tr':
				return array($canvasW - $elW - $margin, $margin);
			case 'cl':
				return array($margin, (int) (($canvasH - $elH) / 2));
			case 'cc':
				return array((int) (($canvasW - $elW) / 2), (int) (($canvasH - $elH) / 2));
			case 'cr':
				return array($canvasW - $elW - $margin, (int) (($canvasH - $elH) / 2));
			case 'bl':
				return array($margin, $canvasH - $elH - $margin);
			case 'bc':
				return array((int) (($canvasW - $elW) / 2), $canvasH - $elH - $margin);
			case 'br':
			default:
				return array($canvasW - $elW - $margin, $canvasH - $elH - $margin);
		}
	}
}
