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
			'version' => 113,
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
	 * Build a single combined regex matching every configured word
	 *
	 * All words go into one alternation ordered longest-first, so the longest
	 * matching phrase wins (e.g. "frameless Media" before "frameless") and the
	 * whole value is processed in one pass. The matched word is identified by
	 * its named group `m<i>`; an existing decoration following the word (any
	 * known symbol/entity, bare or in a <sup> wrapper) is captured so it can be
	 * normalised. Protected constructs are matched first and passed through.
	 *
	 * @param array $mappings Result of getMappings()
	 * @param string $flags Regex modifiers
	 * @return array array('pattern' => string, 'meta' => array(int => array))
	 *
	 */
	protected function buildPattern(array $mappings, $flags) {

		// Every symbol that counts as "already present": the standard marks
		// plus all configured symbols. Longest first so a multi-character
		// symbol matches before a substring of it.
		$known = array('©', '®', '™', '℠');
		foreach($mappings as $m) $known[] = $m['symbol'];
		$known = array_unique($known);
		usort($known, function($a, $b) {
			return mb_strlen($b) - mb_strlen($a);
		});

		// alternation of any known symbol or its entity forms (any spelling)
		$anyList = array_map(function($s) {
			return preg_quote($s, '~');
		}, $known);
		foreach($known as $s) {
			foreach($this->getSymbolEntities($s) as $f) $anyList[] = $f;
		}
		$anyList = array_unique($anyList);
		$anyAlt = implode('|', $anyList);

		// word boundaries that are unicode-aware (\b is not reliable for é, ö …)
		$before = $this->wholeWord ? '(?<![\p{L}\p{N}_])' : '';
		$after = $this->wholeWord ? '(?![\p{L}\p{N}_])' : '';

		// words longest-first so the longest matching phrase wins
		$words = array_keys($mappings);
		usort($words, function($a, $b) {
			return mb_strlen($b) - mb_strlen($a);
		});

		$alts = array();
		$meta = array();
		$i = 0;
		foreach($words as $word) {
			$m = $mappings[$word];
			// all spellings of *this* symbol: literal char + its entity forms
			$ownAlt = preg_quote($m['symbol'], '~');
			foreach($this->getSymbolEntities($m['symbol']) as $f) $ownAlt .= '|' . $f;
			$alts[] = '(?<m' . $i . '>' . preg_quote($word, '~') . ')';
			$meta[$i] = array(
				'word' => $word,
				'symbol' => $m['symbol'],
				'sup' => $m['sup'],
				// tests whether a captured decoration spelling is *this* symbol
				'ownTest' => '~^(?:' . $ownAlt . ')$~' . $flags,
			);
			$i++;
		}

		// optional existing decoration right after the word: any symbol/entity,
		// bare or wrapped in <sup>; the inner spelling is captured separately so
		// it can be unwrapped or kept as-is.
		$deco = '(?<deco>\s*(?:<sup[^>]*>\s*(?<inner>' . $anyAlt . ')\s*</sup>|(?<bare>' . $anyAlt . ')))?';

		$pattern = '~(?<prot>' . $this->getProtectedPattern() . ')|'
			. $before . '(?:' . implode('|', $alts) . ')' . $after . $deco . '~' . $flags;

		return array('pattern' => $pattern, 'meta' => $meta);
	}

	/**
	 * Render a matched word with its mark in the configured form
	 *
	 * Adds the mark when none follows; otherwise normalises an existing mark of
	 * *this* symbol to the mapping's wrap setting (wrap a bare one for `| sup`,
	 * unwrap a <sup> one for a plain mapping), keeping its spelling. A different
	 * symbol next to the word is left untouched.
	 *
	 * @param string $w Matched word (original casing)
	 * @param string $deco Captured decoration ('' if none)
	 * @param string $inner Inner spelling from a <sup> wrapper ('' if not wrapped)
	 * @param string $bare Bare spelling ('' if wrapped or none)
	 * @param array $info Mapping meta (symbol, sup, ownTest)
	 * @return string
	 *
	 */
	protected function renderMatch($w, $deco, $inner, $bare, array $info) {
		$symbol = $info['symbol'];
		$sup = $info['sup'];

		if($deco === '') {
			return $sup ? $w . '<sup>' . $symbol . '</sup>' : $w . $symbol;
		}

		// a symbol/entity already follows; only normalise it if it is this symbol
		$symText = $inner !== '' ? $inner : $bare;
		if(!preg_match($info['ownTest'], $symText)) return $w . $deco;

		$wrapped = $inner !== '';
		$decoTrim = ltrim($deco);
		if($sup) {
			if($wrapped) return $w . $decoTrim; // already wrapped, keep
			$spelling = ($decoTrim[0] === '&') ? $decoTrim : $symbol;
			return $w . '<sup>' . $spelling . '</sup>';
		}
		if($wrapped) return $w . $inner; // unwrap, keep spelling
		return $w . $decoTrim; // already bare, keep
	}

	/**
	 * Apply the annotations to the given string
	 *
	 * Replacements are applied to text content only: HTML tags, attributes,
	 * comments and e-mail addresses are never modified, and the content of
	 * configured skip-tags (e.g. code, pre) is left untouched. The whole value
	 * is processed in a single pass; the longest matching word wins.
	 *
	 * @param string $str
	 *
	 */
	public function format(&$str) {

		$mappings = $this->getMappings();
		if(empty($mappings)) return;

		$flags = 'su'; // dot matches newlines (skip-tag blocks/comments) + unicode
		if(!$this->caseSensitive) $flags .= 'i';

		$built = $this->buildPattern($mappings, $flags);
		$meta = $built['meta'];
		$firstOnly = (bool) $this->firstOnly;
		$seen = array();

		$str = preg_replace_callback($built['pattern'], function($m) use ($meta, $firstOnly, &$seen) {
			// protected construct (tag, comment, e-mail, skip block): leave as-is
			if(isset($m['prot']) && $m['prot'] !== '') return $m[0];

			// identify which word matched (its named group is the non-empty one)
			$info = null;
			$w = '';
			foreach($meta as $gi => $candidate) {
				$key = 'm' . $gi;
				if(isset($m[$key]) && $m[$key] !== '') {
					$info = $candidate;
					$w = $m[$key];
					break;
				}
			}
			if($info === null) return $m[0];

			$deco = isset($m['deco']) ? $m['deco'] : '';
			$inner = isset($m['inner']) ? $m['inner'] : '';
			$bare = isset($m['bare']) ? $m['bare'] : '';

			if($firstOnly) {
				$word = $info['word'];
				if(isset($seen[$word])) {
					// later occurrence: strip this symbol, leave a different one
					if($deco === '') return $w;
					$symText = $inner !== '' ? $inner : $bare;
					return preg_match($info['ownTest'], $symText) ? $w : $w . $deco;
				}
				$seen[$word] = true;
			}

			return $this->renderMatch($w, $deco, $inner, $bare, $info);
		}, $str);
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
