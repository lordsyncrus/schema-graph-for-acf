# Schema Graph — Plugin generico ACF → JSON-LD

> Brief operativo per Claude Code.
> Plugin WordPress (MU-plugin) che genera dati strutturati schema.org su qualsiasi Custom Post Type, mappando campi ACF in modo **dichiarativo via config**.
> Obiettivo: un **core unico** riutilizzabile su più siti (`angapp.it`, `bauxite.fm`), dove l'unica cosa che cambia tra un sito e l'altro è il **file di configurazione**.

---

## 1. Principio di design

Due livelli nettamente separati:

| Livello   | File                                      | Cambia tra siti? | Cosa fa                                                        |
|-----------|-------------------------------------------|------------------|---------------------------------------------------------------|
| **Core**  | `schema-graph.php`                        | ❌ identico       | Motore generico: legge la config, risolve i valori, stampa JSON-LD, gestisce Yoast/og:type |
| **Config**| `schema-graph-config.php`                 | ✅ per-sito       | Dichiara: quali CPT, che `@type` schema, mappa campi → proprietà |

Il core espone un filtro `schema_graph/config`. Ogni sito si aggancia al filtro e fornisce la propria mappa. Il core di per sé, senza config, non fa nulla.

**Vantaggio:** deployi lo stesso `schema-graph.php` ovunque (anche da un repo condiviso/submodule); per Angapp e Bauxite scrivi solo due `schema-graph-config.php` diversi.

---

## 2. Struttura file

```
wp-content/mu-plugins/
├── schema-graph.php           # CORE — identico su tutti i siti
└── schema-graph-config.php    # CONFIG — specifico per questo sito
```

> Entrambi nella root di `mu-plugins/`: WordPress carica i file `.php` direttamente in quella cartella (non le sottocartelle). Il core non dipende dall'ordine di caricamento perché legge la config in modo lazy.

---

## 3. La grammatica di mapping (reference)

Ogni proprietà schema, nella config, ha una **sorgente di valore**. Le sorgenti possibili:

### 3.1 Token dinamici (stringhe che iniziano con `:`)
| Token        | Valore restituito                                  |
|--------------|----------------------------------------------------|
| `:title`     | Titolo del post                                    |
| `:permalink` | Permalink del post                                 |
| `:thumbnail` | URL immagine in evidenza (full)                    |
| `:excerpt`   | Excerpt (testo pulito)                             |
| `:content`   | Contenuto (testo pulito)                           |
| `:home`      | `home_url()`                                       |
| `:site_name` | Nome del sito                                      |

### 3.2 Nome campo ACF (stringa senza `:`)
Qualsiasi altra stringa è interpretata come **nome di un campo ACF** → `get_field()`.
Es.: `'foundingDate' => 'founding_date'`.

### 3.3 Direttive (array associativo con chiave riservata)
| Direttiva    | Sintassi                                                                 | Cosa fa                                                                 |
|--------------|--------------------------------------------------------------------------|-------------------------------------------------------------------------|
| `static`     | `['static' => <valore>]`                                                 | Valore fisso. Eventuali token `:` interni vengono risolti.              |
| `fallback`   | `['fallback' => [':excerpt', ':content']]`                               | Primo valore non vuoto della lista.                                     |
| `acf`+`split`| `['acf' => 'genres', 'split' => true]`                                   | Campo ACF; se stringa CSV, la splitta in array.                         |
| `collect`    | `['collect' => ['spotify_url', 'instagram_url', ...]]`                   | Raccoglie più campi ACF in un array, scartando i vuoti (ideale `sameAs`).|
| `relation`   | `['relation' => 'releases', 'as_type' => 'MusicAlbum', 'id_suffix' => '#album']` | Relazione ACF → array di sub-nodi `{@type, @id, name, url}`. Aggiungi `'single' => true` per relazioni singole (ritorna oggetto, non array). |

I campi vuoti / `null` / `[]` vengono automaticamente esclusi dall'output finale.

---

## 4. Codice: `wp-content/mu-plugins/schema-graph.php` (CORE — non modificare per-sito)

