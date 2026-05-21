# Schema Graph for ACF

Motore generico **WordPress** che trasforma campi **ACF** in **JSON-LD schema.org**, mappando qualsiasi Custom Post Type in modo **dichiarativo** tramite un file di configurazione.

Un solo core, riusabile su più siti: cambia solo la config.

[![PHP](https://img.shields.io/badge/PHP-%E2%89%A57.4-777BB4)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-MU--plugin-21759B)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

---

## Perché

I plugin SEO mainstream (Yoast, Rank Math) gestiscono male i CPT custom con relazioni ACF non banali. Scrivere JSON-LD a mano in `functions.php` è fragile e si ripete da sito a sito.

`Schema Graph for ACF` separa nettamente **motore** e **mappatura**:

| Livello | File | Cambia tra siti? | Cosa fa |
|---------|------|------------------|---------|
| **Core** | `schema-graph.php` | identico | Legge la config, risolve i valori ACF, stampa il JSON-LD in `wp_head`, neutralizza il graph di Yoast sui CPT mappati |
| **Config** | `schema-graph-config.php` | per-sito | Dichiara: quali CPT, che `@type` schema, mappa campi → proprietà |

Il core senza config non fa niente. La config senza il core nemmeno. Si comunicano via il filtro `schema_graph/config`.

---

## Requisiti

- WordPress 5.5+
- PHP 7.4+
- [Advanced Custom Fields](https://www.advancedcustomfields.com/) (Free o Pro)
- Yoast SEO *(opzionale — solo per la disattivazione automatica del graph duplicato)*

---

## Installazione

Pensato come **MU-plugin**, così è sempre attivo e non disinstallabile dalla bacheca:

```
wp-content/mu-plugins/
├── schema-graph.php           # CORE — identico su tutti i siti
└── schema-graph-config.php    # CONFIG — specifico del sito
```

> WordPress carica i `.php` direttamente nella root di `mu-plugins/` (non le sottocartelle). Il core legge la config in modo lazy, quindi non c'è ordine di caricamento da rispettare.

In alternativa puoi installarlo come plugin standard in `wp-content/plugins/schema-graph-for-acf/` e attivarlo dalla bacheca.

---

## Quick start

Crea `wp-content/mu-plugins/schema-graph-config.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'schema_graph/config', function( $config ) {

    $config['artist'] = [                  // slug del CPT
        'type'      => 'MusicGroup',       // @type schema.org
        'id_suffix' => '#artist',          // genera @id = permalink + suffix
        'og_type'   => 'profile',          // og:type per questo CPT
        'map'       => [
            'name'        => ':title',
            'url'         => ':permalink',
            'image'       => ':thumbnail',
            'description' => [ 'fallback' => [ ':excerpt', ':content' ] ],
            'sameAs'      => [ 'collect' => [
                'spotify_url', 'instagram_url', 'wikidata_url',
            ] ],
        ],
    ];

    return $config;
} );
```

Apri un post del CPT `artist` e ispeziona l'HTML: troverai uno `<script type="application/ld+json">…</script>` valido.

---

## Grammatica di mapping

Ogni proprietà schema, nella config, ha una **sorgente di valore**.

### Token dinamici

Stringhe che iniziano con `:` — risolte automaticamente dal core.

| Token | Restituisce |
|-------|-------------|
| `:title` | Titolo del post |
| `:permalink` | Permalink |
| `:thumbnail` | URL dell'immagine in evidenza (size `full`) |
| `:excerpt` | Excerpt, testo pulito |
| `:content` | Contenuto, testo pulito |
| `:home` | `home_url()` |
| `:site_name` | Nome del sito |

### Nome campo ACF

Qualsiasi altra stringa è il **nome di un campo ACF** passato a `get_field()`:

```php
'foundingDate' => 'founding_date',
```

### Direttive

Array associativi con una chiave riservata.

| Direttiva | Sintassi | Effetto |
|-----------|----------|---------|
| `static` | `[ 'static' => <valore> ]` | Valore fisso. I token `:` interni vengono comunque risolti. |
| `fallback` | `[ 'fallback' => [ ':excerpt', ':content' ] ]` | Primo valore non vuoto della lista. |
| `acf` + `split` | `[ 'acf' => 'genres', 'split' => true ]` | Campo ACF; se è una stringa CSV, viene splittata in array. |
| `collect` | `[ 'collect' => [ 'spotify_url', 'instagram_url' ] ]` | Raccoglie più campi ACF in un array, scartando i vuoti. Ideale per `sameAs`. |
| `relation` | `[ 'relation' => 'releases', 'as_type' => 'MusicAlbum', 'id_suffix' => '#album' ]` | Relationship ACF → array di sub-nodi `{ @type, @id, name, url }`. Aggiungi `'single' => true` per relazioni 1:1 (restituisce un oggetto invece di un array). |

I valori `null`, `''` e `[]` vengono **automaticamente esclusi** dall'output finale.

---

## Esempio completo

```php
add_filter( 'schema_graph/config', function( $config ) {

    // CPT artist → MusicGroup
    $config['artist'] = [
        'type'      => 'MusicGroup',
        'id_suffix' => '#artist',
        'og_type'   => 'profile',
        'map'       => [
            'name'          => ':title',
            'url'           => ':permalink',
            'image'         => ':thumbnail',
            'description'   => [ 'fallback' => [ ':excerpt', ':content' ] ],
            'alternateName' => 'real_name',
            'genre'         => [ 'acf' => 'genres', 'split' => true ],
            'foundingDate'  => 'founding_date',
            'sameAs'        => [ 'collect' => [
                'spotify_url', 'apple_music_url', 'instagram_url',
                'facebook_url', 'youtube_url', 'bandcamp_url',
                'wikidata_url', 'musicbrainz_url',
            ] ],
            'recordLabel'   => [ 'static' => [
                '@type' => 'MusicLabel',
                'name'  => 'Bauxite',
                'url'   => ':home',
            ] ],
            'album'         => [
                'relation'  => 'releases',
                'as_type'   => 'MusicAlbum',
                'id_suffix' => '#album',
            ],
        ],
    ];

    // CPT music → MusicAlbum
    $config['music'] = [
        'type'      => 'MusicAlbum',
        'id_suffix' => '#album',
        'og_type'   => 'profile',
        'map'       => [
            'name'          => ':title',
            'url'           => ':permalink',
            'image'         => ':thumbnail',
            'datePublished' => 'release_date',
            'numTracks'     => 'num_tracks',
            'byArtist'      => [
                'relation'  => 'artist',
                'as_type'   => 'MusicGroup',
                'id_suffix' => '#artist',
                'single'    => true,
            ],
        ],
    ];

    return $config;
} );
```

Altri esempi pronti in [`examples/`](./examples).

---

## Compatibilità

- **Yoast SEO**: sui CPT mappati, il graph di Yoast viene neutralizzato (`wpseo_schema_graph` → `[]`) per evitare doppioni. Sui CPT non mappati, Yoast continua a funzionare normalmente.
- **og:type**: configurabile per CPT (default `profile`). Filtro `wpseo_opengraph_type`.
- **Altri plugin SEO** (Rank Math, AIOSEO): non c'è anti-doppione automatico. Disattiva manualmente lo schema sul CPT mappato.
- **ACF mancante**: il wrapper su `get_field()` controlla `function_exists()`, quindi niente errori fatali; le proprietà dipendenti restano semplicemente vuote.

---

## Validazione

- [Schema Markup Validator](https://validator.schema.org/) — incolla l'URL della pagina
- [Rich Results Test](https://search.google.com/test/rich-results)
- Ispezione veloce da terminale:

```bash
curl -s https://tuosito.it/artist/nome-artista/ | grep -A2 'application/ld+json'
```

---

## Architettura — riassunto

```
┌──────────────────────────────┐         ┌────────────────────────────────┐
│  schema-graph-config.php     │         │  schema-graph.php (core)       │
│  ───────────────────────     │         │  ─────────────────────         │
│  add_filter(                 │ ──────► │  apply_filters(                │
│    'schema_graph/config',    │         │    'schema_graph/config', []   │
│    fn($c) => [ 'artist' =>   │         │  );                            │
│      [ 'type' => …,          │         │  → build_node()                │
│        'map'  => […] ] ]     │         │  → JSON-LD in <head>           │
│  );                          │         │                                │
└──────────────────────────────┘         └────────────────────────────────┘
```

- **Lazy load** della config: il filtro viene letto la prima volta che serve.
- **Output** in `wp_head` con priorità 5 (prima di Yoast).
- **Filtraggio ricorsivo dei vuoti** per evitare proprietà schema con `null`.

---

## Estendibilità

Il motore non è legato alla musica. Funziona con qualsiasi `@type` schema.org:

- `event` → `MusicEvent` / `Event`
- `staff` → `Person`
- `label` → `MusicLabel` / `Organization`
- `post` → `Article` / `NewsArticle`

Per un nuovo tipo basta aggiungere una voce alla config: **il core non si tocca**.

Se servisse una nuova direttiva (es. cast a intero, concatenazione, format date), estendi il metodo `resolve()` nel core in modo retro-compatibile.

---

## Deploy multi-sito

Strategia consigliata:

- **Core** (`schema-graph.php`) versionato in questo repo, deployato su ogni sito come MU-plugin (via `git pull`, submodule, rsync, CI).
- **Config** (`schema-graph-config.php`) vive nel sito o in un repo per-sito.
- Aggiorni il core una volta → propaga a tutti i siti senza riscrivere la logica di mapping.

---

## Licenza

[GPL-2.0-or-later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html) — stesso modello di WordPress.

---

## Autore

[3Heads Agency](https://3heads.it)
