# Schema Graph for ACF

A **WordPress plugin** that turns **ACF** fields into **schema.org JSON-LD** through a **no-code admin UI**.

Map any Custom Post Type to a `schema.org @type` by picking properties from a menu and linking them to ACF fields — no PHP required.

[![PHP](https://img.shields.io/badge/PHP-%E2%89%A57.4-777BB4)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-plugin-21759B)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

---

## What it does

- Emits `<script type="application/ld+json">` on singular posts of mapped CPTs.
- Maps schema.org properties to ACF fields via an admin UI — no code.
- Curated catalog (~25 common `@type`s with hand-picked SEO properties) + the full schema.org vocabulary (~922 types) as fallback.
- Auto-derives `@id` (`permalink + #<cpt_slug>`) and pre-fills `og:type` from the chosen `@type`.
- Defuses parallel JSON-LD emitters on mapped CPTs:
  - **Yoast SEO**: `wpseo_schema_graph` → `[]`
  - **ACF Pro 6.x native schema**: `acf/schema/disable_output` → `true`

## Requirements

- WordPress 5.5+
- PHP 7.4+
- [Advanced Custom Fields](https://www.advancedcustomfields.com/) (Free or Pro)
- Yoast SEO *(optional — only for the automatic graph deduplication)*

## Install

1. Copy the `schema-graph-for-acf` folder into `wp-content/plugins/` (or upload the zip from `dist/`).
2. Activate from the WP admin.
3. Go to the **Schema Graph** menu and configure your CPTs.

You can also install it as a must-use plugin under `wp-content/mu-plugins/schema-graph-for-acf/` with a small loader.

## Usage

### 1. Open the "Schema Graph" menu

A list of all registered public CPTs is shown, with mapping status (mapped / not mapped) and the current `@type`.

### 2. Configure a CPT

On the edit screen you set:

- **schema.org `@type`** — autocomplete from the catalog, free text for custom types.
- **`og:type`** — preselected from the `@type` (`MusicAlbum` → `music.album`, `Person` → `profile`, `Article` → `article`, …). You can override it.

Then you build the **property → source** mapping table.

### 3. Mapping sources

For each schema.org property you pick a source kind from a dropdown:

| Source | What it does |
|--------|--------------|
| **Post property** (token) | Built-in values: `:title`, `:permalink`, `:thumbnail`, `:excerpt`, `:content`, `:date_published`, `:date_modified`, `:home`, `:site_name` |
| **ACF field** | Reads an ACF field by name. Optional `split CSV` to explode a CSV string into an array |
| **Static** | Fixed value (string or JSON object). Tokens (`:site_name`) are resolved if used as whole values; `{{:token}}` interpolation works inside strings |
| **Fallback** | Ordered list: first non-empty value wins |
| **Collect** | Combines multiple ACF fields into an array, dropping empties (ideal for `sameAs`) |
| **Relation** | ACF relationship/post_object field → array of typed sub-nodes `{@type, @id, name, url}`. Set `single` for 1:1 relations |

`null`, `''` and `[]` are **automatically stripped** from the final JSON-LD.

### 4. Quality-of-life features

- **Sidebar with two cards** ("Post properties" + "ACF fields available"): click any item to assign it to the active row. Relationship/post_object ACF fields auto-set the **Relation** source kind.
- **Live preview**: click "Generate preview" — the plugin picks a sample post of the CPT, applies the current (even unsaved) definition, and shows the JSON-LD it would produce.
- **Import / Export**: on the list screen, download the full config as JSON or upload a previously exported file. Two import modes:
  - **Merge** (default): add/update CPTs present in the file, leave others untouched.
  - **Replace**: wipe the existing config first.

## Example: `artist` CPT → `MusicGroup`

Typical mapping from the UI:

| Property | Source | Value |
|----------|--------|-------|
| `name` | Post property | `:title` |
| `url` | Post property | `:permalink` |
| `image` | Post property | `:thumbnail` |
| `description` | Fallback | `:excerpt`, `:content` |
| `alternateName` | ACF field | `real_name` |
| `genre` | ACF field | `genres` (split CSV ✓) |
| `foundingDate` | ACF field | `founding_date` |
| `sameAs` | Collect | `spotify_url`, `instagram_url`, `wikidata_url`, … |
| `recordLabel` | Static | `{ "@type": "MusicLabel", "name": ":site_name", "url": ":home" }` |
| `album` | Relation | relation `releases`, as_type `MusicAlbum`, id_suffix `#album` |

Output on the artist page:

```json
{
  "@context": "https://schema.org",
  "@type": "MusicGroup",
  "@id": "https://example.com/artist/foo/#artist",
  "name": "Foo",
  "url": "https://example.com/artist/foo/",
  "image": "https://example.com/wp-content/uploads/2026/foo.jpg",
  "description": "…",
  "genre": ["electronic", "ambient"],
  "sameAs": ["https://open.spotify.com/artist/…", "https://instagram.com/…"],
  "recordLabel": { "@type": "MusicLabel", "name": "Example Label", "url": "https://example.com" },
  "album": [
    { "@type": "MusicAlbum", "@id": "https://example.com/music/foo-album/#album", "name": "Foo Album", "url": "https://example.com/music/foo-album/" }
  ]
}
```

## Architecture

```
┌────────────────────────────────────────┐
│  Admin UI                              │
│  Schema Graph menu → Edit CPT screen   │
└───────────────────┬────────────────────┘
                    ▼ (save / ajax preview)
       wp_options['schema_graph_config']
                    ▼ (read, memoized per request)
       Schema_Graph_Render::output()
                    ▼
       <script type="application/ld+json">…</script>
```

### File layout

```
schema-graph.php                              # bootstrap (header + autoload)
includes/
  class-schema-graph-config-store.php         # option CRUD
  class-schema-graph-render.php               # JSON-LD output, build_node, resolve
  class-schema-graph-catalog.php              # loads schema-catalog*.json
  class-schema-graph-acf-introspect.php       # lists ACF fields per CPT
  class-schema-graph-admin.php                # menu, screens, save, ajax, import/export
  views/
    list.php                                  # CPT list + import/export UI
    edit.php                                  # mapping form
data/
  schema-catalog.json                         # ~922 types from official schema.org vocab
  schema-catalog-curated.json                 # ~25 hand-picked types for common SEO use
assets/
  admin.css
  admin.js
bin/
  build-catalog.php                           # regenerates schema-catalog.json from schema.org CSVs
  build-zip.sh                                # packages dist/<slug>-<version>.zip
```

## Validation

- [Schema Markup Validator](https://validator.schema.org/) — paste the page URL
- [Rich Results Test](https://search.google.com/test/rich-results)
- Quick inspection from the terminal:
  ```bash
  curl -s https://example.com/artist/foo/ | grep -A2 'application/ld+json'
  ```

## Compatibility

- **Yoast SEO** — on a mapped CPT, Yoast's graph is neutralized (`wpseo_schema_graph` → `[]`) to avoid duplicates. Unmapped CPTs are untouched.
- **ACF Pro 6.x native schema** — disabled on mapped CPTs via `acf/schema/disable_output`. Unmapped CPTs keep ACF's output.
- **`og:type`** — configurable per CPT (default derived from `@type`). Filter: `wpseo_opengraph_type`.
- **Other SEO plugins** (Rank Math, AIOSEO) — no automatic deduplication; disable the schema on mapped CPTs manually.
- **ACF missing** — the `get_field()` wrapper checks `function_exists()`, so no fatals; properties depending on ACF will simply be empty.

## Extending the catalog

The catalog lives in `data/schema-catalog.json`, generated from the official schema.org CSV vocabulary. To regenerate after a schema.org release:

```bash
php bin/build-catalog.php
```

The curated subset in `data/schema-catalog-curated.json` is a hand-picked list of common `@type`s with their most SEO-relevant properties. Edit this file to refine which properties the autocomplete suggests for the types you care about.

The property input is **free text**, so any property name not in the catalog can still be typed manually — the catalog only powers autocomplete.

## Building a release zip

```bash
./bin/build-zip.sh
```

Reads the version from the plugin header, runs PHP lint, packages a clean `dist/schema-graph-for-acf-<version>.zip` ready for upload via the WP admin.

## Migrating from v2.x

v3.0.0 **removed the `schema_graph/config` filter** in favor of a UI-managed option. If you had a PHP config, re-create the CPT mappings from the Schema Graph menu (a one-time, few-minutes operation per site). Or export your old config to a JSON file (matching the shape of the new export) and use the import UI.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html) — same as WordPress.