```php
<?php
/**
 * Plugin Name: Schema Graph (generic ACF → JSON-LD)
 * Description: Genera JSON-LD schema.org sui CPT mappando campi ACF in modo dichiarativo. Config per-sito via filtro 'schema_graph/config'.
 * Version: 2.0.0
 * Author: 3Heads Agency
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Schema_Graph {

    /** @var array<string,array>|null cpt => definizione */
    private $config = null;

    public function __construct() {
        add_action( 'wp_head', [ $this, 'output' ], 5 );
        add_filter( 'wpseo_schema_graph', [ $this, 'disable_yoast' ], 10, 2 );
        add_filter( 'wpseo_opengraph_type', [ $this, 'og_type' ] );
    }

    /** Carica la config (lazy) dal filtro per-sito. */
    private function config() {
        if ( $this->config === null ) {
            $this->config = (array) apply_filters( 'schema_graph/config', [] );
        }
        return $this->config;
    }

    private function def_for_current() {
        $config = $this->config();
        $pt = get_post_type();
        return $config[ $pt ] ?? null;
    }

    /** Stampa il JSON-LD sul singolo CPT mappato. */
    public function output() {
        if ( ! is_singular() ) return;
        $post = get_queried_object();
        $config = $this->config();
        if ( empty( $config[ $post->post_type ] ) ) return;

        $node = $this->build_node( $post, $config[ $post->post_type ] );
        if ( $node ) {
            echo "\n<script type=\"application/ld+json\">"
               . wp_json_encode( $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
               . "</script>\n";
        }
    }

    /** Costruisce un nodo schema da post + definizione. */
    private function build_node( $post, $def ) {
        $node = [
            '@context' => 'https://schema.org',
            '@type'    => $def['type'],
        ];

        if ( ! empty( $def['id_suffix'] ) ) {
            $node['@id'] = get_permalink( $post ) . $def['id_suffix'];
        }

        foreach ( ( $def['map'] ?? [] ) as $prop => $source ) {
            $value = $this->resolve( $source, $post );
            if ( $this->not_empty( $value ) ) {
                $node[ $prop ] = $value;
            }
        }

        return array_filter( $node, [ $this, 'not_empty' ] );
    }

    /** Risolve una sorgente di valore. */
    private function resolve( $source, $post ) {

        if ( is_string( $source ) ) {
            return $this->resolve_scalar( $source, $post );
        }

        if ( is_array( $source ) ) {

            if ( array_key_exists( 'static', $source ) ) {
                return $this->resolve_deep( $source['static'], $post );
            }

            if ( isset( $source['fallback'] ) ) {
                foreach ( (array) $source['fallback'] as $candidate ) {
                    $v = $this->resolve_scalar( $candidate, $post );
                    if ( $this->not_empty( $v ) ) return $v;
                }
                return null;
            }

            if ( isset( $source['acf'] ) ) {
                $v = $this->acf( $source['acf'], $post->ID );
                if ( ! empty( $source['split'] ) && is_string( $v ) ) {
                    return array_values( array_filter( array_map( 'trim', explode( ',', $v ) ) ) );
                }
                return $v;
            }

            if ( isset( $source['collect'] ) ) {
                $vals = array_map( function( $f ) use ( $post ) {
                    return $this->acf( $f, $post->ID );
                }, (array) $source['collect'] );
                $vals = array_values( array_filter( $vals ) );
                return $vals ?: null;
            }

            if ( isset( $source['relation'] ) ) {
                $related = $this->acf( $source['relation'], $post->ID );
                if ( ! $related ) return null;
                $related = is_array( $related ) ? $related : [ $related ];
                $items = array_map( function( $rel ) use ( $source ) {
                    $rel_id = is_object( $rel ) ? $rel->ID : (int) $rel;
                    $item = [
                        '@type' => $source['as_type'],
                        'name'  => get_the_title( $rel_id ),
                        'url'   => get_permalink( $rel_id ),
                    ];
                    if ( ! empty( $source['id_suffix'] ) ) {
                        $item['@id'] = get_permalink( $rel_id ) . $source['id_suffix'];
                    }
                    return array_filter( $item, [ $this, 'not_empty' ] );
                }, $related );
                if ( ! empty( $source['single'] ) ) {
                    return $items[0] ?? null;
                }
                return array_values( $items );
            }
        }

        return null;
    }

    /** Token dinamici o singolo campo ACF. */
    private function resolve_scalar( $token, $post ) {
        switch ( $token ) {
            case ':title':     return get_the_title( $post );
            case ':permalink': return get_permalink( $post );
            case ':thumbnail': return get_the_post_thumbnail_url( $post, 'full' ) ?: null;
            case ':excerpt':   return $post->post_excerpt ? wp_strip_all_tags( $post->post_excerpt ) : null;
            case ':content':   return $post->post_content ? wp_strip_all_tags( $post->post_content ) : null;
            case ':home':      return home_url();
            case ':site_name': return get_bloginfo( 'name' );
        }
        return $this->acf( $token, $post->ID );
    }

    /** Risolve token dentro valori statici annidati. */
    private function resolve_deep( $value, $post ) {
        if ( is_string( $value ) && isset( $value[0] ) && $value[0] === ':' ) {
            return $this->resolve_scalar( $value, $post );
        }
        if ( is_array( $value ) ) {
            return array_map( function( $v ) use ( $post ) {
                return $this->resolve_deep( $v, $post );
            }, $value );
        }
        return $value;
    }

    /** Wrapper sicuro su get_field. */
    private function acf( $field, $post_id ) {
        if ( ! function_exists( 'get_field' ) ) return null;
        $v = get_field( $field, $post_id );
        return ( $v === '' || $v === false ) ? null : $v;
    }

    private function not_empty( $v ) {
        return $v !== null && $v !== '' && $v !== [];
    }

    /** Disabilita il graph Yoast sui CPT che gestiamo noi (evita duplicati). */
    public function disable_yoast( $graph, $context ) {
        return $this->def_for_current() ? [] : $graph;
    }

    /** og:type configurabile per CPT (default 'profile'). */
    public function og_type( $type ) {
        $def = $this->def_for_current();
        return $def ? ( $def['og_type'] ?? 'profile' ) : $type;
    }
}

new Schema_Graph();
```

