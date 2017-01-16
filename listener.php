<?php

/**
* @package   s9e\mediaembed
* @copyright Copyright (c) 2014-2016 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\mediaembed;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use s9e\TextFormatter\Configurator\Bundles\MediaPack as MediaPackConfigurator;
use s9e\TextFormatter\Bundles\MediaPack;

class listener implements EventSubscriberInterface
{
	public static function getSubscribedEvents()
	{
		return array(
			'core.text_formatter_s9e_configure_after'  => 'onConfigure',
			'core.modify_format_display_text_after'    => 'onPreview',
			'core.modify_submit_post_data'             => 'onSubmit',
			'core.modify_text_for_display_after'       => 'onDisplay',
			'core.modify_text_for_edit_before'         => 'onEdit',
			'core.modify_text_for_storage_after'       => 'onStorage',
			'core.submit_pm_before'                    => 'onPmStorage'
		);
	}

	public function onConfigure($event)
	{
		$configurator = $event['configurator'];
		$attrMap      = array();
		foreach ($configurator->MediaEmbed->defaultSites as $siteId => $definition)
		{
			if (isset($configurator->BBCodes[$siteId]))
			{
				$configurator->BBCodes[$siteId]->defaultAttribute  = 'url';
				$configurator->BBCodes[$siteId]->contentAttributes = array('url');
			}
			if (isset($configurator->tags[$siteId]))
			{
				foreach ($configurator->tags[$siteId]->attributes as $attrName => $attribute)
				{
					if ($attrName === 'id')
					{
						break;
					}
				}
				$attrMap[$siteId] = $attrName;
			}
		}

		$bundleConfigurator = new MediaPackConfigurator;
		$bundleConfigurator->configure($configurator);

		foreach ($attrMap as $siteId => $attrName)
		{
			$tag = $configurator->tags[$siteId];
			if (!isset($tag->attributes['id']))
			{
				continue;
			}
			$tag->template = '<xsl:choose><xsl:when test="@id">' . $tag->template . '</xsl:when><xsl:otherwise>' . str_replace('@id', '@' . $attrName, $tag->template) . '</xsl:otherwise></xsl:choose>';
		}
	}

	public function onDisplay($event)
	{
		$event['text'] = $this->render($event['text']);
	}

	public function onEdit($event)
	{
		$event['text'] = $this->unparse($event['text']);
	}

	public function onPmStorage($event)
	{
		$data = $event['data'];
		$data['message'] = $this->parse($data['message']);
		$event['data'] = $data;
	}

	public function onPreview($event)
	{
		$event['text'] = $this->render($this->parse($event['text']));
	}

	public function onStorage($event)
	{
		$event['text'] = $this->parse($event['text']);
	}

	public function onSubmit($event)
	{
		$data = $event['data'];
		$data['message'] = $this->parse($data['message']);
		$event['data'] = $data;
	}

	public function parse($text)
	{
		if (strpos($text, '<!-- m -->') === false)
		{
			return $text;
		}

		if (!class_exists('s9e\\TextFormatter\\Parser', false))
		{
			include_once __DIR__ . '/bundle.php';
			include __DIR__ . '/parsing.php';
		}

		return preg_replace_callback(
			'(<!-- m -->.*?href="([^"]+).*?<!-- m -->)',
			function ($m)
			{
				$xml = MediaPack::parse(htmlspecialchars_decode($m[1]));

				return ($xml[1] === 'r')
					? '<!-- s9e:mediaembed:' . base64_encode($xml) . ' -->' . $m[0]
					: $m[0];
			},
			$text
		);
	}

	public function render($text)
	{
		if (strpos($text, '<!-- s9e:mediaembed') === false)
		{
			return $text;
		}

		if (!class_exists('s9e\\TextFormatter\\Renderer', false))
		{
			include_once __DIR__ . '/bundle.php';
			include __DIR__ . '/rendering.php';
		}

		return preg_replace_callback(
			'(<!-- s9e:mediaembed:([^ ]+) --><!-- m -->.*?<!-- m -->)',
			function ($m)
			{
				return MediaPack::render(base64_decode($m[1]));
			},
			$text
		);
	}

	public function unparse($text)
	{
		if (strpos($text, '<!-- s9e:mediaembed') === false)
		{
			return $text;
		}

		return preg_replace('(<!-- s9e:mediaembed:([^ ]+) -->)', '', $text);
	}
}