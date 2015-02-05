<?php

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2015 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter;

use InvalidArgumentException;
use RuntimeException;
use s9e\TextFormatter\Parser\Logger;
use s9e\TextFormatter\Parser\Tag;

class Parser
{
	const RULE_AUTO_CLOSE        = 1;
	const RULE_AUTO_REOPEN       = 2;
	const RULE_BREAK_PARAGRAPH   = 4;
	const RULE_CREATE_PARAGRAPHS = 8;
	const RULE_DISABLE_AUTO_BR   = 16;
	const RULE_ENABLE_AUTO_BR    = 32;
	const RULE_IGNORE_TAGS       = 64;
	const RULE_IGNORE_TEXT       = 128;
	const RULE_IS_TRANSPARENT    = 256;
	const RULE_PREVENT_BR        = 512;
	const RULE_SUSPEND_AUTO_BR   = 1024;
	const RULE_TRIM_WHITESPACE   = 2048;
	const RULES_AUTO_LINEBREAKS = 1072;

	const RULES_INHERITANCE = 32;

	const WHITESPACE = ' 
	';

	protected $cntOpen;

	protected $cntTotal;

	protected $context;

	protected $currentFixingCost;

	protected $currentTag;

	protected $isRich;

	protected $logger;

	public $maxFixingCost = 1000;

	protected $namespaces;

	protected $openTags;

	protected $output;

	protected $pos;

	protected $pluginParsers = array();

	protected $pluginsConfig;

	public $registeredVars = array();

	protected $rootContext;

	protected $tagsConfig;

	protected $tagStack;

	protected $tagStackIsSorted;

	protected $text;

	protected $textLen;

	protected $uid = 0;

	protected $wsPos;

	public function __construct(array $config)
	{
		$this->pluginsConfig  = $config['plugins'];
		$this->registeredVars = $config['registeredVars'];
		$this->rootContext    = $config['rootContext'];
		$this->tagsConfig     = $config['tags'];

		$this->__wakeup();
	}

	public function __sleep()
	{
		return array('pluginsConfig', 'registeredVars', 'rootContext', 'tagsConfig');
	}

	public function __wakeup()
	{
		$this->logger = new Logger;
	}

	protected function reset($text)
	{
		$text = \preg_replace('/\\r\\n?/', "\n", $text);
		$text = \preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]+/S', '', $text);

		$this->logger->clear();

		$this->currentFixingCost = 0;
		$this->isRich     = \false;
		$this->namespaces = array();
		$this->output     = '';
		$this->text       = $text;
		$this->textLen    = \strlen($text);
		$this->tagStack   = array();
		$this->tagStackIsSorted = \true;
		$this->wsPos      = 0;

