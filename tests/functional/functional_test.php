<?php

/**
* @package   s9e\mediaembed
* @copyright Copyright (c) 2014-2016 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\mediaembed\tests\functional;

/**
* @group functional
*/
class functional_test extends \phpbb_functional_test_case
{
	static protected function setup_extensions()
	{
		return array('s9e/mediaembed');
	}

	public function test()
	{
		$this->login();

		$post = $this->create_topic(2, 'Title', 'https://www.youtube.com/watch?v=9bZkp7q19f0');
		$crawler = self::request('GET', "viewtopic.php?t={$post['topic_id']}&sid={$this->sid}");
		$this->assertSame(1, count($crawler->filterXPath('//iframe[@src="//www.youtube.com/embed/9bZkp7q19f0"]')));
	}
}