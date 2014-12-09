<?php

function getSource(array $filenames)
{
	$php = '<?php';
	foreach ($filenames as $filename)
	{
		$php .= "\n\n" . preg_replace(
			'(^<\\?php\\s+)', '',
			file_get_contents(__DIR__ . '/../vendor/s9e/TextFormatter/src/' . $filename)
		);
	}

	return $php;
}

$bundles = [
	'bundle.php' => [
		'Bundle.php',
		'Bundles/MediaPack.php'
	],
	'parsing.php' => [
		'Parser.php',
		'Parser/BuiltInFilters.php',
		'Parser/Logger.php',
		'Parser/Tag.php',
		'Plugins/MediaEmbed/Parser.php',
		'Plugins/ParserBase.php'
	],
	'rendering.php' => [
		'Renderer.php',
		'Bundles/MediaPack/Renderer.php'
	]
];

foreach ($bundles as $target => $filenames)
{
	file_put_contents($target, getSource($filenames));
}