		++$this->uid;
	}

	protected function setTagOption($tagName, $optionName, $optionValue)
	{
		if (isset($this->tagsConfig[$tagName]))
		{
			$tagConfig = $this->tagsConfig[$tagName];
			unset($this->tagsConfig[$tagName]);

			$tagConfig[$optionName]     = $optionValue;
			$this->tagsConfig[$tagName] = $tagConfig;
		}
	}

	public function disableTag($tagName)
	{
		$this->setTagOption($tagName, 'isDisabled', \true);
	}

	public function enableTag($tagName)
	{
		if (isset($this->tagsConfig[$tagName]))
			unset($this->tagsConfig[$tagName]['isDisabled']);
	}

	public function getLogger()
	{
		return $this->logger;
	}

	public function getText()
	{
		return $this->text;
	}

	public function parse($text)
	{
		$this->reset($text);
		$uid = $this->uid;

		$this->executePluginParsers();
		$this->processTags();

		if ($this->uid !== $uid)
			throw new RuntimeException('The parser has been reset during execution');

		return $this->output;
	}

	public function setTagLimit($tagName, $tagLimit)
	{
		$this->setTagOption($tagName, 'tagLimit', $tagLimit);
	}

	public function setNestingLimit($tagName, $nestingLimit)
	{
		$this->setTagOption($tagName, 'nestingLimit', $nestingLimit);
	}

	public static function executeAttributePreprocessors(Tag $tag, array $tagConfig)
	{
		if (!empty($tagConfig['attributePreprocessors']))
			foreach ($tagConfig['attributePreprocessors'] as $_2698889989)
			{
				list($attrName, $regexp) = $_2698889989;
				if (!$tag->hasAttribute($attrName))
					continue;

				$attrValue = $tag->getAttribute($attrName);

				if (\preg_match($regexp, $attrValue, $m))
					foreach ($m as $targetName => $targetValue)
					{
						if (\is_numeric($targetName) || $targetValue === '')
							continue;

						if ($targetName === $attrName || !$tag->hasAttribute($targetName))
							$tag->setAttribute($targetName, $targetValue);
					}
			}

		return \true;
	}

	protected static function executeFilter(array $filter, array $vars)
	{
		$callback = $filter['callback'];
		$params   = (isset($filter['params'])) ? $filter['params'] : array();

		$args = array();
		foreach ($params as $k => $v)
			if (\is_numeric($k))
				$args[] = $v;
			elseif (isset($vars[$k]))
				$args[] = $vars[$k];
			elseif (isset($vars['registeredVars'][$k]))
				$args[] = $vars['registeredVars'][$k];
			else
				$args[] = \null;

		return \call_user_func_array($callback, $args);
	}

	public static function filterAttributes(Tag $tag, array $tagConfig, array $registeredVars, Logger $logger)
	{
		if (empty($tagConfig['attributes']))
		{
			$tag->setAttributes(array());

			return \true;
		}

		foreach ($tagConfig['attributes'] as $attrName => $attrConfig)
			if (isset($attrConfig['generator']))
				$tag->setAttribute(
					$attrName,
					self::executeFilter(
						$attrConfig['generator'],
						array(
							'attrName'       => $attrName,
							'logger'         => $logger,
							'registeredVars' => $registeredVars
						)
					)
				);

		foreach ($tag->getAttributes() as $attrName => $attrValue)
		{
			if (!isset($tagConfig['attributes'][$attrName]))
			{
				$tag->removeAttribute($attrName);
				continue;
			}

			$attrConfig = $tagConfig['attributes'][$attrName];

			if (!isset($attrConfig['filterChain']))
				continue;

			$logger->setAttribute($attrName);

			foreach ($attrConfig['filterChain'] as $filter)
			{
				$attrValue = self::executeFilter(
					$filter,
					array(
						'attrName'       => $attrName,
						'attrValue'      => $attrValue,
						'logger'         => $logger,
						'registeredVars' => $registeredVars
					)
				);

				if ($attrValue === \false)
				{
					$tag->removeAttribute($attrName);
					break;
				}
			}

			if ($attrValue !== \false)
				$tag->setAttribute($attrName, $attrValue);

			$logger->unsetAttribute();
		}

		foreach ($tagConfig['attributes'] as $attrName => $attrConfig)
			if (!$tag->hasAttribute($attrName))
				if (isset($attrConfig['defaultValue']))
					$tag->setAttribute($attrName, $attrConfig['defaultValue']);
				elseif (!empty($attrConfig['required']))
					return \false;

		return \true;
	}

	protected function filterTag(Tag $tag)
	{
		$tagName   = $tag->getName();
		$tagConfig = $this->tagsConfig[$tagName];
		$isValid   = \true;

		if (!empty($tagConfig['filterChain']))
		{
			$this->logger->setTag($tag);

			$vars = array(
				'logger'         => $this->logger,
				'openTags'       => $this->openTags,
				'parser'         => $this,
				'registeredVars' => $this->registeredVars,
				'tag'            => $tag,
				'tagConfig'      => $tagConfig
			);

			foreach ($tagConfig['filterChain'] as $filter)
				if (!self::executeFilter($filter, $vars))
				{
					$isValid = \false;
					break;
				}

			$this->logger->unsetTag();
		}

		return $isValid;
	}

	protected function finalizeOutput()
	{
		$this->outputText($this->textLen, 0, \true);

		do
		{
			$this->output = \preg_replace(
				'#<([\\w:]+)[^>]*></\\1>#',
				'',
				$this->output,
				-1,
				$cnt
			);
		}
		while ($cnt);

		if (\strpos($this->output, '</i><i>') !== \false)
			$this->output = \str_replace('</i><i>', '', $this->output);

		$tagName = ($this->isRich) ? 'r' : 't';

		$tmp = '<' . $tagName;
		foreach (\array_keys($this->namespaces) as $prefix)
			$tmp .= ' xmlns:' . $prefix . '="urn:s9e:TextFormatter:' . $prefix . '"';

		$this->output = $tmp . '>' . $this->output . '</' . $tagName . '>';
	}

	protected function outputTag(Tag $tag)
	{
		$this->isRich = \true;

		$tagName  = $tag->getName();
		$tagPos   = $tag->getPos();
		$tagLen   = $tag->getLen();
		$tagFlags = $tag->getFlags();

		if ($tagFlags & self::RULE_TRIM_WHITESPACE)
		{
			$skipBefore = ($tag->isStartTag()) ? 2 : 1;
			$skipAfter  = ($tag->isEndTag())   ? 2 : 1;
		}
		else
			$skipBefore = $skipAfter = 0;

		$closeParagraph = \false;
		if ($tag->isStartTag())
		{
			if ($tagFlags & self::RULE_BREAK_PARAGRAPH)
				$closeParagraph = \true;
		}
		else
			$closeParagraph = \true;

		$this->outputText($tagPos, $skipBefore, $closeParagraph);

		$tagText = ($tagLen)
		         ? \htmlspecialchars(\substr($this->text, $tagPos, $tagLen), \ENT_NOQUOTES, 'UTF-8')
		         : '';

		if ($tag->isStartTag())
		{
			if (!($tagFlags & self::RULE_BREAK_PARAGRAPH))
				$this->outputParagraphStart($tagPos);

			$colonPos = \strpos($tagName, ':');
			if ($colonPos)
				$this->namespaces[\substr($tagName, 0, $colonPos)] = 0;

			$this->output .= '<' . $tagName;

			$attributes = $tag->getAttributes();
			\ksort($attributes);

			foreach ($attributes as $attrName => $attrValue)
				$this->output .= ' ' . $attrName . '="' . \htmlspecialchars($attrValue, \ENT_COMPAT, 'UTF-8') . '"';

			if ($tag->isSelfClosingTag())
				if ($tagLen)
					$this->output .= '>' . $tagText . '</' . $tagName . '>';
				else
					$this->output .= '/>';
			elseif ($tagLen)
				$this->output .= '><s>' . $tagText . '</s>';
			else
				$this->output .= '>';
		}
		else
		{
			if ($tagLen)
				$this->output .= '<e>' . $tagText . '</e>';

			$this->output .= '</' . $tagName . '>';
		}

		$this->pos = $tagPos + $tagLen;

		$this->wsPos = $this->pos;
		while ($skipAfter && $this->wsPos < $this->textLen && $this->text[$this->wsPos] === "\n")
		{
			--$skipAfter;

			++$this->wsPos;
		}
	}

	protected function outputText($catchupPos, $maxLines, $closeParagraph)
	{
		if ($closeParagraph)
			if (!($this->context['flags'] & self::RULE_CREATE_PARAGRAPHS))
				$closeParagraph = \false;
			else
				$maxLines = -1;

		if ($this->pos >= $catchupPos)
		{
			if ($closeParagraph)
				$this->outputParagraphEnd();

			return;
		}

		if ($this->wsPos > $this->pos)
		{
			$skipPos       = \min($catchupPos, $this->wsPos);
			$this->output .= \substr($this->text, $this->pos, $skipPos - $this->pos);
			$this->pos     = $skipPos;

			if ($this->pos >= $catchupPos)
			{
				if ($closeParagraph)
					$this->outputParagraphEnd();

				return;
			}
		}

		if ($this->context['flags'] & self::RULE_IGNORE_TEXT)
		{
			$catchupLen  = $catchupPos - $this->pos;
			$catchupText = \substr($this->text, $this->pos, $catchupLen);

			if (\strspn($catchupText, " \n\t") < $catchupLen)
				$catchupText = '<i>' . $catchupText . '</i>';

			$this->output .= $catchupText;
			$this->pos = $catchupPos;

			if ($closeParagraph)
				$this->outputParagraphEnd();

			return;
		}

		$ignorePos = $catchupPos;
		$ignoreLen = 0;

		while ($maxLines && --$ignorePos >= $this->pos)
		{
			$c = $this->text[$ignorePos];
			if (\strpos(self::WHITESPACE, $c) === \false)
				break;

			if ($c === "\n")
				--$maxLines;

			++$ignoreLen;
		}

		$catchupPos -= $ignoreLen;

		if ($this->context['flags'] & self::RULE_CREATE_PARAGRAPHS)
		{
			if (!$this->context['inParagraph'])
			{
				$this->outputWhitespace($catchupPos);

				if ($catchupPos > $this->pos)
					$this->outputParagraphStart($catchupPos);
			}

			$pbPos = \strpos($this->text, "\n\n", $this->pos);

			while ($pbPos !== \false && $pbPos < $catchupPos)
			{
				$this->outputText($pbPos, 0, \true);
				$this->outputParagraphStart($catchupPos);

				$pbPos = \strpos($this->text, "\n\n", $this->pos);
			}
		}

		if ($catchupPos > $this->pos)
		{
			$catchupText = \htmlspecialchars(
				\substr($this->text, $this->pos, $catchupPos - $this->pos),
				\ENT_NOQUOTES,
				'UTF-8'
			);

			if (($this->context['flags'] & self::RULES_AUTO_LINEBREAKS) === self::RULE_ENABLE_AUTO_BR)
				$catchupText = \str_replace("\n", "<br/>\n", $catchupText);

			$this->output .= $catchupText;
		}

		if ($closeParagraph)
			$this->outputParagraphEnd();

		if ($ignoreLen)
			$this->output .= \substr($this->text, $catchupPos, $ignoreLen);

		$this->pos = $catchupPos + $ignoreLen;
	}

	protected function outputBrTag(Tag $tag)
	{
		$this->outputText($tag->getPos(), 0, \false);
		$this->output .= '<br/>';
	}

	protected function outputIgnoreTag(Tag $tag)
	{
		$tagPos = $tag->getPos();
		$tagLen = $tag->getLen();

		$ignoreText = \substr($this->text, $tagPos, $tagLen);

		$this->outputText($tagPos, 0, \false);
		$this->output .= '<i>' . \htmlspecialchars($ignoreText, \ENT_NOQUOTES, 'UTF-8') . '</i>';
		$this->isRich = \true;

		$this->pos = $tagPos + $tagLen;
	}

	protected function outputParagraphStart($maxPos)
	{
		if ($this->context['inParagraph']
		 || !($this->context['flags'] & self::RULE_CREATE_PARAGRAPHS))
			return;

		$this->outputWhitespace($maxPos);

		if ($this->pos < $this->textLen)
		{
			$this->output .= '<p>';
			$this->context['inParagraph'] = \true;
		}
	}

	protected function outputParagraphEnd()
	{
		if (!$this->context['inParagraph'])
			return;

		$this->output .= '</p>';
		$this->context['inParagraph'] = \false;
	}

	protected function outputWhitespace($maxPos)
	{
		if ($maxPos > $this->pos)
		{
			$spn = \strspn($this->text, self::WHITESPACE, $this->pos, $maxPos - $this->pos);

			if ($spn)
			{
				$this->output .= \substr($this->text, $this->pos, $spn);
				$this->pos += $spn;
			}
		}
	}

	public function disablePlugin($pluginName)
	{
		if (isset($this->pluginsConfig[$pluginName]))
		{
			$pluginConfig = $this->pluginsConfig[$pluginName];
			unset($this->pluginsConfig[$pluginName]);

			$pluginConfig['isDisabled'] = \true;
			$this->pluginsConfig[$pluginName] = $pluginConfig;
		}
	}

	public function enablePlugin($pluginName)
	{
		if (isset($this->pluginsConfig[$pluginName]))
			$this->pluginsConfig[$pluginName]['isDisabled'] = \false;
	}

	protected function executePluginParsers()
	{
		foreach ($this->pluginsConfig as $pluginName => $pluginConfig)
		{
			if (!empty($pluginConfig['isDisabled']))
				continue;

			if (isset($pluginConfig['quickMatch'])
			 && \strpos($this->text, $pluginConfig['quickMatch']) === \false)
				continue;

			$matches = array();

			if (isset($pluginConfig['regexp']))
			{
				$cnt = \preg_match_all(
					$pluginConfig['regexp'],
					$this->text,
					$matches,
					\PREG_SET_ORDER | \PREG_OFFSET_CAPTURE
				);

				if (!$cnt)
					continue;

				if ($cnt > $pluginConfig['regexpLimit'])
				{
					if ($pluginConfig['regexpLimitAction'] === 'abort')
						throw new RuntimeException($pluginName . ' limit exceeded');

					$matches = \array_slice($matches, 0, $pluginConfig['regexpLimit']);

					$msg = 'Regexp limit exceeded. Only the allowed number of matches will be processed';
					$context = array(
						'pluginName' => $pluginName,
						'limit'      => $pluginConfig['regexpLimit']
					);

					if ($pluginConfig['regexpLimitAction'] === 'warn')
						$this->logger->warn($msg, $context);
				}
			}

			if (!isset($this->pluginParsers[$pluginName]))
			{
				$className = (isset($pluginConfig['className']))
				           ? $pluginConfig['className']
				           : 's9e\\TextFormatter\\Plugins\\' . $pluginName . '\\Parser';

				$this->pluginParsers[$pluginName] = array(
					new $className($this, $pluginConfig),
					'parse'
				);
			}

			\call_user_func($this->pluginParsers[$pluginName], $this->text, $matches);
		}
	}

	public function registerParser($pluginName, $parser)
	{
		if (!\is_callable($parser))
			throw new InvalidArgumentException('Argument 1 passed to ' . __METHOD__ . ' must be a valid callback');

		if (!isset($this->pluginsConfig[$pluginName]))
			$this->pluginsConfig[$pluginName] = array();

		$this->pluginParsers[$pluginName] = $parser;
	}

	protected function closeAncestor(Tag $tag)
	{
		if (!empty($this->openTags))
		{
			$tagName   = $tag->getName();
			$tagConfig = $this->tagsConfig[$tagName];

			if (!empty($tagConfig['rules']['closeAncestor']))
			{
				$i = \count($this->openTags);

				while (--$i >= 0)
				{
					$ancestor     = $this->openTags[$i];
					$ancestorName = $ancestor->getName();

					if (isset($tagConfig['rules']['closeAncestor'][$ancestorName]))
					{
						$this->tagStack[] = $tag;

						$this->addMagicEndTag($ancestor, $tag->getPos());

						return \true;
					}
				}
			}
		}

		return \false;
	}

	protected function closeParent(Tag $tag)
	{
		if (!empty($this->openTags))
		{
			$tagName   = $tag->getName();
			$tagConfig = $this->tagsConfig[$tagName];

			if (!empty($tagConfig['rules']['closeParent']))
			{
				$parent     = \end($this->openTags);
				$parentName = $parent->getName();

				if (isset($tagConfig['rules']['closeParent'][$parentName]))
				{
					$this->tagStack[] = $tag;

					$this->addMagicEndTag($parent, $tag->getPos());

					return \true;
				}
			}
		}

		return \false;
	}

	protected function fosterParent(Tag $tag)
	{
		if (!empty($this->openTags))
		{
			$tagName   = $tag->getName();
			$tagConfig = $this->tagsConfig[$tagName];

			if (!empty($tagConfig['rules']['fosterParent']))
			{
				$parent     = \end($this->openTags);
				$parentName = $parent->getName();

				if (isset($tagConfig['rules']['fosterParent'][$parentName]))
				{
					if ($parentName !== $tagName && $this->currentFixingCost < $this->maxFixingCost)
					{
						$child = $this->addCopyTag($parent, $tag->getPos() + $tag->getLen(), 0);
						$tag->cascadeInvalidationTo($child);
					}

					++$this->currentFixingCost;

					$this->tagStack[] = $tag;

					$this->addMagicEndTag($parent, $tag->getPos());

					return \true;
				}
			}
		}

		return \false;
	}

	protected function requireAncestor(Tag $tag)
	{
		$tagName   = $tag->getName();
		$tagConfig = $this->tagsConfig[$tagName];

		if (isset($tagConfig['rules']['requireAncestor']))
		{
			foreach ($tagConfig['rules']['requireAncestor'] as $ancestorName)
				if (!empty($this->cntOpen[$ancestorName]))
					return \false;

			$this->logger->err('Tag requires an ancestor', array(
				'requireAncestor' => \implode(',', $tagConfig['rules']['requireAncestor']),
				'tag'             => $tag
			));

			return \true;
		}

		return \false;
	}

	protected function addMagicEndTag(Tag $startTag, $tagPos)
	{
		$tagName = $startTag->getName();

		if ($startTag->getFlags() & self::RULE_TRIM_WHITESPACE)
			$tagPos = $this->getMagicPos($tagPos);

		$this->addEndTag($tagName, $tagPos, 0)->pairWith($startTag);
	}

	protected function getMagicPos($tagPos)
	{
		while ($tagPos > $this->pos && \strpos(self::WHITESPACE, $this->text[$tagPos - 1]) !== \false)
			--$tagPos;

		return $tagPos;
	}

	protected function processTags()
	{
		$this->pos       = 0;
		$this->cntOpen   = array();
		$this->cntTotal  = array();
		$this->openTags  = array();
		unset($this->currentTag);

		$this->context = $this->rootContext;
		$this->context['inParagraph'] = \false;

		foreach (\array_keys($this->tagsConfig) as $tagName)
		{
			$this->cntOpen[$tagName]  = 0;
			$this->cntTotal[$tagName] = 0;
		}

		do
		{
			while (!empty($this->tagStack))
			{
				if (!$this->tagStackIsSorted)
					$this->sortTags();

				$this->currentTag = \array_pop($this->tagStack);

				if ($this->context['flags'] & self::RULE_IGNORE_TAGS)
					if (!$this->currentTag->canClose(\end($this->openTags))
					 && !$this->currentTag->isSystemTag())
						continue;

				$this->processCurrentTag();
			}

			foreach ($this->openTags as $startTag)
				$this->addMagicEndTag($startTag, $this->textLen);
		}
		while (!empty($this->tagStack));

		$this->finalizeOutput();
	}

	protected function processCurrentTag()
	{
		if ($this->currentTag->isInvalid())
			return;

		$tagPos = $this->currentTag->getPos();
		$tagLen = $this->currentTag->getLen();

		if ($this->pos > $tagPos)
		{
			$startTag = $this->currentTag->getStartTag();

			if ($startTag && \in_array($startTag, $this->openTags, \true))
			{
				$this->addEndTag(
					$startTag->getName(),
					$this->pos,
					\max(0, $tagPos + $tagLen - $this->pos)
				)->pairWith($startTag);

				return;
			}

			if ($this->currentTag->isIgnoreTag())
			{
				$ignoreLen = $tagPos + $tagLen - $this->pos;

				if ($ignoreLen > 0)
				{
					$this->addIgnoreTag($this->pos, $ignoreLen);

					return;
				}
			}

			$this->currentTag->invalidate();

			return;
		}

		if ($this->currentTag->isIgnoreTag())
			$this->outputIgnoreTag($this->currentTag);
		elseif ($this->currentTag->isBrTag())
		{
			if (!($this->context['flags'] & self::RULE_PREVENT_BR))
				$this->outputBrTag($this->currentTag);
		}
		elseif ($this->currentTag->isParagraphBreak())
			$this->outputText($this->currentTag->getPos(), 0, \true);
		elseif ($this->currentTag->isStartTag())
			$this->processStartTag($this->currentTag);
		else
			$this->processEndTag($this->currentTag);
	}

	protected function processStartTag(Tag $tag)
	{
		$tagName   = $tag->getName();
		$tagConfig = $this->tagsConfig[$tagName];

		if ($this->cntTotal[$tagName] >= $tagConfig['tagLimit'])
		{
			$this->logger->err(
				'Tag limit exceeded',
				array(
					'tag'      => $tag,
					'tagName'  => $tagName,
					'tagLimit' => $tagConfig['tagLimit']
				)
			);
			$tag->invalidate();

			return;
		}

		if (!$this->filterTag($tag))
		{
			$tag->invalidate();

			return;
		}

		if ($this->fosterParent($tag) || $this->closeParent($tag) || $this->closeAncestor($tag))
			return;

		if ($this->cntOpen[$tagName] >= $tagConfig['nestingLimit'])
		{
			$this->logger->err(
				'Nesting limit exceeded',
				array(
					'tag'          => $tag,
					'tagName'      => $tagName,
					'nestingLimit' => $tagConfig['nestingLimit']
				)
			);
			$tag->invalidate();

			return;
		}

		if (!$this->tagIsAllowed($tagName))
		{
			$this->logger->warn(
				'Tag is not allowed in this context',
				array(
					'tag'     => $tag,
					'tagName' => $tagName
				)
			);
			$tag->invalidate();

			return;
		}

		if ($this->requireAncestor($tag))
		{
			$tag->invalidate();

			return;
		}

		if ($tag->getFlags() & self::RULE_AUTO_CLOSE
		 && !$tag->getEndTag())
		{
			$newTag = new Tag(Tag::SELF_CLOSING_TAG, $tagName, $tag->getPos(), $tag->getLen());
			$newTag->setAttributes($tag->getAttributes());
			$newTag->setFlags($tag->getFlags());

			$tag = $newTag;
		}

		$this->outputTag($tag);
		$this->pushContext($tag);
	}

	protected function processEndTag(Tag $tag)
	{
		$tagName = $tag->getName();

		if (empty($this->cntOpen[$tagName]))
			return;

		$closeTags = array();

		$i = \count($this->openTags);
		while (--$i >= 0)
		{
			$openTag = $this->openTags[$i];

			if ($tag->canClose($openTag))
				break;

			if (++$this->currentFixingCost > $this->maxFixingCost)
				throw new RuntimeException('Fixing cost exceeded');

			$closeTags[] = $openTag;
		}

		if ($i < 0)
		{
			$this->logger->debug('Skipping end tag with no start tag', array('tag' => $tag));

			return;
		}

		$keepReopening = (bool) ($this->currentFixingCost < $this->maxFixingCost);

		$reopenTags = array();
		foreach ($closeTags as $openTag)
		{
			$openTagName = $openTag->getName();

			if ($keepReopening)
				if ($openTag->getFlags() & self::RULE_AUTO_REOPEN)
					$reopenTags[] = $openTag;
				else
					$keepReopening = \false;

			$tagPos = $tag->getPos();
			if ($openTag->getFlags() & self::RULE_TRIM_WHITESPACE)
				$tagPos = $this->getMagicPos($tagPos);

			$endTag = new Tag(Tag::END_TAG, $openTagName, $tagPos, 0);
			$endTag->setFlags($openTag->getFlags());
			$this->outputTag($endTag);
			$this->popContext();
		}

		$this->outputTag($tag);
		$this->popContext();

		if ($closeTags && $this->currentFixingCost < $this->maxFixingCost)
		{
			$ignorePos = $this->pos;

			$i = \count($this->tagStack);
			while (--$i >= 0 && ++$this->currentFixingCost < $this->maxFixingCost)
			{
				$upcomingTag = $this->tagStack[$i];

				if ($upcomingTag->getPos() > $ignorePos
				 || $upcomingTag->isStartTag())
					break;

				$j = \count($closeTags);

				while (--$j >= 0 && ++$this->currentFixingCost < $this->maxFixingCost)
					if ($upcomingTag->canClose($closeTags[$j]))
					{
						\array_splice($closeTags, $j, 1);

						if (isset($reopenTags[$j]))
							\array_splice($reopenTags, $j, 1);

						$ignorePos = \max(
							$ignorePos,
							$upcomingTag->getPos() + $upcomingTag->getLen()
						);

						break;
					}
			}

			if ($ignorePos > $this->pos)
				$this->outputIgnoreTag(new Tag(Tag::SELF_CLOSING_TAG, 'i', $this->pos, $ignorePos - $this->pos));
		}

		foreach ($reopenTags as $startTag)
		{
			$newTag = $this->addCopyTag($startTag, $this->pos, 0);

			$endTag = $startTag->getEndTag();
			if ($endTag)
				$newTag->pairWith($endTag);
		}
	}

	protected function popContext()
	{
		$tag = \array_pop($this->openTags);
		--$this->cntOpen[$tag->getName()];
		$this->context = $this->context['parentContext'];
	}

	protected function pushContext(Tag $tag)
	{
		$tagName   = $tag->getName();
		$tagFlags  = $tag->getFlags();
		$tagConfig = $this->tagsConfig[$tagName];

		++$this->cntTotal[$tagName];

		if ($tag->isSelfClosingTag())
			return;

		++$this->cntOpen[$tagName];
		$this->openTags[] = $tag;

		$allowedChildren = $tagConfig['allowedChildren'];

		if ($tagFlags & self::RULE_IS_TRANSPARENT)
			$allowedChildren &= $this->context['allowedChildren'];

		$allowedDescendants = $this->context['allowedDescendants']
		                    & $tagConfig['allowedDescendants'];

		$allowedChildren &= $allowedDescendants;

		$flags = $tagFlags;

		$flags |= $this->context['flags'] & self::RULES_INHERITANCE;

		if ($flags & self::RULE_DISABLE_AUTO_BR)
			$flags &= ~self::RULE_ENABLE_AUTO_BR;

		$this->context = array(
			'allowedChildren'    => $allowedChildren,
			'allowedDescendants' => $allowedDescendants,
			'flags'              => $flags,
			'inParagraph'        => \false,
			'parentContext'      => $this->context
		);
	}

	protected function tagIsAllowed($tagName)
	{
		$n = $this->tagsConfig[$tagName]['bitNumber'];

		return (bool) (\ord($this->context['allowedChildren'][$n >> 3]) & (1 << ($n & 7)));
	}

	public function addStartTag($name, $pos, $len)
	{
		return $this->addTag(Tag::START_TAG, $name, $pos, $len);
	}

	public function addEndTag($name, $pos, $len)
	{
		return $this->addTag(Tag::END_TAG, $name, $pos, $len);
	}

	public function addSelfClosingTag($name, $pos, $len)
	{
		return $this->addTag(Tag::SELF_CLOSING_TAG, $name, $pos, $len);
	}

	public function addBrTag($pos)
	{
		return $this->addTag(Tag::SELF_CLOSING_TAG, 'br', $pos, 0);
	}

	public function addIgnoreTag($pos, $len)
	{
		return $this->addTag(Tag::SELF_CLOSING_TAG, 'i', $pos, $len);
	}

	public function addParagraphBreak($pos)
	{
		return $this->addTag(Tag::SELF_CLOSING_TAG, 'pb', $pos, 0);
	}

	public function addCopyTag(Tag $tag, $pos, $len)
	{
		$copy = $this->addTag($tag->getType(), $tag->getName(), $pos, $len);
		$copy->setAttributes($tag->getAttributes());
		$copy->setSortPriority($tag->getSortPriority());

		return $copy;
	}

	protected function addTag($type, $name, $pos, $len)
	{
		$tag = new Tag($type, $name, $pos, $len);

		if (isset($this->tagsConfig[$name]))
			$tag->setFlags($this->tagsConfig[$name]['rules']['flags']);

		if (!isset($this->tagsConfig[$name]) && !$tag->isSystemTag())
			$tag->invalidate();
		elseif (!empty($this->tagsConfig[$name]['isDisabled']))
		{
			$this->logger->warn(
				'Tag is disabled',
				array(
					'tag'     => $tag,
					'tagName' => $name
				)
			);
			$tag->invalidate();
		}
		elseif ($len < 0 || $pos < 0 || $pos + $len > $this->textLen)
			$tag->invalidate();
		else
		{
			if ($this->tagStackIsSorted
			 && !empty($this->tagStack)
			 && $tag->getPos() >= \end($this->tagStack)->getPos())
				$this->tagStackIsSorted = \false;

			$this->tagStack[] = $tag;
		}

		return $tag;
	}

	public function addTagPair($name, $startPos, $startLen, $endPos, $endLen)
	{
		$tag = $this->addStartTag($name, $startPos, $startLen);
		$tag->pairWith($this->addEndTag($name, $endPos, $endLen));

		return $tag;
	}

	protected function sortTags()
	{
		\usort($this->tagStack, __CLASS__ . '::compareTags');
		$this->tagStackIsSorted = \true;
	}

	static protected function compareTags(Tag $a, Tag $b)
	{
		$aPos = $a->getPos();
		$bPos = $b->getPos();

		if ($aPos !== $bPos)
			return $bPos - $aPos;

		if ($a->getSortPriority() !== $b->getSortPriority())
			return $b->getSortPriority() - $a->getSortPriority();

		$aLen = $a->getLen();
		$bLen = $b->getLen();

		if (!$aLen || !$bLen)
		{
			if (!$aLen && !$bLen)
			{
				$order = array(
					Tag::END_TAG          => 0,
					Tag::SELF_CLOSING_TAG => 1,
					Tag::START_TAG        => 2
				);

				return $order[$b->getType()] - $order[$a->getType()];
			}

			return ($aLen) ? -1 : 1;
		}

		return $aLen - $bLen;
	}
}

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2015 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Parser;

