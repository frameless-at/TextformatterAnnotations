# Annotations (TextformatterAnnotations)

A ProcessWire Textformatter that automatically appends a configurable **mark**
to configurable words during output formatting. The mark can be anything —
a symbol (**©**, **®**, **™**, **℠**), a footnote marker, or any short string —
and can optionally be wrapped in a `<sup>` tag per mapping.

Examples:

- `Frameless = ®` → every `Frameless` becomes `Frameless®`
- `Term = 1 | sup` → the first `Term` becomes `Term<sup>1</sup>` (footnote)
- `H2O == 2 | sub` → every `H2O` becomes `H<sub>2</sub>O`

## Why not Find/Replace?

ProcessWire's [TextformatterFindReplace](https://processwire.com/modules/textformatter-find-replace/)
is a general find/replace engine (`str_replace` / `preg_replace`) and can also
append a symbol after a word — if you write and maintain the regex yourself.

This module is a higher-level, semantic tool for *annotating words*. Its value
is the robustness layer that a raw find/replace does not give you:

- **HTML-aware** — never touches text inside tags, attributes, comments or
  configured skip-tags; e-mail addresses are protected.
- **Idempotent** — never adds a second mark, recognising the literal symbol,
  its entity forms (`&copy;`, `&COPY;`, `&#169;`, `&#xA9;`) and an existing
  `<sup>` wrapper.
- **Normalises** an existing mark to the mapping's wrap setting (wrap/unwrap),
  keeping its spelling.
- **First occurrence only** that also strips marks from later occurrences —
  useful for footnotes.
- **Longest match wins** across overlapping, multi-word definitions.
- **Editor-friendly config** (`Word = mark | sup`), no regex knowledge needed.

Use **Find/Replace** for arbitrary one-off text/markup transforms (domain
swaps, tag conversion, generic regex). Use **Annotations** for consistent,
idempotent, HTML-safe symbol/footnote annotation.

## Installation

1. Copy the `TextformatterAnnotations` folder into `/site/modules/`.
2. In the ProcessWire admin go to **Modules → Refresh**, then install
   **Annotations**.
3. Edit a text/textarea field, open the **Details** tab and add
   **Annotations** to the field's *Text formatters*.

## Configuration

Open the module configuration (**Modules → Configure → Annotations**) and
define one mapping per line in the format `word = mark`:

```
Frameless   = ®
Term        = 1 | sup
ProcessWire = (tm)
MyBrand     = ™
```

There are two operators:

- **`word = mark`** — append `mark` after the word.
- **`word == find | sub`** — inside the word, wrap occurrences of `find` in a
  `<sub>` tag (use `| sup` for `<sup>`). Example: `H2O == 2 | sub` →
  `H<sub>2</sub>O`, `m2 == 2 | sup` → `m<sup>2</sup>`. This is naturally
  idempotent — once wrapped, the literal word no longer matches.

Words may contain spaces, and overlapping definitions are supported: the
**longest matching phrase wins**. With both `frameless` and `frameless Media`
defined, `frameless Media` gets its own mark while a standalone `frameless`
gets the other.

For the append operator, the mark is any text. For convenience a few **symbol
shortcuts** are recognised:

| Shortcut(s)                     | Symbol |
|---------------------------------|--------|
| `(c)`, `copyright`              | ©      |
| `(r)`, `reg`, `registered`      | ®      |
| `(tm)`, `tm`, `trademark`       | ™      |
| `(sm)`, `sm`, `servicemark`     | ℠      |

### Superscript (per word)

Append `| sup` to a line to wrap the mark in a `<sup>` tag — set per mapping,
so you can mix superscript and inline marks:

```
Term        = 1 | sup        →  Term<sup>1</sup>
ProcessWire = (tm) | sup     →  ProcessWire<sup>™</sup>
ACME        = ©              →  ACME©
```

The mapping's wrap setting is authoritative — an existing mark is normalised to
it, keeping its spelling:

- **`| sup` mapping:** a bare mark is wrapped — `Frameless&reg;` →
  `Frameless<sup>&reg;</sup>`.
- **plain mapping:** an existing `<sup>` wrapper is **unwrapped** —
  `Frameless<sup>&reg;</sup>` → `Frameless&reg;`.

A *different* mark next to the word is never touched.

### Options

- **Match whole words only** – only complete words are matched (so `ACME`
  will not match inside `ACMElabs`). Unicode-aware boundaries, so accented
  characters (ö, é, …) are handled correctly.
- **Case sensitive** – when enabled, `acme` and `ACME` are treated as
  different words.
- **First occurrence only** – the word carries the mark exactly once, on its
  first occurrence. That occurrence is annotated (an existing mark there is
  kept and normalised); every later occurrence has its mark **removed** —
  including marks already present in the source (`©`, `&copy;`, `<sup>…</sup>`).
  Useful for footnotes (mark only the first mention). Protected regions
  (attributes, e-mails, skip-tags) are ignored when finding occurrences.
- **Skip inside these tags** – text inside the listed HTML elements (and their
  descendants) is left untouched. Default: `code pre script style`. Separate
  tag names with spaces or commas. Add `a` if you do not want link text
  annotated.

## HTML-aware

Replacements are applied to **text content only**. HTML tags, attributes
(`href`, `alt`, `class`, `title`, …) and comments are never modified, so a word
inside a URL, an `alt` text or a class name is left alone:

```html
<a href="/frameless">Frameless</a>   →  <a href="/frameless">Frameless®</a>
<img alt="Frameless logo">           →  <img alt="Frameless logo">   (unchanged)
<code>Frameless</code>               →  <code>Frameless</code>        (unchanged)
```

## Notes

- **E-mail addresses are protected.** A configured word that is part of an
  address is left untouched, e.g. with `Frameless = ®` the text
  `info@frameless.at` stays as-is (no `info@frameless®.at`).
- The formatter never adds a mark twice. If a word is already followed by its
  mark — tolerating surrounding whitespace and an existing `<sup>` wrapper — it
  is normalised rather than duplicated.
- **Symbol entity forms are recognised.** For the symbol shortcuts, the named
  entity in lower *and* upper case (`&reg;`/`&REG;`, `&copy;`/`&COPY;`,
  `&trade;`/`&TRADE;`) and numeric references (`&#174;`, `&#xAE;`, with leading
  zeros or either hex case) count as the symbol, so `Frameless&reg;` is never
  turned into `Frameless®&reg;`.
- When matching is case-insensitive, the original casing of the matched word
  is preserved.
- Anything between `<` and `>` is treated as markup. In plain-text fields a
  literal `a < b` may therefore be skipped; on rich-text/HTML fields (the
  intended use) this is exactly the desired behaviour.

## License

Released under the MIT License. See `LICENSE`.