---

## 5. Config per-sito: `wp-content/mu-plugins/schema-graph-config.php`

> **Questo è l'unico file che cambia tra Angapp e Bauxite.**
> Adattare nomi CPT e nomi campi ACF alla realtà di ciascun sito (verificarli prima — vedi §7).

### 5.1 Esempio Bauxite (`bauxite.fm`)

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'schema_graph/config', function( $config ) {

    // CPT artista → MusicGroup
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

    // CPT release → MusicAlbum
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

### 5.2 Esempio Angapp (`angapp.it`)

```php
<?php
if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'schema_graph/config', function( $config ) {

    // ADATTARE: nomi CPT e campi ACF reali di Angapp.
    $config['artist'] = [   // <- verificare slug CPT su Angapp
        'type'      => 'MusicGroup',
        'id_suffix' => '#artist',
        'og_type'   => 'profile',
        'map'       => [
            'name'        => ':title',
            'url'         => ':permalink',
            'image'       => ':thumbnail',
            'description' => [ 'fallback' => [ ':excerpt', ':content' ] ],
            'sameAs'      => [ 'collect' => [
                'spotify_url', 'apple_music_url', 'instagram_url',
                'wikidata_url', 'musicbrainz_url',
            ] ],
            'recordLabel' => [ 'static' => [
                '@type' => 'MusicLabel',
                'name'  => 'Angapp Records',   // <- nome label corretto
                'url'   => ':home',
            ] ],
        ],
    ];

    return $config;
} );
```

> Il **core (`schema-graph.php`) è identico** su entrambi i siti. Conviene tenerlo in un repo Git condiviso (vedi §8).

---

## 6. Setup campi ACF

I nomi dei field ACF devono coincidere ESATTAMENTE con quelli usati nella config.

### Field Group "Artist Schema" → CPT artista (tutti opzionali)
`real_name` (text), `genres` (text CSV o checkbox), `founding_date` (date), `spotify_url`, `apple_music_url`, `instagram_url`, `facebook_url`, `youtube_url`, `bandcamp_url`, `wikidata_url`, `musicbrainz_url` (url), `releases` (relationship → CPT release, multiplo).

### Field Group "Release Schema" → CPT release (tutti opzionali)
`release_date` (date), `num_tracks` (number), `artist` (relationship → CPT artista, singolo).

> Se i due siti hanno field group diversi, va bene: basta che la config di ciascun sito rispecchi i nomi reali dei suoi campi.

---

## 7. Task per Claude Code

> **PRIMO STEP OBBLIGATORIO su OGNI sito:** verificare nomi reali di CPT e campi ACF. Non assumere `artist` / `music`.

```bash
# CPT registrati
wp post-type list --fields=name,label,public

# Esempi post per ispezionare slug
wp post list --post_type=artist --fields=ID,post_title,post_name
```

### Checklist (ripetere per Angapp e Bauxite)
- [ ] Verificare nomi CPT reali e adattare le chiavi della config.
- [ ] Verificare/creare i campi ACF e allineare i nomi alla config.
- [ ] Creare la cartella `wp-content/mu-plugins/` se assente.
- [ ] Copiare `schema-graph.php` (core, identico).
- [ ] Creare `schema-graph-config.php` specifico del sito.
- [ ] Popolare i campi ACF per un post pilota con URL ufficiali reali.
- [ ] Validare il JSON-LD (vedi §9).
- [ ] Verificare assenza di doppioni schema con Yoast attivo (`disable_yoast`).
- [ ] Inizializzare/aggiornare il repo Git.

---

## 8. Versioning e deploy multi-sito

Strategia consigliata: **un solo repo per il core**, deployato su entrambi i siti.

- Repo `schema-graph/` contenente `schema-graph.php` (+ README).
- Su ciascun sito: deploy del core via `git pull` / submodule / rsync.
- La config (`schema-graph-config.php`) vive nel sito (o in un repo per-sito), perché è specifica.
- Aggiornare il core una volta → propaga a tutti i siti senza riscrivere la logica.

---

## 9. Test e validazione

1. **Schema Markup Validator:** https://validator.schema.org/ (incollare URL pagina)
2. **Rich Results Test:** https://search.google.com/test/rich-results
3. **Controllo duplicati / output:**

```bash
curl -s https://bauxite.fm/artist/ti/ | grep -A2 'application/ld+json'
curl -s https://angapp.it/...        | grep -A2 'application/ld+json'
```

4. **Search Console** (entrambe le proprietà): monitorare la sezione "Dati strutturati".

---

## 10. Estendibilità (note)

- Il motore non è legato alla musica: qualsiasi CPT → qualsiasi `@type` schema.org. Esempi futuri: `event` → `MusicEvent`, `staff` → `Person`, `label` → `MusicLabel`/`Organization`, `post` → `Article`.
- Per un nuovo tipo basta aggiungere una voce alla config; il core non si tocca.
- Se servisse una direttiva nuova (es. cast a intero, concatenazione), si estende il metodo `resolve()` nel core in modo retro-compatibile.

---

## 11. Compatibilità

- I filtri `wpseo_schema_graph` e `wpseo_opengraph_type` richiedono **Yoast** attivo. Se un sito usa un altro SEO plugin (Rank Math, AIOSEO), adattare/rimuovere questi due hook.
- `get_field()` richiede **ACF** attivo: il wrapper `acf()` nel core fa `function_exists()` e non genera errori fatali se ACF manca (le proprietà relative restano semplicemente vuote).
- `wp_json_encode` con `JSON_UNESCAPED_UNICODE` per non spezzare accenti/caratteri italiani nel JSON-LD.

---

## 12. Step strategico parallelo (Knowledge Graph)

Creare le voci **Wikidata** degli artisti chiave e popolarne `wikidata_url`.
Wikidata è il segnale più forte per generare i Knowledge Panel musicali: collegarla a MusicBrainz, Spotify e al sito ufficiale tramite `sameAs` / external identifiers.
