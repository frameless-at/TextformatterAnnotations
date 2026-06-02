# Word Symbols (TextformatterWordSymbols)

A ProcessWire Textformatter that automatically appends configurable symbols —
such as **©**, **®**, **™** or **℠** — to configurable words during output
formatting.

For example, with a mapping of `Frameless = ®`, every output of the word
`Frameless` becomes `Frameless®`.

## Installation

1. Copy the `TextformatterWordSymbols` folder into `/site/modules/`.
2. In the ProcessWire admin go to **Modules → Refresh**, then install
   **Word Symbols**.
3. Edit a text/textarea field, open the **Details** tab and add
   **Word Symbols** to the field's *Text formatters*.

## Configuration

Open the module configuration (**Modules → Configure → Word Symbols**) and
define one mapping per line in the format `word = symbol`:

```
Frameless   = ®
ProcessWire = (tm)
ACME        = copyright
MyBrand     = ™
```

The symbol can be either a **literal character** (`©`, `®`, `™`, `℠`, or any
other character/string) or one of the following **named shortcuts**:

| Shortcut(s)                     | Symbol |
|---------------------------------|--------|
| `(c)`, `copyright`              | ©      |
| `(r)`, `reg`, `registered`      | ®      |
| `(tm)`, `tm`, `trademark`       | ™      |
| `(sm)`, `sm`, `servicemark`     | ℠      |

### Superscript (per word)

Append `| sup` to a line to wrap that symbol in a `<sup>` tag — set
per mapping, so you can mix superscript and normal symbols:

```
ProcessWire = (tm) | sup     →  ProcessWire<sup>™</sup>
Frameless   = ® | sup        →  Frameless<sup>®</sup>
ACME        = ©              →  ACME©
```

For a `| sup` mapping, an occurrence that already carries the **bare** symbol
(without the wrapper) is upgraded to the superscript form — e.g. an existing
`Frameless®` becomes `Frameless<sup>®</sup>`. An occurrence that is already
wrapped in `<sup>` is left as-is, and a *different* symbol next to the word is
never touched.

### Options

- **Match whole words only** – only complete words are matched (so `ACME`
  will not match inside `ACMElabs`). Unicode-aware boundaries, so accented
  characters (ö, é, …) are handled correctly.
- **Case sensitive** – when enabled, `acme` and `ACME` are treated as
  different words.
- **First occurrence only** – append the symbol only to the first occurrence
  of each word per field value (counted across the whole value, including
  across HTML tags).
- **Skip inside these tags** – text inside the listed HTML elements (and their
  descendants) is left untouched. Default: `code pre script style`. Separate
  tag names with spaces or commas. Add `a` if you do not want link text
  decorated.

## HTML-aware

Replacements are applied to **text content only**. HTML tags, attributes
(`href`, `alt`, `class`, `title`, …) and comments are never modified, so a
brand name inside a URL, an `alt` text or a class name is left alone:

```html
<a href="/frameless">Frameless</a>   →  <a href="/frameless">Frameless®</a>
<img alt="Frameless logo">           →  <img alt="Frameless logo">   (unchanged)
<code>Frameless</code>               →  <code>Frameless</code>        (unchanged)
```

## Notes

- The formatter never appends a symbol twice. If a word is already followed
  by *any* known symbol — the standard marks ©, ®, ™, ℠ or any of your
  configured symbols — it is left unchanged. The check tolerates surrounding
  whitespace and an existing `<sup>` wrapper, so `Frameless©`,
  `Frameless<sup>®</sup>` and `Frameless ®` are all recognised and never get
  a second symbol.
- When matching is case-insensitive, the original casing of the matched word
  is preserved.
- Anything between `<` and `>` is treated as markup. In plain-text fields a
  literal `a < b` may therefore be skipped; on rich-text/HTML fields (the
  intended use) this is exactly the desired behaviour.

## License

Released under the MIT License. See `LICENSE`.
