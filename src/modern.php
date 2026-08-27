<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Content.Watermark
 *
 * SubscriberInterface wrapper. Loaded whenever Joomla\Event\SubscriberInterface
 * exists - Joomla 4, 5 and 6. CMSPlugin's legacy on*-method auto-registration
 * is removed as of Joomla 6.0, so this is the only reliable path from J6 onward;
 * it also works fine on 4 and 5, which is why it's preferred over legacy.php
 * whenever available. Real work happens in WatermarkEngine (src/engine.php).
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Joomla\Event\Event;

class PlgContentFgwatermark extends CMSPlugin implements SubscriberInterface
{
	protected $autoloadLanguage = true;

	/** @var WatermarkEngine */
	protected $engine;

	public static function getSubscribedEvents(): array
	{
		return [
			'onContentPrepare' => 'onContentPrepare',
		];
	}

	public function onContentPrepare(Event $event): void
	{
		if (!$this->engine) {
			$this->engine = new WatermarkEngine($this->params);
		}

		$imageEnabled = (int) $this->params->get('image_enable', 0);
		$textEnabled  = (int) $this->params->get('text_enable', 0);

		if (!$imageEnabled && !$textEnabled) {
			return;
		}

		// Positional destructure (context, item, params, page) - robust against
		// whether Joomla passed a generic Event or a concrete ContentPrepareEvent,
		// since the legacy argument order is preserved either way.
		$values = array_values($event->getArguments());
		$row    = isset($values[1]) ? $values[1] : null;

		if (!is_object($row)) {
			return;
		}

		if (isset($row->text)) {
			$row->text = $this->engine->processHtml($row->text);
		} elseif (isset($row->introtext)) {
			$row->introtext = $this->engine->processHtml($row->introtext);

			if (isset($row->fulltext)) {
				$row->fulltext = $this->engine->processHtml($row->fulltext);
			}
		}
	}
}
