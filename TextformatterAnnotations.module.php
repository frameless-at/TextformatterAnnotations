<?php namespace ProcessWire;

/**
 * Annotations Textformatter
 *
 * Appends a configurable mark (a symbol, a footnote marker, …) to configurable
 * words when output formatting is applied to a field value, or wraps part of a
 * word in an inline tag. The mark/part can optionally be wrapped in a freely
 * chosen inline tag per mapping.
 *
 * Examples: "frameless" → "frameless®", "Term" → "Term<sup>1</sup>",
 * "H2O" → "H<sub>2</sub>O".
 *
 * Copyright 2026 by frameless Media
 * Licensed under MIT
 *
 * @property string $terms
 * @property string $skipTags
 *
 * Per-string settings are stored under dynamic keys op_<key> (append|wrap|both),
 * mark_<key>, part_<key>, tag_<key> and opts_<key> (a checkbox array of
 * whole/case/first), where key = rowKey(term).
 *
 */

class TextformatterAnnotations extends Textformatter implements ConfigurableModule {

	public static function getModuleInfo() {
		return array(
			'title' => 'Annotations',
			'version' => 130,
			'summary' => 'Appends a configurable mark (symbol, footnote, …) to configurable words, or wraps part of a word in an inline tag, during output formatting.',
			'author' => 'frameless Media',
			'icon' => 'asterisk',
		);
	}

