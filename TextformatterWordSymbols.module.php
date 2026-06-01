<?php namespace ProcessWire;

/**
 * Word Symbols Textformatter
 *
 * Appends configurable symbols (e.g. ©, ®, ™, ℠) to configurable words
 * when output formatting is applied to a field value.
 *
 * Example: turns "Frameless" into "Frameless®" wherever it appears.
 *
 * Copyright 2026 by frameless Media
 * Licensed under MIT
 *
 * @property string $mappings
 * @property int $wholeWord
 * @property int $caseSensitive
 * @property int $firstOnly
 *
 */

class TextformatterWordSymbols extends Textformatter implements ConfigurableModule {

	public static function getModuleInfo() {
		return array(
			'title' => 'Word Symbols',
			'version' => 102,
			'summary' => 'Appends configurable symbols (©, ®, ™, ℠ …) to configurable words during output formatting.',
			'author' => 'frameless Media',
			'icon' => 'copyright',
		);
	}

	/**
	 * Default configuration
	 *
	 */
	protected static $defaults = array(
		'mappings' => '',
		'wholeWord' => 1,
		'caseSensitive' => 1,
		'firstOnly' => 0,
	);

	/**
	 * Named shortcuts that may be used in place of a literal symbol char
	 *
	 */
	protected static $shortcuts = array(
		'(c)' => '©',
		'copyright' => '©',
		'(r)' => '®',
		'reg' => '®',
		'registered' => '®',
		'(tm)' => '™',
		'tm' => '™',
		'trademark' => '™',
		'(sm)' => '℠',
		'sm' => '℠',
		'servicemark' => '℠',
	);

	public function __construct() {
		parent::__construct();
		foreach(self::$defaults as $key => $value) {
			$this->set($key, $value);
		}
	}

	/**
	 * Parse the configured mappings textarea into an array of [word => symbol]
	 *
	 * Each non-empty line: `word = symbol`. The symbol may be a literal
	 * character (©, ®, ™ …) or one of the named shortcuts (see $shortcuts).
	 * A trailing `| sup` flag wraps that symbol in a <sup> tag, e.g.
	 * `ProcessWire = (tm) | sup` produces `ProcessWire<sup>™</sup>`.
	 *
	 * @return array word => array('symbol' => string, 'sup' => bool)
	 *
	 */
	protected function getMappings() {
		$mappings = array();
		$lines = preg_split('/\r\n|\r|\n/', (string) $this->mappings);

		foreach($lines as $line) {
			$line = trim($line);
			if($line === '' || strpos($line, '=') === false) continue;

			list($word, $rest) = explode('=', $line, 2);
			$word = trim($word);
			$rest = trim($rest);

			// optional trailing flag: `symbol | sup`
			$sup = false;
			if(strpos($rest, '|') !== false) {
				list($sym, $flag) = explode('|', $rest, 2);
				$rest = trim($sym);
				if(strtolower(trim($flag)) === 'sup') $sup = true;
			}

			$symbol = $rest;
			if($word === '' || $symbol === '') continue;

			// expand named shortcut if one was used
			$key = strtolower($symbol);
			if(isset(self::$shortcuts[$key])) $symbol = self::$shortcuts[$key];

			$mappings[$word] = array('symbol' => $symbol, 'sup' => $sup);
		}

		return $mappings;
	}

