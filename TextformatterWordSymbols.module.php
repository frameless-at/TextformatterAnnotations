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
 * @property string $skipTags
 *
 */

class TextformatterWordSymbols extends Textformatter implements ConfigurableModule {

	public static function getModuleInfo() {
		return array(
			'title' => 'Word Symbols',
			'version' => 108,
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
		'skipTags' => 'code pre script style',
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
	 * Parse the configured skip-tags into a lowercased array of tag names
	 *
	 * Text inside these elements (and their descendants) is left untouched.
	 *
	 * @return array
	 *
	 */
	protected function getSkipTags() {
		$tags = preg_split('/[\s,]+/', strtolower((string) $this->skipTags), -1, PREG_SPLIT_NO_EMPTY);
		return array_map(function($t) {
			return trim($t, '<>/');
		}, $tags);
	}

	/**
	 * Unicode code point of a single character, or 0 if not determinable
	 *
	 * @param string $char
	 * @return int
	 *
	 */
	protected function charCode($char) {
		if(!function_exists('mb_ord')) return 0;
		$code = mb_ord($char, 'UTF-8');
		return $code === false ? 0 : (int) $code;
	}

	/**
	 * Regex fragments matching the HTML entity forms of a symbol
	 *
	 * Covers the named entity (for the standard marks) and the numeric
	 * decimal/hex character references, tolerating leading zeros and either
	 * case of the hex digits. Fragments are regex (not literal) and must not
	 * be passed through preg_quote().
	 *
	 * @param string $symbol
	 * @return array
	 *
	 */
	protected function getSymbolEntities($symbol) {
		$named = array('©' => 'copy', '®' => 'reg', '™' => 'trade');
		$fragments = array();

		if(isset($named[$symbol])) {
			// HTML5 defines both the lowercase and the all-uppercase legacy form
			// (&copy; and &COPY;); mixed case like &Copy; is invalid and ignored.
			$fragments[] = '&' . $named[$symbol] . ';';
			$fragments[] = '&' . strtoupper($named[$symbol]) . ';';
		}

		// numeric references only make sense for a single character
		if(mb_strlen($symbol) === 1) {
			$code = $this->charCode($symbol);
			if($code > 0) {
				$fragments[] = '&#0*' . $code . ';';
				$hex = dechex($code);
				$hexCi = '';
				for($i = 0; $i < strlen($hex); $i++) {
					$c = $hex[$i];
					$hexCi .= ctype_alpha($c) ? '[' . strtoupper($c) . strtolower($c) . ']' : $c;
				}
				$fragments[] = '&#[xX]0*' . $hexCi . ';';
			}
		}

		return $fragments;
	}

	/**
	 * Build the alternation of "protected" constructs that must pass through
	 * unchanged: HTML comments, skip-tag blocks, any other tag, and e-mails.
	 *
	 * Matched first in each pattern (as a leading alternative) so that the
	 * regex engine consumes whole tags/addresses and never starts a word
	 * match inside an attribute, a tag, or an e-mail address. Returned as a
	 * regex fragment using the "~" delimiter (so literal "/" in closing tags
	 * needs no escaping).
	 *
	 * @return string
	 *
	 */
	protected function getProtectedPattern() {
		$alts = array('<!--.*?-->'); // HTML comments
		// whole skip-tag blocks (content protected), tag name case-insensitive
		foreach($this->getSkipTags() as $tag) {
			$tag = preg_quote($tag, '~');
			$alts[] = '<(?i:' . $tag . ')\b[^>]*>.*?</(?i:' . $tag . ')\s*>';
		}
		$alts[] = '<[^>]+>'; // any other tag
		$alts[] = '[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}'; // e-mail
		return implode('|', $alts);
	}

	/**
	 * Build one regex pattern per mapping
	 *
	 * Each pattern is `(?<prot>protected)|word(+guards)` so a single pass over
	 * the whole value can decorate matching words while leaving protected
	 * constructs untouched. Because the value is not split, the dedup guards
	 * can see what follows a word across tag boundaries (e.g. an existing
	 * `<sup>©</sup>`).
	 *
	 * @param array $mappings Result of getMappings()
	 * @param string $flags Regex modifiers
	 * @return array word => array('pattern' => string, 'symbol' => string, 'sup' => bool)
	 *
	 */
	protected function buildPatterns(array $mappings, $flags) {

		// Every symbol that counts as "already present": the standard marks
		// plus all configured symbols. Longest first so a multi-character
		// symbol matches before a substring of it.
		$known = array('©', '®', '™', '℠');
		foreach($mappings as $m) $known[] = $m['symbol'];
		$known = array_unique($known);
		usort($known, function($a, $b) {
			return mb_strlen($b) - mb_strlen($a);
		});

		// Regex fragments for the entity forms of every known symbol, so an
		// existing "&reg;" / "&#174;" / "&#xAE;" is recognised just like "®".
		$entityFrags = array();
		foreach($known as $s) {
			$entityFrags = array_merge($entityFrags, $this->getSymbolEntities($s));
		}
		$entityFrags = array_unique($entityFrags);
		$entityAlt = empty($entityFrags) ? '' : implode('|', $entityFrags);

		// word boundaries that are unicode-aware (\b is not reliable for é, ö …)
		$before = $this->wholeWord ? '(?<![\p{L}\p{N}_])' : '';
		$after = $this->wholeWord ? '(?![\p{L}\p{N}_])' : '';

		// matches an existing decoration right after a word: any known symbol or
		// entity, optionally inside a <sup> wrapper. Used both for the plain
		// dedup guard and to detect already-decorated occurrences (first-only).
		$presentList = array_map(function($s) {
			return preg_quote($s, '~');
		}, $known);
		if($entityAlt !== '') $presentList[] = $entityAlt;
		$presentGroup = '(?:<sup[^>]*>\s*)?(?:' . implode('|', $presentList) . ')';

		$protected = $this->getProtectedPattern();
		$patterns = array();

		foreach($mappings as $word => $m) {

			$symbol = $m['symbol'];
			$quotedWord = preg_quote($word, '~');
			$quotedSymbol = preg_quote($symbol, '~');

			if($m['sup']) {
				// Superscript mapping. Upgrade a bare occurrence of *this* symbol
				// to the <sup> form, but leave an existing <sup> wrapper, any
				// *other* symbol and any entity form untouched (so we never
				// produce "<sup>®</sup>©" or "<sup>®</sup>&reg;").
				$others = array_values(array_filter($known, function($s) use($symbol) {
					return $s !== $symbol;
				}));
				$guard = '(?!\s*<sup)';
				if(!empty($others)) {
					$otherAlt = implode('|', array_map(function($s) {
						return preg_quote($s, '~');
					}, $others));
					$guard .= '(?!\s*(?:' . $otherAlt . '))';
				}
				if($entityAlt !== '') $guard .= '(?!\s*(?:' . $entityAlt . '))';
				// word captured in (?<w>…); guards reject sup/other-symbol/entity;
				// finally an own bare symbol may be absorbed (and replaced by <sup>)
				$word_pattern = $before . '(?<w>' . $quotedWord . ')' . $after
					. $guard . '(?:\s*' . $quotedSymbol . ')?';

			} else {
				// Plain mapping. Skip if the word is already followed by any known
				// symbol or its entity form — optionally after whitespace and/or
				// inside a <sup> wrapper — so none is ever added twice (handles
				// "©", "<sup>©</sup>" and "&copy;" / "&#169;" alike).
				$dupCheck = '(?!\s*' . $presentGroup . ')';
				$word_pattern = $before . $quotedWord . $after . $dupCheck;
			}

			$patterns[$word] = array(
				// decorate matching words, leaving protected constructs untouched
				'pattern' => '~(?<prot>' . $protected . ')|' . $word_pattern . '~' . $flags,
				// matches an *already decorated* occurrence (for first-only counting)
				'detect' => '~(?<prot>' . $protected . ')|' . $before . $quotedWord . $after . '\s*' . $presentGroup . '~' . $flags,
				'symbol' => $symbol,
				'sup' => $m['sup'],
			);
		}

		return $patterns;
	}

	/**
	 * Apply the symbol substitutions to the given string
	 *
	 * Replacements are applied to text content only: HTML tags, attributes,
	 * comments and e-mail addresses are never modified, and the content of
	 * configured skip-tags (e.g. code, pre) is left untouched. Each mapping is
	 * applied in a single pass over the whole value.
	 *
	 * @param string $str
	 *
	 */
	public function format(&$str) {

		$mappings = $this->getMappings();
		if(empty($mappings)) return;

		$flags = 'su'; // dot matches newlines (skip-tag blocks/comments) + unicode
		if(!$this->caseSensitive) $flags .= 'i';

		foreach($this->buildPatterns($mappings, $flags) as $word => $p) {

			$symbol = $p['symbol'];
			$sup = $p['sup'];

			// -1 = unlimited. For "first occurrence only" the word may carry the
			// symbol once in the whole value — so an occurrence that is *already*
			// decorated in the source counts and suppresses any new decoration.
			$remaining = -1;
			if($this->firstOnly) {
				$remaining = 1;
				if(preg_match_all($p['detect'], $str, $matches, PREG_SET_ORDER)) {
					foreach($matches as $mm) {
						// a match without a "prot" group is an already-decorated word
						if(!isset($mm['prot']) || $mm['prot'] === '') {
							$remaining = 0;
							break;
						}
					}
				}
			}

			$str = preg_replace_callback($p['pattern'], function($m) use ($symbol, $sup, &$remaining) {
				// protected construct (tag, comment, e-mail, skip block): leave as-is
				if(isset($m['prot']) && $m['prot'] !== '') return $m[0];
				// first-occurrence budget already spent: leave the match unchanged
				if($remaining === 0) return $m[0];
				if($remaining > 0) $remaining--;
				return $sup ? $m['w'] . '<sup>' . $symbol . '</sup>' : $m[0] . $symbol;
			}, $str);
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

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'skipTags');
		$f->attr('value', $data['skipTags']);
		$f->label = $this->_('Skip inside these tags');
		$f->description = $this->_('Text inside these HTML elements (and their descendants) is left untouched. Separate tag names with spaces or commas.');
		$f->notes = $this->_('HTML tags, attributes and comments are always protected regardless of this list. Add `a` here if you do not want link text decorated.');
		$f->placeholder = 'code pre script style';
		$inputfields->add($f);

		return $inputfields;
	}
}