class BuiltInFilters
{
	public static function filterAlnum($attrValue)
	{
		return \filter_var($attrValue, \FILTER_VALIDATE_REGEXP, array(
			'options' => array('regexp' => '/^[0-9A-Za-z]+$/D')
		));
	}

	public static function filterColor($attrValue)
	{
		return \filter_var($attrValue, \FILTER_VALIDATE_REGEXP, array(
			'options' => array(
				'regexp' => '/^(?>#[0-9a-f]{3,6}|rgb\\(\\d{1,3}, *\\d{1,3}, *\\d{1,3}\\)|[a-z]+)$/Di'
			)
		));
	}

	public static function filterEmail($attrValue)
	{
		return \filter_var($attrValue, \FILTER_VALIDATE_EMAIL);
	}

	public static function filterFloat($attrValue)
	{
		return \filter_var($attrValue, \FILTER_VALIDATE_FLOAT);
	}

	public static function filterHashmap($attrValue, array $map, $strict)
	{
		if (isset($map[$attrValue]))
			return $map[$attrValue];

		return ($strict) ? \false : $attrValue;
	}

	public static function filterIdentifier($attrValue)
	{
		return \filter_var($attrValue, \FILTER_VALIDATE_REGEXP, array(
			'options' => array('regexp' => '/^[-0-9A-Za-z_]+$/D')
		));
	}

