<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.Watermark
 *
 * Installer script. Joomla looks for a class named "<element>InstallerScript"
 * (class name matching is case-insensitive in PHP) - for this plugin that's
 * PlgContentFgwatermarkInstallerScript. Only uninstall() is implemented; Joomla
 * calls methods via method_exists(), so the other four lifecycle hooks
 * (preflight/install/update/postflight) simply don't need to exist.
 */

defined('_JEXEC') or die;

class PlgContentFgwatermarkInstallerScript
{
	/**
	 * Runs after the plugin has been successfully uninstalled. Removes the
	 * watermark cache folder so no orphaned generated images are left behind.
	 * Never throws - a cleanup failure must not turn into a failed uninstall.
	 */
	public function uninstall($parent)
	{
		$cacheFolder = $this->getConfiguredCacheFolder();

		$fullPath = JPATH_ROOT . '/' . ltrim($cacheFolder, '/');

		// Safety guard: refuse to touch anything that isn't clearly our own
		// cache folder under images/, in case of a misconfigured/empty value.
		if ($cacheFolder === '' || strpos($cacheFolder, 'fgwatermark_cache') === false) {
			return true;
		}

		if (is_dir($fullPath)) {
			$this->deleteFolder($fullPath);
		}

		return true;
	}

	/**
	 * Best-effort read of the plugin's own "cache_folder" param straight from
	 * the #__extensions table, since by the time uninstall() runs there's no
	 * guarantee a live plugin params object is still available. Falls back to
	 * the documented default if anything about this lookup fails.
	 */
	protected function getConfiguredCacheFolder()
	{
		$default = 'images/fgwatermark_cache';

		try {
			if (class_exists('Joomla\\CMS\\Factory')) {
				$db = \Joomla\CMS\Factory::getDbo();
			} elseif (class_exists('JFactory')) {
				$db = JFactory::getDbo();
			} else {
				return $default;
			}

			$query = $db->getQuery(true)
				->select($db->quoteName('params'))
				->from($db->quoteName('#__extensions'))
				->where($db->quoteName('element') . ' = ' . $db->quote('fgwatermark'))
				->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
				->where($db->quoteName('folder') . ' = ' . $db->quote('content'));

			$db->setQuery($query);
			$paramsJson = $db->loadResult();

			if (!$paramsJson) {
				return $default;
			}

			$params = json_decode($paramsJson, true);

			if (is_array($params) && !empty($params['cache_folder'])) {
				return trim((string) $params['cache_folder'], '/');
			}
		} catch (\Exception $e) {
			// ignore, fall back below
		}

		return $default;
	}

	/**
	 * Plain-PHP recursive folder delete - deliberately avoids any Joomla
	 * filesystem class so it works identically regardless of version.
	 */
	protected function deleteFolder($path)
	{
		$items = @scandir($path);

		if ($items === false) {
			return;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$itemPath = $path . '/' . $item;

			if (is_dir($itemPath)) {
				$this->deleteFolder($itemPath);
			} else {
				@unlink($itemPath);
			}
		}

		@rmdir($path);
	}
}