	/**
	 * Default configuration
	 *
	 */
	protected static $defaults = array(
		'terms' => '',
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

	/**
	 * Inline HTML tags allowed for wrapping (the `| tag` flag)
	 *
	 */
	protected static $wrapTags = array(
		'sub', 'sup', 'b', 'strong', 'i', 'em', 'u', 's', 'mark', 'small',
		'ins', 'del', 'code', 'kbd', 'samp', 'var', 'abbr', 'cite', 'dfn', 'q', 'time',
	);

	/**
	 * Default match options for a not-yet-configured row
	 *
	 */
	protected static $optsDefault = array('whole', 'case');

	/**
	 * Split a textarea value into trimmed, non-empty lines
	 *
	 * @param string $str
	 * @return array
	 *
	 */
	protected function splitLines($str) {
		$lines = preg_split('/\r\n|\r|\n/', (string) $str, -1, PREG_SPLIT_NO_EMPTY);
		return array_values(array_filter(array_map('trim', $lines), 'strlen'));
	}

	/**
	 * Return the flag as a valid wrap tag (lowercased) or null if not allowed
	 *
	 * @param string $flag
	 * @return string|null
	 *
	 */
	protected function wrapTag($flag) {
		$flag = strtolower(trim($flag));
		return in_array($flag, self::$wrapTags, true) ? $flag : null;
	}

	public function __construct() {
		parent::__construct();
		foreach(self::$defaults as $key => $value) {
			$this->set($key, $value);
		}
	}

	/**
	 * Config storage key for a term's per-string settings
	 *
	 * Content-based (md5) so it survives reordering and is a safe field name.
	 *
	 * @param string $term
	 * @return string
	 *
	 */
	protected function rowKey($term) {
		return substr(md5($term), 0, 10);
	}

	/**
	 * Build the list of mapping entries from the terms list + per-row settings
	 *
	 * The terms textarea holds one search string per line; everything else
	 * (operation, mark/part, tag, whole-word, case, first-only) is stored per
	 * row under dynamic config keys. A row not yet saved uses sensible defaults
	 * (append, whole word + case sensitive on, first-only off).
	 *
	 * @return array list of mapping entries with per-row 'whole'/'case'/'first'
	 *
	 */
	protected function getMappings() {
		$mappings = array();

		foreach($this->splitLines($this->terms) as $term) {
			$key = $this->rowKey($term);

			$saved = $this->get("op_$key") !== null;
			$opRaw = $this->get("op_$key");
			$op = in_array($opRaw, array('wrap', 'both'), true) ? $opRaw : 'append';
			$mark = trim((string) $this->get("mark_$key"));
			$part = trim((string) $this->get("part_$key"));
			$tag = $this->wrapTag((string) $this->get("tag_$key"));

			// match options (one multi-checkbox field per row); a not-yet-saved
			// row falls back to the defaults (whole word + case on, first off)
			$opts = $saved ? (array) $this->get("opts_$key") : self::$optsDefault;
			$whole = in_array('whole', $opts, true);
			$case = in_array('case', $opts, true);
			$first = in_array('first', $opts, true);

			// a row may wrap, append, or both — emitting one entry per action
			if($op === 'wrap' || $op === 'both') {
				$find = $part === '' ? $term : $part; // empty = whole word
				$mappings[] = array(
					'type' => 'wrap', 'word' => $term, 'find' => $find,
					'tag' => $tag === null ? 'sub' : $tag, // wrap needs a tag
					'whole' => $whole, 'case' => $case, 'first' => $first,
				);
			}
			if(($op === 'append' || $op === 'both') && $mark !== '') {
				$symbol = $mark;
				$sk = strtolower($symbol);
				if(isset(self::$shortcuts[$sk])) $symbol = self::$shortcuts[$sk];
				$mappings[] = array(
					'type' => 'append', 'word' => $term, 'symbol' => $symbol,
					// in "both" the wrap uses the tag, so the appended mark is inline
					'tag' => $op === 'both' ? null : $tag,
					'whole' => $whole, 'case' => $case, 'first' => $first,
				);
			}
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
		// plus all configured (append) symbols. Longest first so a multi-char
		// symbol matches before a substring of it.
		$known = array('©', '®', '™', '℠');
		foreach($mappings as $m) {
			if($m['type'] === 'append') $known[] = $m['symbol'];
		}
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

		// entries longest-first (by word) so the longest matching phrase wins
		$entries = $mappings;
		usort($entries, function($a, $b) {
			return mb_strlen($b['word']) - mb_strlen($a['word']);
		});

		// Each alternative carries its own boundaries and case sensitivity:
		// whole-word adds unicode-aware boundary lookarounds; a case-insensitive
		// row wraps the word in an inline (?i:…) group. (\b is not reliable for
		// é, ö …, hence the explicit lookarounds.)
		$alts = array();
		$meta = array();
		$i = 0;
		foreach($entries as $m) {
			$quoted = preg_quote($m['word'], '~');
			$wordRe = $m['case'] ? $quoted : '(?i:' . $quoted . ')';
			$before = $m['whole'] ? '(?<![\p{L}\p{N}_])' : '';
			$after = $m['whole'] ? '(?![\p{L}\p{N}_])' : '';
			$alts[] = $before . '(?<m' . $i . '>' . $wordRe . ')' . $after;

			if($m['type'] === 'wrap') {
				$meta[$i] = array(
					'type' => 'wrap',
					'word' => $m['word'],
					'tag' => $m['tag'],
					'first' => $m['first'],
					// matches the substring to wrap inside the word
					'findRe' => '~' . preg_quote($m['find'], '~') . '~u' . ($m['case'] ? '' : 'i'),
				);
			} else {
				// all spellings of *this* symbol: literal char + its entity forms
				$ownAlt = preg_quote($m['symbol'], '~');
				foreach($this->getSymbolEntities($m['symbol']) as $f) $ownAlt .= '|' . $f;
				$meta[$i] = array(
					'type' => 'append',
					'word' => $m['word'],
					'symbol' => $m['symbol'],
					'tag' => $m['tag'],
					'first' => $m['first'],
					// tests whether a captured decoration spelling is *this* symbol
					'ownTest' => '~^(?:' . $ownAlt . ')$~su',
				);
			}
			$i++;
		}

		// optional existing decoration right after the word: any known symbol or
		// entity, bare or wrapped in any allowed inline tag. The wrapper tag name
		// and the inner spelling are captured so the mark can be normalised to
		// the mapping's configured tag (or unwrapped).
		$tagAlt = implode('|', self::$wrapTags);
		$deco = '(?<deco>\s*(?:<(?<dtag>' . $tagAlt . ')\b[^>]*>\s*(?<inner>' . $anyAlt . ')\s*</\k<dtag>\s*>|(?<bare>' . $anyAlt . ')))?';

		$pattern = '~(?<prot>' . $this->getProtectedPattern() . ')|'
			. '(?:' . implode('|', $alts) . ')' . $deco . '~' . $flags;

		return array('pattern' => $pattern, 'meta' => $meta);
	}

	/**
	 * Render a matched word with its mark in the configured form
	 *
	 * Adds the mark when none follows; otherwise normalises an existing mark of
	 * *this* symbol to the mapping's tag (wrap a bare one, rewrap a different
	 * tag, or unwrap when the mapping has no tag), keeping its spelling. A
	 * different symbol next to the word is left untouched.
	 *
	 * @param string $w Matched word (original casing)
	 * @param string $deco Captured decoration ('' if none)
	 * @param string $inner Inner spelling from a tag wrapper ('' if not wrapped)
	 * @param string $bare Bare spelling ('' if wrapped or none)
	 * @param string $dtag Wrapper tag name found ('' if not wrapped)
	 * @param array $info Mapping meta (symbol, tag, ownTest)
	 * @return string
	 *
	 */
	protected function renderMatch($w, $deco, $inner, $bare, $dtag, array $info) {
		$symbol = $info['symbol'];
		$tag = $info['tag']; // string tag, or null for plain append

		if($deco === '') {
			return $tag === null ? $w . $symbol : $w . '<' . $tag . '>' . $symbol . '</' . $tag . '>';
		}

		// a symbol/entity already follows; only normalise it if it is this symbol
		$symText = $inner !== '' ? $inner : $bare;
		if(!preg_match($info['ownTest'], $symText)) return $w . $deco;

		$wrapped = $inner !== '';
		$spelling = $wrapped ? $inner : ltrim($deco);

		if($tag === null) return $w . $spelling; // plain: unwrap / keep bare
		// keep an existing wrapper of the same tag verbatim (preserves attributes)
		if($wrapped && strcasecmp($dtag, $tag) === 0) return $w . ltrim($deco);
		return $w . '<' . $tag . '>' . $spelling . '</' . $tag . '>';
	}

	/**
	 * Apply the annotations to the given string
	 *
	 * Replacements are applied to text content only: HTML tags, attributes,
	 * comments and e-mail addresses are never modified, and the content of
	 * configured skip-tags (e.g. code, pre) is left untouched.
	 *
	 * Two phases: append mappings first, then wrap mappings on top. Within each
	 * phase the longest matching word wins. Running appends first lets wrap
	 * styling layer over an already-marked phrase — e.g. `frameless` is wrapped
	 * inside an `®`-marked `frameless Media` without breaking the phrase match.
	 *
	 * @param string $str
	 *
	 */
	public function format(&$str) {

		$mappings = $this->getMappings();
		if(empty($mappings)) return;

		// dot matches newlines (skip-tag blocks/comments) + unicode; case
		// sensitivity is per row, applied via inline (?i:…) in buildPattern()
		$flags = 'su';

		$appends = array();
		$wraps = array();
		foreach($mappings as $m) {
			if($m['type'] === 'wrap') $wraps[] = $m; else $appends[] = $m;
		}

		if(!empty($appends)) $this->applyPass($str, $appends, $flags);
		if(!empty($wraps)) $this->applyPass($str, $wraps, $flags);
	}

	/**
	 * Apply one phase (a set of same-kind mappings) in a single combined pass
	 *
	 * @param string $str Modified by reference
	 * @param array $entries Mapping entries (all append, or all wrap)
	 * @param string $flags Regex modifiers
	 *
	 */
	protected function applyPass(&$str, array $entries, $flags) {

		$built = $this->buildPattern($entries, $flags);
		$meta = $built['meta'];
		$seen = array();

		$str = preg_replace_callback($built['pattern'], function($m) use ($meta, &$seen) {
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
			$dtag = isset($m['dtag']) ? $m['dtag'] : '';

			// per-row "first occurrence only"
			if($info['first']) {
				$word = $info['word'];
				if(isset($seen[$word])) {
					// later occurrence: do not annotate it again
					if($info['type'] === 'wrap') return $w . $deco; // leave it unwrapped
					// append: strip this symbol, leave a different one
					if($deco === '') return $w;
					$symText = $inner !== '' ? $inner : $bare;
					return preg_match($info['ownTest'], $symText) ? $w : $w . $deco;
				}
				$seen[$word] = true;
			}

			if($info['type'] === 'wrap') {
				// wrap occurrences of the configured substring inside the word;
				// re-append any captured trailing decoration unchanged
				$tag = $info['tag'];
				$wrapped = preg_replace($info['findRe'], '<' . $tag . '>$0</' . $tag . '>', $w);
				return $wrapped . $deco;
			}

			return $this->renderMatch($w, $deco, $inner, $bare, $dtag, $info);
		}, $str);
	}

	/**
	 * Read a saved config value, or a default when it is not present
	 *
	 * @param array $data
	 * @param string $key
	 * @param mixed $default
	 * @return mixed
	 *
	 */
	protected function cfg(array $data, $key, $default = '') {
		return isset($data[$key]) ? $data[$key] : $default;
	}

	/**
	 * Create a config Inputfield with the common attributes set
	 *
	 * @param string $type Inputfield module name
	 * @param string $name
	 * @param string $label
	 * @param int $columnWidth 0 for full width
	 * @param mixed $value Value to set, or null to skip
	 * @return Inputfield
	 *
	 */
	protected function configField($type, $name, $label, $columnWidth = 0, $value = null) {
		$f = $this->wire()->modules->get($type);
		$f->attr('name', $name);
		if($value !== null) $f->attr('value', $value);
		$f->label = $label;
		if($columnWidth) {
			$f->columnWidth = $columnWidth;
			$f->themeBorder = 'none'; // flat cells inside a per-string row
		}
		return $f;
	}

	/**
	 * One-line summary of a configured row, for its collapsed fieldset label
	 *
	 * @param array $data
	 * @param string $key rowKey
	 * @return string
	 *
	 */
	protected function rowSummary(array $data, $key) {
		$op = $this->cfg($data, "op_$key", 'append');
		$mark = trim((string) $this->cfg($data, "mark_$key"));
		$part = trim((string) $this->cfg($data, "part_$key"));
		$tag = (string) $this->cfg($data, "tag_$key");
		$bits = array();

		if($op === 'wrap' || $op === 'both') {
			$what = $part === '' ? $this->_('whole') : '“' . $part . '”';
			$bits[] = $this->_('wrap') . " $what <" . ($tag !== '' ? $tag : 'sub') . '>';
		}
		if(($op === 'append' || $op === 'both') && $mark !== '') {
			$bits[] = $this->_('append') . " $mark" . ($tag !== '' && $op !== 'both' ? " <$tag>" : '');
		}

		$opts = (array) $this->cfg($data, "opts_$key", array());
		$flags = array();
		if(!in_array('whole', $opts, true)) $flags[] = $this->_('partial');
		if(!in_array('case', $opts, true)) $flags[] = $this->_('any-case');
		if(in_array('first', $opts, true)) $flags[] = $this->_('first');

		$summary = $bits ? implode(' + ', $bits) : $this->_('not configured');
		if($flags) $summary .= '  ·  ' . implode(', ', $flags);
		return $summary;
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

		// Strings — one search string per line
		$f = $this->configField('InputfieldTextarea', 'terms', $this->_('Strings'), 0, $data['terms']);
		$f->attr('rows', 6);
		$f->collapsed = Inputfield::collapsedPopulated; // open only while empty
		$f->description = $this->_('One search string per line — nothing else. After saving, configure each string in the table below.');
		$inputfields->add($f);

		// one row of settings per (unique) string
		$terms = array_values(array_unique($this->splitLines($data['terms'])));

		/** @var InputfieldFieldset $table */
		$table = $modules->get('InputfieldFieldset');
		$table->label = $this->_('Per-string settings');
		$table->notes = $this->_('Symbol shortcuts (Mark): `(c)`/`copyright` → © · `(r)`/`reg` → ® · `(tm)`/`tm` → ™ · `(sm)`/`sm` → ℠');

		if(empty($terms)) {
			$note = $modules->get('InputfieldMarkup');
			$note->value = '<p>' . $this->_('Add one or more strings above and save to configure them here.') . '</p>';
			$table->add($note);
		}

		foreach($terms as $term) {
			$key = $this->rowKey($term);
			$saved = isset($data["op_$key"]);

			/** @var InputfieldFieldset $row */
			$row = $modules->get('InputfieldFieldset');
			// configured rows show a one-line summary (operation + options);
			// new rows show just the string and open for editing
			$row->label = $saved ? ($term . '  —  ' . $this->rowSummary($data, $key)) : $term;
			// open only while not yet configured; collapse once saved
			$row->collapsed = $saved ? Inputfield::collapsedYes : Inputfield::collapsedNo;

			$g = $this->configField('InputfieldSelect', "op_$key", $this->_('Operation'), 18);
			$g->addOption('append', $this->_('append after'));
			$g->addOption('wrap', $this->_('wrap inside'));
			$g->addOption('both', $this->_('both'));
			$g->attr('value', $this->cfg($data, "op_$key", 'append'));
			$g->required = true;
			$row->add($g);

			$g = $this->configField('InputfieldText', "mark_$key", $this->_('Mark (append)'), 22, $this->cfg($data, "mark_$key"));
			$g->attr('placeholder', '(r)');
			$g->showIf = "op_$key!=wrap"; // shown for append + both
			$row->add($g);

			$g = $this->configField('InputfieldText', "part_$key", $this->_('Part (wrap)'), 22, $this->cfg($data, "part_$key"));
			$g->attr('placeholder', $this->_('empty = whole word'));
			$g->showIf = "op_$key!=append"; // shown for wrap + both
			$row->add($g);

			$g = $this->configField('InputfieldSelect', "tag_$key", $this->_('Tag'), 14);
			$g->addOption('', $this->_('(none)'));
			foreach(self::$wrapTags as $t) $g->addOption($t, $t);
			$g->attr('value', $this->cfg($data, "tag_$key"));
			$row->add($g);

			// the three match options as ONE multi-checkbox field (not 3 cells)
			$g = $this->configField('InputfieldCheckboxes', "opts_$key", $this->_('Options'), 24);
			$g->addOption('whole', $this->_('Whole word'));
			$g->addOption('case', $this->_('Case'));
			$g->addOption('first', $this->_('First only'));
			$g->optionColumns = 1; // inline (horizontal) list of options
			$g->attr('value', $saved ? (array) $this->cfg($data, "opts_$key", array()) : self::$optsDefault);
			$row->add($g);

			$table->add($row);
		}

		$inputfields->add($table);

		// skip-tags (global)
		$f = $this->configField('InputfieldText', 'skipTags', $this->_('Skip inside these tags'), 0, $data['skipTags']);
		$f->description = $this->_('Text inside these HTML elements (and their descendants) is left untouched. Separate tag names with spaces or commas.');
		$f->notes = $this->_('HTML tags, attributes and comments are always protected regardless of this list. Add `a` here if you do not want link text annotated.');
		$f->placeholder = 'code pre script style';
		$inputfields->add($f);

		return $inputfields;
	}
}