	public static function filterInt($attrValue)
	{
		return \filter_var($attrValue, \FILTER_VALIDATE_INT);
	}

	public static function filterIp($attrValue)
	{
		return \filter_var($attrValue, \FILTER_VALIDATE_IP);
	}

	public static function filterIpport($attrValue)
	{
		if (\preg_match('/^\\[([^\\]]+)(\\]:[1-9][0-9]*)$/D', $attrValue, $m))
		{
			$ip = self::filterIpv6($m[1]);

			if ($ip === \false)
				return \false;

			return '[' . $ip . $m[2];
		}

		if (\preg_match('/^([^:]+)(:[1-9][0-9]*)$/D', $attrValue, $m))
		{
			$ip = self::filterIpv4($m[1]);

			if ($ip === \false)
				return \false;

			return $ip . $m[2];
		}

		return \false;
	}

	public static function filterIpv4($attrValue)
	{
		return \filter_var($attrValue, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4);
	}

	public static function filterIpv6($attrValue)
	{
		return \filter_var($attrValue, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6);
	}

	public static function filterMap($attrValue, array $map)
	{
		foreach ($map as $pair)
			if (\preg_match($pair[0], $attrValue))
				return $pair[1];

		return $attrValue;
	}

	public static function filterNumber($attrValue)
	{
		return \filter_var($attrValue, \FILTER_VALIDATE_REGEXP, array(
			'options' => array('regexp' => '/^[0-9]+$/D')
		));
	}