	/**
	 * Apply the symbol substitutions to the given string
	 *
	 * @param string $str
	 *
	 */
	public function format(&$str) {

		$mappings = $this->getMappings();
		if(empty($mappings)) return;

		$limit = $this->firstOnly ? 1 : -1;
		$flags = 'u'; // unicode
		if(!$this->caseSensitive) $flags .= 'i';

		// Build a lookahead alternation of every symbol that must never be
		// duplicated: the standard marks plus all configured symbols. This
		// prevents both "Frameless©©" (same symbol) and "Frameless©®"
		// (a different symbol already present).
		$known = array('©', '®', '™', '℠');
		foreach($mappings as $m) $known[] = $m['symbol'];
		$known = array_unique($known);
		// longest first so a multi-character symbol matches before a substring of it
		usort($known, function($a, $b) {
			return mb_strlen($b) - mb_strlen($a);
		});
		$symbolsAlt = implode('|', array_map(function($s) {
			return preg_quote($s, '/');
		}, $known));

		foreach($mappings as $word => $m) {

			$symbol = $m['symbol'];
			$replacement = $m['sup'] ? '<sup>' . $symbol . '</sup>' : $symbol;
			$quotedWord = preg_quote($word, '/');

			// word boundaries that are unicode-aware (\b is not reliable for é, ö …)
			$before = $this->wholeWord ? '(?<![\p{L}\p{N}_])' : '';
			$after = $this->wholeWord ? '(?![\p{L}\p{N}_])' : '';

			// negative lookahead: skip if the word is already followed by any known
			// symbol — optionally after whitespace and/or inside a <sup> wrapper —
			// so none is ever added twice (handles both "©" and "<sup>©</sup>")
			$dupCheck = '(?!\s*(?:<sup[^>]*>\s*)?(?:' . $symbolsAlt . '))';
			$pattern = '/' . $before . $quotedWord . $after . $dupCheck . '/' . $flags;

			$str = preg_replace_callback($pattern, function($matches) use ($replacement) {
				return $matches[0] . $replacement;
			}, $str, $limit);
		}
	}

	/**
	 * Module configuration screen
	 *
	 * @param array $data
	 * @return InputfieldWrapper
	 *
	 */
	public function getModuleConfigInputfields(array $data) {

		$modules = $this->wire()->modules;
		$inputfields = $this->wire(new InputfieldWrapper());
		$data = array_merge(self::$defaults, $data);

		/** @var InputfieldTextarea $f */
		$f = $modules->get('InputfieldTextarea');
		$f->attr('name', 'mappings');
		$f->attr('value', $data['mappings']);
		$f->attr('rows', 8);
		$f->label = $this->_('Word → Symbol mappings');
		$f->description = $this->_('One mapping per line in the format `word = symbol`. The symbol may be a literal character or a named shortcut.');
		$f->notes = $this->_('Examples:') . "\n" .
			"`Frameless = ®`\n" .
			"`ProcessWire = (tm)`\n" .
			"`ACME = copyright`\n\n" .
			$this->_('Add `| sup` to render a symbol superscript, e.g.') . "\n" .
			"`ProcessWire = (tm) | sup` → `ProcessWire<sup>™</sup>`\n\n" .
			$this->_('Shortcuts:') . " `(c)`/`copyright` → © · `(r)`/`reg` → ® · `(tm)`/`tm` → ™ · `(sm)`/`sm` → ℠";
		$inputfields->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'wholeWord');
		$f->attr('value', 1);
		if($data['wholeWord']) $f->attr('checked', 'checked');
		$f->label = $this->_('Match whole words only');
		$f->description = $this->_('When enabled, only complete words are matched (e.g. "ACME" will not match inside "ACMElabs").');
		$f->columnWidth = 33;
		$inputfields->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'caseSensitive');
		$f->attr('value', 1);
		if($data['caseSensitive']) $f->attr('checked', 'checked');
		$f->label = $this->_('Case sensitive');
		$f->description = $this->_('When enabled, "acme" and "ACME" are treated as different words.');
		$f->columnWidth = 34;
		$inputfields->add($f);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'firstOnly');
		$f->attr('value', 1);
		if($data['firstOnly']) $f->attr('checked', 'checked');
		$f->label = $this->_('First occurrence only');
		$f->description = $this->_('When enabled, the symbol is only appended to the first occurrence of each word per field value.');
		$f->columnWidth = 33;
		$inputfields->add($f);

		return $inputfields;
	}
}
