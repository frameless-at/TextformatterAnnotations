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
			'version' => 100,
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
	 *
	 * @return array
	 *
	 */
	protected function getMappings() {
		$mappings = array();
		$lines = preg_split('/\r\n|\r|\n/', (string) $this->mappings);

		foreach($lines as $line) {
			$line = trim($line);
			if($line === '' || strpos($line, '=') === false) continue;

			list($word, $symbol) = explode('=', $line, 2);
			$word = trim($word);
			$symbol = trim($symbol);
			if($word === '' || $symbol === '') continue;

			// expand named shortcut if one was used
			$key = strtolower($symbol);
			if(isset(self::$shortcuts[$key])) $symbol = self::$shortcuts[$key];

			$mappings[$word] = $symbol;
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

		foreach($mappings as $word => $symbol) {

			$quotedWord = preg_quote($word, '/');
			$quotedSymbol = preg_quote($symbol, '/');

			// word boundaries that are unicode-aware (\b is not reliable for é, ö …)
			$before = $this->wholeWord ? '(?<![\p{L}\p{N}_])' : '';
			$after = $this->wholeWord ? '(?![\p{L}\p{N}_])' : '';

			// negative lookahead so we never append the symbol twice
			$pattern = '/' . $before . $quotedWord . $after . '(?!' . $quotedSymbol . ')/' . $flags;

			$str = preg_replace_callback($pattern, function($m) use ($symbol) {
				return $m[0] . $symbol;
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