	public static function filterRange($attrValue, $min, $max, Logger $logger = \null)
	{
		$attrValue = \filter_var($attrValue, \FILTER_VALIDATE_INT);

		if ($attrValue === \false)
			return \false;

		if ($attrValue < $min)
		{
			if (isset($logger))
				$logger->warn(
					'Value outside of range, adjusted up to min value',
					array(
						'attrValue' => $attrValue,
						'min'       => $min,
						'max'       => $max
					)
				);

			return $min;
		}

		if ($attrValue > $max)
		{
			if (isset($logger))
				$logger->warn(
					'Value outside of range, adjusted down to max value',
					array(
						'attrValue' => $attrValue,
						'min'       => $min,
						'max'       => $max
					)
				);

			return $max;
		}

		return $attrValue;
	}

	public static function filterRegexp($attrValue, $regexp)
	{
		return \filter_var($attrValue, \FILTER_VALIDATE_REGEXP, array(
			'options' => array('regexp' => $regexp)
		));
	}

	public static function filterSimpletext($attrValue)
	{
		return \filter_var($attrValue, \FILTER_VALIDATE_REGEXP, array(
			'options' => array('regexp' => '/^[- +,.0-9A-Za-z_]+$/D')
		));
	}

	public static function filterUint($attrValue)
	{
		return \filter_var($attrValue, \FILTER_VALIDATE_INT, array(
			'options' => array('min_range' => 0)
		));
	}

