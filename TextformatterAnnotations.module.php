<?php namespace ProcessWire;

/**
 * Annotations Textformatter
 *
 * Appends a configurable mark (a symbol, a footnote marker, …) to configurable
 * words when output formatting is applied to a field value. The mark can
 * optionally be wrapped in a <sup> tag per mapping.
 *
 * Example: turns "Frameless" into "Frameless®", or "Term" into "Term<sup>1</sup>".
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

class TextformatterAnnotations extends Textformatter implements ConfigurableModule {

	public static function getModuleInfo() {
		return array(
			'title' => 'Annotations',
			'version' => 112,
			'summary' => 'Appends a configurable mark (symbol, footnote, …) to configurable words during output formatting, optionally wrapped in <sup>.',
			'author' => 'frameless Media',
			'icon' => 'asterisk',
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

			// all spellings of *this* symbol: literal char + its entity forms
			$ownAlt = $quotedSymbol;
			foreach($this->getSymbolEntities($symbol) as $f) $ownAlt .= '|' . $f;

			// an existing decoration of *this* symbol directly after the word: a
			// <sup> wrapper of it (inner spelling captured) or a bare spelling.
			// Captured in (?<deco>…) so the callback can normalise it to the
			// configured form — wrap a bare one for `| sup`, unwrap a <sup> one
			// for a plain mapping — while preserving the spelling.
			$ownDeco = '\s*(?:<sup[^>]*>\s*(?<inner>' . $ownAlt . ')\s*</sup>|' . $ownAlt . ')';

			// Match the word, then either capture an own decoration to normalise,
			// or assert nothing decorated follows so a new symbol may be added.
			// `presentGroup` covers any known symbol/entity (bare or in <sup>), so
			// a different symbol next to the word is left untouched.
			$word_pattern = $before . '(?<w>' . $quotedWord . ')' . $after
				. '(?:(?<deco>' . $ownDeco . ')|(?!\s*' . $presentGroup . '))';

			// first-occurrence-only mode: match every occurrence (decorated or not)
			// so the first can be normalised and every later one stripped.
			$firstPattern = '~(?<prot>' . $protected . ')|' . $before . '(?<w>' . $quotedWord . ')' . $after
				. '(?<deco>' . $ownDeco . ')?~' . $flags;

			$patterns[$word] = array(
				// decorate/normalise words, leaving protected constructs untouched
				'pattern' => '~(?<prot>' . $protected . ')|' . $word_pattern . '~' . $flags,
				// used when "first occurrence only" is on
				'firstPattern' => $firstPattern,
				'symbol' => $symbol,
				'sup' => $m['sup'],
			);
		}

		return $patterns;
	}

	/**
	 * Render a word with the symbol in the configured form
	 *
	 * Normalises an existing decoration to match the mapping's wrap setting,
	 * keeping the symbol's spelling: a bare symbol is wrapped for `| sup`, and
	 * an existing <sup> wrapper is unwrapped for a plain mapping. With no
	 * existing decoration the configured symbol is added.
	 *
	 * @param string $w The matched word
	 * @param string $deco Captured existing decoration ('' if none)
	 * @param string $inner Inner spelling captured from a <sup> wrapper ('' if not wrapped)
	 * @param string $symbol Configured symbol
	 * @param bool $sup Whether the mapping wraps in <sup>
	 * @return string
	 *
	 */
	protected function applyDecoration($w, $deco, $inner, $symbol, $sup) {
		$deco = ltrim($deco);
		$wrapped = $inner !== '';

		if($sup) {
			if($deco === '') return $w . '<sup>' . $symbol . '</sup>';
			if($wrapped) return $w . $deco; // already wrapped, keep
			// bare symbol/entity → wrap, keeping an entity spelling as-is
			$spelling = ($deco[0] === '&') ? $deco : $symbol;
			return $w . '<sup>' . $spelling . '</sup>';
		}

		// plain mapping
		if($deco === '') return $w . $symbol;
		if($wrapped) return $w . $inner; // unwrap, keeping the spelling
		return $w . $deco; // already bare, keep
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

			if($this->firstOnly) {
				// Exactly one symbol, on the first occurrence: normalise the first
				// occurrence and strip any existing symbol from all later ones.
				$seen = false;
				$str = preg_replace_callback($p['firstPattern'], function($m) use ($symbol, $sup, &$seen) {
					if(isset($m['prot']) && $m['prot'] !== '') return $m[0];
					if($seen) return $m['w']; // later occurrence: strip existing symbol
					$seen = true;
					$deco = isset($m['deco']) ? $m['deco'] : '';
					$inner = isset($m['inner']) ? $m['inner'] : '';
					return $this->applyDecoration($m['w'], $deco, $inner, $symbol, $sup);
				}, $str);
				continue;
			}

			$str = preg_replace_callback($p['pattern'], function($m) use ($symbol, $sup) {
				// protected construct (tag, comment, e-mail, skip block): leave as-is
				if(isset($m['prot']) && $m['prot'] !== '') return $m[0];
				$deco = isset($m['deco']) ? $m['deco'] : '';
				$inner = isset($m['inner']) ? $m['inner'] : '';
				return $this->applyDecoration($m['w'], $deco, $inner, $symbol, $sup);
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
		$f->label = $this->_('Word → mark mappings');
		$f->description = $this->_('One mapping per line in the format `word = mark`. The mark is any text appended after the word — a symbol, a footnote marker, etc. A few symbol shortcuts are available.');
		$f->notes = $this->_('Examples:') . "\n" .
			"`Frameless = ®`\n" .
			"`Term = 1 | sup`   (" . $this->_('footnote') . ")\n" .
			"`ProcessWire = (tm) | sup`\n\n" .
			$this->_('Add `| sup` to wrap the mark in a superscript tag, e.g.') . "\n" .
			"`ProcessWire = (tm) | sup` → `ProcessWire<sup>™</sup>`\n\n" .
			$this->_('Symbol shortcuts:') . " `(c)`/`copyright` → © · `(r)`/`reg` → ® · `(tm)`/`tm` → ™ · `(sm)`/`sm` → ℠";
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
		$f->description = $this->_('When enabled, the mark is placed on the first occurrence of each word per field value, and removed from all later occurrences.');
		$f->columnWidth = 33;
		$inputfields->add($f);

		/** @var InputfieldText $f */
		$f = $modules->get('InputfieldText');
		$f->attr('name', 'skipTags');
		$f->attr('value', $data['skipTags']);
		$f->label = $this->_('Skip inside these tags');
		$f->description = $this->_('Text inside these HTML elements (and their descendants) is left untouched. Separate tag names with spaces or commas.');
		$f->notes = $this->_('HTML tags, attributes and comments are always protected regardless of this list. Add `a` here if you do not want link text annotated.');
		$f->placeholder = 'code pre script style';
		$inputfields->add($f);

		return $inputfields;
	}
}
