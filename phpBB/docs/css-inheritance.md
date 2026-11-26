# CSS inheritance

phpBB supports style inheritance for templates and, since this feature, for
stylesheets (CSS) as well. When a style declares a parent style, its CSS is no
longer a separate, self-contained copy of the parent's CSS: instead, component
files are resolved through the inheritance tree, exactly like templates.

This document describes how the mechanism works and how style authors can use
it. It is the source material for the "Inheritance" section of the style
creation guide (`https://www.phpbb.com/styles/create/#a-inheritance`).

## How it works

A theme's CSS is split into an **entry point** and **component files**:

- `theme/stylesheet.css` — the entry point. It is a manifest containing
  `@import` rules that list the component files, for example:

  ```css
  @import url("base.css");
  @import url("colours.css");
  @import url("responsive.css");
  ```

- `theme/{lang}/stylesheet.css` — an optional, language specific entry point
  (e.g. `theme/en/stylesheet.css`), loaded in addition to the main one.

- The component files themselves (`base.css`, `colours.css`, …).

When a style inherits from a parent, phpBB assembles the entry point at request
time by merging the entry points of the whole inheritance chain (child first)
and by resolving every `@import`-ed component file through the tree. This means
a child style only needs to ship the files it actually changes.

### Resolving component files

Every `@import`-ed file is resolved through the inheritance tree, from the most
specific style to its ancestors. The first style that ships the file wins. This
mirrors template inheritance exactly:

- A child style can **override** a component simply by shipping a file with the
  same name (e.g. its own `colours.css`), without touching the manifest.
- A child style can **add** a new component by listing it in its own manifest
  (see below).
- Anything not overridden is inherited from the parent automatically.

### Literal CSS

The entry point may also contain ordinary CSS rules in addition to `@import`
rules. These rules are appended after all imports, so they act as a final
override layer. Rules written directly in the child's `stylesheet.css` will
override the parent's CSS at equal specificity.

## Inheritance modes

The behaviour is controlled by the `theme-inheritance` key in the `extra`
section of a style's `composer.json`:

| Value        | Behaviour                                                                                       |
| ------------ | ----------------------------------------------------------------------------------------------- |
| `merge`      | (default) The child's entry is merged with its ancestors' entries.                              |
| `replace`    | The child's entry completely replaces the ancestors' entries.                                   |

### Merge mode (default)

In merge mode, the import lists of all ancestors are concatenated (deduplicated
by file name, with the most specific style added last) and the literal CSS of
ancestors is emitted before the literal CSS of the child.

To add a file, list it in the child's `stylesheet.css`:

```css
/* styles/my_style/theme/stylesheet.css */
@import url("custom.css");
```

The parent's imports are inherited automatically, and `custom.css` is appended
last. No other files need to be copied.

### Replace mode

When a style wants full control over the composition and order of its
stylesheets, it can opt into replace mode:

```json
{
    "extra": {
        "parent-style": "prosilver",
        "theme-inheritance": "replace"
    }
}
```

In replace mode only the child's `stylesheet.css` is used; the ancestors'
entries are ignored entirely. Component files are still resolved through the
tree, so a file listed in the manifest but not shipped by the child still falls
back to the parent.

## Cache busting

The `?hash=` query strings in `@import` rules are computed automatically from
the resolved file's content. Authors no longer need to maintain these hashes
(or run the build tool) — a stale or missing hash is simply replaced with the
correct one at request time.

The assembled stylesheets are cached on the server and invalidated
automatically when any source file changes, so edits to a style are picked up
without manual work. A board-wide cache purge (in the ACP) also forces a
rebuild.

## Notes and limitations

- Multi-level chains are supported. The `theme-inheritance` mode of each style
  in the chain is honoured: a `replace` style hides the CSS of everything above
  it, including for its own descendants.
- Remote imports (`@import url("https://…")`) are passed through unchanged and
  are not resolved or rewritten.
- Import URLs that are absolute (leading `/`) or `data:` URIs are likewise
  passed through unchanged.
- The order of component files is defined by the manifest. A child style
  overrides a component "in place" (at the position the parent declared it); to
  reorder or remove parent components, use `replace` mode.
- The language specific entry point (`theme/{lang}/stylesheet.css`) follows the
  same merge/replace and per-file fallback rules.