	public static function filterUrl($attrValue, array $urlConfig, Logger $logger = \null)
	{
		$p = self::parseUrl(\trim($attrValue));

		$url = '';

		if ($p['scheme'] !== '')
		{
			if (!\preg_match($urlConfig['allowedSchemes'], $p['scheme']))
			{
				if (isset($logger))
					$logger->err(
						'URL scheme is not allowed',
						array('attrValue' => $attrValue, 'scheme' => $p['scheme'])
					);

				return \false;
			}

			$url .= $p['scheme'] . ':';
		}

		if ($p['host'] === '')
		{
			if ($p['scheme'] === 'file')
				$url .= '//';
			elseif ($p['scheme'] !== '')
				return \false;
		}
		else
		{
			$url .= '//';

			$regexp = '/^(?=[a-z])[-a-z0-9]{0,62}[a-z0-9](?:\\.(?=[a-z])[-a-z0-9]{0,62}[a-z0-9])*$/i';
			if (!\preg_match($regexp, $p['host']))
				if (!self::filterIpv4($p['host'])
				 && !self::filterIpv6(\preg_replace('/^\\[(.*)\\]$/', '$1', $p['host'])))
				{
					if (isset($logger))
						$logger->err(
							'URL host is invalid',
							array('attrValue' => $attrValue, 'host' => $p['host'])
						);

					return \false;
				}

			if ((isset($urlConfig['disallowedHosts']) && \preg_match($urlConfig['disallowedHosts'], $p['host']))
			 || (isset($urlConfig['restrictedHosts']) && !\preg_match($urlConfig['restrictedHosts'], $p['host'])))
			{
				if (isset($logger))
					$logger->err(
						'URL host is not allowed',
						array('attrValue' => $attrValue, 'host' => $p['host'])
					);

				return \false;
			}

			if ($p['user'] !== '')
			{
				$url .= \rawurlencode(\urldecode($p['user']));

				if ($p['pass'] !== '')
					$url .= ':' . \rawurlencode(\urldecode($p['pass']));

				$url .= '@';
			}

			$url .= $p['host'];

			if ($p['port'] !== '')
				$url .= ':' . $p['port'];
		}

		$path = $p['path'];
		if ($p['query'] !== '')
			$path .= '?' . $p['query'];
		if ($p['fragment'] !== '')
			$path .= '#' . $p['fragment'];

		$path = \preg_replace_callback(
			'/%.?[a-f]/',
			function ($m)
			{
				return \strtoupper($m[0]);
			},
			$path
		);

		$url .= self::sanitizeUrl($path);

		if (!$p['scheme'])
			$url = \preg_replace('#^([^/]*):#', '$1%3A', $url);

		return $url;
	}

