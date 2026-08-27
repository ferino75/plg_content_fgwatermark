<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.Watermark
 *
 * Classic JPlugin-style wrapper. Loaded ONLY when Joomla\Event\SubscriberInterface
 * doesn't exist yet - in practice that means Joomla 3.x. Kept deliberately thin:
 * all the real work happens in WatermarkEngine (src/engine.php), shared with the
 * modern.php wrapper used on Joomla 4/5/6.
 */

defined('_JEXEC') or die;

jimport('joomla.plugin.plugin');

class PlgContentFgwatermark extends JPlugin
{
	protected $autoloadLanguage = true;

	/** @var WatermarkEngine */
	protected $engine;

	public function onContentPrepare($context, &$row, &$params, $page = 0)
	{
		if (!$this->engine) {
			$this->engine = new WatermarkEngine($this->params);
		}

		$imageEnabled = (int) $this->params->get('image_enable', 0);
		$textEnabled  = (int) $this->params->get('text_enable', 0);

		if (!$imageEnabled && !$textEnabled) {
			return true;
		}

		if (isset($row->text)) {
			$row->text = $this->engine->processHtml($row->text);
		} elseif (isset($row->introtext)) {
			$row->introtext = $this->engine->processHtml($row->introtext);

			if (isset($row->fulltext)) {
				$row->fulltext = $this->engine->processHtml($row->fulltext);
			}
		}

		return true;
	}
}
