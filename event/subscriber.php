<?php

/**
* @package   s9e\mediaembed
* @copyright Copyright (c) 2014 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\mediaembed\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use s9e\TextFormatter\Bundles\MediaPack;

class subscriber implements EventSubscriberInterface
{
	public static function autoload()
	{
		if (!class_exists('s9e\\TextFormatter\\Bundles\\MediaPack'))
		{
			include_once __DIR__ . '/../vendor/s9e/TextFormatter/src/autoloader.php';
		}
	}

	public static function getSubscribedEvents()
	{
		return array(
			'core.modify_format_display_text_after' => 'onPreview',
			'core.modify_submit_post_data'          => 'onSubmit',
			'core.modify_text_for_display_after'    => 'onDisplay',
			'core.modify_text_for_edit_before'      => 'onEdit',
			'core.modify_text_for_storage_after'    => 'onStorage'
		);
	}

	public function onDisplay($event)
	{
		$event['text'] = $this->render($event['text']);
	}

	public function onEdit($event)
	{
		$event['text'] = $this->unparse($event['text']);
	}

	public function onPreview($event)
	{
		$text = str_replace(
			array('<br /><!-- m -->', '<!-- m --><br />'),
			array("\n<!-- m -->",     "<!-- m -->\n"),
			$event['text']
		);
		$event['text'] = $this->render($this->parse($text));
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

		return preg_replace_callback(
			'((?<=^|<br />)<!-- m -->.*?href="([^"]+)".*<!-- m -->(?=<br />|$))m',
			function ($m)
			{
				subscriber::autoload();
				$xml = MediaPack::parse($m[1]);

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

		return preg_replace_callback(
			'(<!-- s9e:mediaembed:([^ ]++) --><!-- m -->.*?<!-- m -->)',
			function ($m)
			{
				subscriber::autoload();
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

		return preg_replace('(<!-- s9e:mediaembed:([^ ]++) -->)', '', $text);
	}
}