	public static function parseUrl($url)
	{
		$regexp = '(^(?:([a-z][-+.\\w]*):)?(?://(?:([^:/?#]*)(?::([^/?#]*)?)?@)?(?:(\\[[a-f\\d:]+\\]|[^:/?#]+)(?::(\\d*))?)?(?![^/?#]))?([^?#]*)(?:\\?([^#]*))?(?:#(.*))?$)Di';

		\preg_match($regexp, $url, $m);

		$parts = array(
			'scheme'   => (isset($m[1])) ? $m[1] : '',
			'user'     => (isset($m[2])) ? $m[2] : '',
			'pass'     => (isset($m[3])) ? $m[3] : '',
			'host'     => (isset($m[4])) ? $m[4] : '',
			'port'     => (isset($m[5])) ? $m[5] : '',
			'path'     => (isset($m[6])) ? $m[6] : '',
			'query'    => (isset($m[7])) ? $m[7] : '',
			'fragment' => (isset($m[8])) ? $m[8] : ''
		);

		$parts['scheme'] = \strtolower($parts['scheme']);

		$parts['host'] = \rtrim(\preg_replace("/\xE3\x80\x82|\xEF(?:\xBC\x8E|\xBD\xA1)/s", '.', $parts['host']), '.');

		if (\preg_match('#[^[:ascii:]]#', $parts['host']) && \function_exists('idn_to_ascii'))
			$parts['host'] = \idn_to_ascii($parts['host']);

		return $parts;
	}

	public static function sanitizeUrl($url)
	{
		return \preg_replace_callback(
			'/%(?![0-9A-Fa-f]{2})|[^!#-&*-;=?-Z_a-z]/S',
			function ($m)
			{
				return \rawurlencode($m[0]);
			},
			$url
		);
	}
}

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2015 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Parser;

use InvalidArgumentException;
use s9e\TextFormatter\Parser;

class Logger
{
	protected $attrName;

	protected $callbacks = array();

	protected $logs = array();

	protected $tag;

	protected function add($type, $msg, array $context)
	{
		if (!isset($context['attrName']) && isset($this->attrName))
			$context['attrName'] = $this->attrName;

		if (!isset($context['tag']) && isset($this->tag))
			$context['tag'] = $this->tag;

		if (isset($this->callbacks[$type]))
			foreach ($this->callbacks[$type] as $callback)
				\call_user_func_array($callback, array(&$msg, &$context));

		$this->logs[] = array($type, $msg, $context);
	}

	public function clear()
	{
		$this->logs = array();
		$this->unsetAttribute();
		$this->unsetTag();
	}

	public function get()
	{
		return $this->logs;
	}

	public function on($type, $callback)
	{
		if (!\is_callable($callback))
			throw new InvalidArgumentException('on() expects a valid callback');

		$this->callbacks[$type][] = $callback;
	}

	public function setAttribute($attrName)
	{
		$this->attrName = $attrName;
	}

	public function setTag(Tag $tag)
	{
		$this->tag = $tag;
	}

	public function unsetAttribute()
	{
		unset($this->attrName);
	}

	public function unsetTag()
	{
		unset($this->tag);
	}

	public function debug($msg, array $context = array())
	{
		$this->add('debug', $msg, $context);
	}

	public function err($msg, array $context = array())
	{
		$this->add('err', $msg, $context);
	}

	public function info($msg, array $context = array())
	{
		$this->add('info', $msg, $context);
	}

	public function warn($msg, array $context = array())
	{
		$this->add('warn', $msg, $context);
	}
}

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2015 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Parser;

class Tag
{
	const START_TAG = 1;

	const END_TAG = 2;

	const SELF_CLOSING_TAG = 3;

	protected $attributes = array();

	protected $cascade = array();

	protected $endTag = \null;

	protected $flags = 0;

	protected $invalid = \false;

	protected $len;

	protected $name;

	protected $pos;

	protected $sortPriority = 0;

	protected $startTag = \null;

	protected $type;

	public function __construct($type, $name, $pos, $len)
	{
		$this->type = (int) $type;
		$this->name = $name;
		$this->pos  = (int) $pos;
		$this->len  = (int) $len;
	}

	public function addFlags($flags)
	{
		$this->flags |= $flags;
	}

	public function cascadeInvalidationTo(Tag $tag)
	{
		$this->cascade[] = $tag;

		if ($this->invalid)
			$tag->invalidate();
	}

	public function invalidate()
	{
		if ($this->invalid)
			return;

		$this->invalid = \true;

		foreach ($this->cascade as $tag)
			$tag->invalidate();
	}

	public function pairWith(Tag $tag)
	{
		if ($this->name === $tag->name)
			if ($this->type === self::START_TAG
			 && $tag->type  === self::END_TAG
			 && $tag->pos   >=  $this->pos)
			{
				$this->endTag  = $tag;
				$tag->startTag = $this;
			}
			elseif ($this->type === self::END_TAG
			     && $tag->type  === self::START_TAG
			     && $tag->pos   <=  $this->pos)
			{
				$this->startTag = $tag;
				$tag->endTag    = $this;
			}
	}

	public function removeFlags($flags)
	{
		$this->flags &= ~$flags;
	}

	public function setFlags($flags)
	{
		$this->flags = $flags;
	}

	public function setSortPriority($sortPriority)
	{
		$this->sortPriority = $sortPriority;
	}

	public function getAttributes()
	{
		return $this->attributes;
	}

	public function getEndTag()
	{
		return $this->endTag;
	}

	public function getFlags()
	{
		return $this->flags;
	}

	public function getLen()
	{
		return $this->len;
	}

	public function getName()
	{
		return $this->name;
	}

	public function getPos()
	{
		return $this->pos;
	}

	public function getSortPriority()
	{
		return $this->sortPriority;
	}

	public function getStartTag()
	{
		return $this->startTag;
	}

	public function getType()
	{
		return $this->type;
	}

	public function canClose(Tag $startTag)
	{
		if ($this->invalid
		 || $this->name !== $startTag->name
		 || $startTag->type !== self::START_TAG
		 || $this->type !== self::END_TAG
		 || $this->pos < $startTag->pos
		 || ($this->startTag && $this->startTag !== $startTag)
		 || ($startTag->endTag && $startTag->endTag !== $this))
			return \false;

		return \true;
	}

	public function isBrTag()
	{
		return ($this->name === 'br');
	}

	public function isEndTag()
	{
		return (bool) ($this->type & self::END_TAG);
	}

