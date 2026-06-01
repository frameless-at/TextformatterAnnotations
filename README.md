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

### Options

- **Match whole words only** – only complete words are matched (so `ACME`
  will not match inside `ACMElabs`). Unicode-aware boundaries, so accented
  characters (ö, é, …) are handled correctly.
- **Case sensitive** – when enabled, `acme` and `ACME` are treated as
  different words.
- **First occurrence only** – append the symbol only to the first occurrence
  of each word per field value.

## Notes

- The formatter never appends a symbol twice. If a word is already followed
  (optionally after whitespace) by *any* known symbol — the standard marks
  ©, ®, ™, ℠ or any of your configured symbols — it is left unchanged. So
  `Frameless©` never becomes `Frameless©©` or `Frameless©®`.
- When matching is case-insensitive, the original casing of the matched word
  is preserved.

## License

Released under the MIT License. See `LICENSE`.
