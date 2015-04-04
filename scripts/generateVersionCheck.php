<?php
$composer = json_decode(file_get_contents(__DIR__ . '/../composer.json'));
$version  = $composer->version;
file_put_contents(
	__DIR__ . '/../versions.json',
	json_encode([
		'stable' => [
			$version => [
				'current'      => $version,
				'announcement' => 'https://www.phpbb.com/community/viewtopic.php?f=456&t=2272431',
				'download'     => 'https://github.com/s9e/phpbb-ext-mediaembed/releases/download/' . $version . '/s9e-mediaembed-' . $version . '.zip',
				'eol'          => null,
				'security'     => false
			]
		]
	])
);