	public function isIgnoreTag()
	{
		return ($this->name === 'i');
	}

	public function isInvalid()
	{
		return $this->invalid;
	}

	public function isParagraphBreak()
	{
		return ($this->name === 'pb');
	}

	public function isSelfClosingTag()
	{
		return ($this->type === self::SELF_CLOSING_TAG);
	}

	public function isSystemTag()
	{
		return ($this->name === 'br' || $this->name === 'i' || $this->name === 'pb');
	}

	public function isStartTag()
	{
		return (bool) ($this->type & self::START_TAG);
	}

	public function getAttribute($attrName)
	{
		return $this->attributes[$attrName];
	}

	public function hasAttribute($attrName)
	{
		return isset($this->attributes[$attrName]);
	}

	public function removeAttribute($attrName)
	{
		unset($this->attributes[$attrName]);
	}

	public function setAttribute($attrName, $attrValue)
	{
		$this->attributes[$attrName] = $attrValue;
	}

	public function setAttributes(array $attributes)
	{
		$this->attributes = $attributes;
	}
}

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2015 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Plugins\MediaEmbed;

use s9e\TextFormatter\Parser as TagStack;
use s9e\TextFormatter\Parser\Tag;
use s9e\TextFormatter\Plugins\ParserBase;

class Parser extends ParserBase
{
	public function parse($text, array $matches)
	{
		foreach ($matches as $m)
		{
			$url = $m[0][0];
			$pos = $m[0][1];
			$len = \strlen($url);

			$tag = $this->parser->addSelfClosingTag('MEDIA', $pos, $len);
			$tag->setAttribute('url', $url);

			$tag->setSortPriority(-10);
		}
	}

	public static function filterTag(Tag $tag, TagStack $tagStack, array $sites)
	{
		if ($tag->hasAttribute('media'))
		{
			$tagName = $tag->getAttribute('media');

			if (!$tag->hasAttribute('id')
			 && $tag->hasAttribute('url')
			 && \strpos($tag->getAttribute('url'), '://') === \false)
				$tag->setAttribute('id', $tag->getAttribute('url'));
		}
		elseif ($tag->hasAttribute('url'))
		{
			$p = \parse_url($tag->getAttribute('url'));

			if (isset($p['scheme']) && isset($sites[$p['scheme'] . ':']))
				$tagName = $sites[$p['scheme'] . ':'];
			elseif (isset($p['host']))
			{
				$host = $p['host'];

				do
				{
					if (isset($sites[$host]))
					{
						$tagName = $sites[$host];
						break;
					}

					$pos = \strpos($host, '.');
					if ($pos === \false)
						break;

					$host = \substr($host, 1 + $pos);
				}
				while ($host > '');
			}
		}

		if (isset($tagName))
		{
			$endTag = $tag->getEndTag() ?: $tag;

			$lpos = $tag->getPos();
			$rpos = $endTag->getPos() + $endTag->getLen();

			$newTag = $tagStack->addSelfClosingTag(\strtoupper($tagName), $lpos, $rpos - $lpos);
			$newTag->setAttributes($tag->getAttributes());
			$newTag->setSortPriority($tag->getSortPriority());
		}

		return \false;
	}

	public static function hasNonDefaultAttribute(Tag $tag)
	{
		foreach ($tag->getAttributes() as $attrName => $void)
			if ($attrName !== 'url')
				return \true;

		return \false;
	}

	public static function scrape(Tag $tag, array $scrapeConfig, $cacheDir = \null)
	{
		if (!$tag->hasAttribute('url'))
			return \true;

		$url = $tag->getAttribute('url');

		if (!\preg_match('#^https?://[^<>"\'\\s]+$#D', $url))
			return \true;

		foreach ($scrapeConfig as $scrape)
			self::scrapeEntry($url, $tag, $scrape, $cacheDir);

		return \true;
	}

	protected static function replaceTokens($url, array $vars)
	{
		return \preg_replace_callback(
			'#\\{@(\\w+)\\}#',
			function ($m) use ($vars)
			{
				return (isset($vars[$m[1]])) ? $vars[$m[1]] : '';
			},
			$url
		);
	}

	protected static function scrapeEntry($url, Tag $tag, array $scrape, $cacheDir)
	{
		list($matchRegexps, $extractRegexps, $attrNames) = $scrape;

		if (!self::tagIsMissingAnyAttribute($tag, $attrNames))
			return;

		$vars    = array();
		$matched = \false;
		foreach ((array) $matchRegexps as $matchRegexp)
			if (\preg_match($matchRegexp, $url, $m))
			{
				$vars   += $m;
				$matched = \true;
			}
		if (!$matched)
			return;

		$vars += $tag->getAttributes();

		$scrapeUrl = (isset($scrape[3])) ? self::replaceTokens($scrape[3], $vars) : $url;
		self::scrapeUrl($scrapeUrl, $tag, (array) $extractRegexps, $cacheDir);
	}

	protected static function scrapeUrl($url, Tag $tag, array $regexps, $cacheDir)
	{
		$content = self::wget($url, $cacheDir);

		foreach ($regexps as $regexp)
			if (\preg_match($regexp, $content, $m))
				foreach ($m as $k => $v)
					if (!\is_numeric($k) && !$tag->hasAttribute($k))
						$tag->setAttribute($k, $v);
	}

	protected static function tagIsMissingAnyAttribute(Tag $tag, array $attrNames)
	{
		foreach ($attrNames as $attrName)
			if (!$tag->hasAttribute($attrName))
				return \true;

		return \false;
	}

	protected static function wget($url, $cacheDir = \null)
	{
		$prefix = $suffix = $context = \null;
		if (\extension_loaded('zlib'))
		{
			$prefix  = 'compress.zlib://';
			$suffix  = '.gz';
			$context = \stream_context_create(array('http' => array('header' => 'Accept-Encoding: gzip')));
		}

		if (isset($cacheDir) && \file_exists($cacheDir))
		{
			$cacheFile = $cacheDir . '/http.' . \crc32($url) . $suffix;

			if (\file_exists($cacheFile))
				return \file_get_contents($prefix . $cacheFile);
		}

		$content = \file_get_contents($prefix . $url, \false, $context);

		if (isset($cacheFile) && $content !== \false)
			\file_put_contents($prefix . $cacheFile, $content);

		return $content;
	}
}

/*
* @package   s9e\TextFormatter
* @copyright Copyright (c) 2010-2015 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\TextFormatter\Plugins;

use s9e\TextFormatter\Parser;

abstract class ParserBase
{
	protected $config;

	protected $parser;

	final public function __construct(Parser $parser, array $config)
	{
		$this->parser = $parser;
		$this->config = $config;

		$this->setUp();
	}

	protected function setUp()
	{
	}

	abstract public function parse($text, array $matches);
}