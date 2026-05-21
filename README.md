# Schema Graph for ACF

Plugin **WordPress** che trasforma campi **ACF** in **JSON-LD schema.org** con un'interfaccia di admin **no-code**.

Mappa qualsiasi Custom Post Type a un `@type` schema.org scegliendo da menu le proprietà e collegandole ai campi ACF: niente PHP da scrivere.

[![PHP](https://img.shields.io/badge/PHP-%E2%89%A57.4-777BB4)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-plugin-21759B)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)

---

## Cosa fa

- Stampa `<script type="application/ld+json">` nei singoli post dei CPT mappati
- Mappa le proprietà schema.org ai campi ACF tramite UI di admin
- Disattiva automaticamente il graph schema di Yoast sui CPT mappati (no duplicati)
- Personalizza `og:type` per ogni CPT
- Catalogo curato di ~25 `@type` schema.org comuni + free text per i tipi custom

## Requisiti

- WordPress 5.5+
- PHP 7.4+
- [Advanced Custom Fields](https://www.advancedcustomfields.com/) (Free o Pro)
- Yoast SEO *(opzionale — solo per la disattivazione automatica del graph duplicato)*

## Installazione

1. Copia la cartella `schema-graph-for-acf` in `wp-content/plugins/`
2. Attiva il plugin dalla bacheca
3. Vai sul menu **Schema Graph** e configura i tuoi CPT

In alternativa puoi installarlo come MU-plugin in `wp-content/mu-plugins/schema-graph-for-acf/` aggiungendo un loader.

## Uso

### 1. Apri il menu "Schema Graph"

Vedrai la lista di tutti i CPT pubblici registrati, con stato (mappato / non mappato) e `@type` corrente.

### 2. Configura un CPT

Sulla schermata di edit imposti:

- **@type schema.org** — autocomplete sul catalogo curato, free text per tipi custom
- **@id suffix** — appeso al permalink per generare l'`@id` univoco (es. `#artist`)
- **og:type** — default `profile`

E poi costruisci la tabella di **mapping proprietà → sorgente**.

### 3. Mapping: sorgenti disponibili

Per ogni proprietà schema.org scegli da un dropdown il tipo di sorgente:

| Sorgente | Cosa fa |
|----------|---------|
| **Token dinamico** | Valori built-in: `:title`, `:permalink`, `:thumbnail`, `:excerpt`, `:content`, `:home`, `:site_name` |
| **Campo ACF** | Legge un campo ACF per nome. Opzione `split CSV` per esplodere una stringa CSV in array |
| **Static** | Valore fisso (stringa o JSON). I token `:` interni vengono comunque risolti |
| **Fallback** | Lista ordinata: primo valore non vuoto vince |
| **Collect** | Raccoglie più campi ACF in un array, scarta i vuoti (ideale per `sameAs`) |
| **Relation** | Campo ACF relationship → array di sub-nodi `{@type, @id, name, url}`. Flag `single` per relazioni 1:1 |

I valori `null`, `''` e `[]` vengono **esclusi automaticamente** dal JSON-LD finale.

### 4. Live preview

Premi **Genera anteprima**: il plugin pesca un post di esempio del CPT, applica la definition corrente (anche non salvata) e mostra il JSON-LD che verrebbe generato.

## Esempio: CPT `artist` → `MusicGroup`

Configurazione tipica dalla UI:

| Proprietà | Sorgente | Valore |
|-----------|----------|--------|
| `name` | Token | `:title` |
| `url` | Token | `:permalink` |
| `image` | Token | `:thumbnail` |
| `description` | Fallback | `:excerpt`, `:content` |
| `alternateName` | Campo ACF | `real_name` |
| `genre` | Campo ACF | `genres` (split CSV ✓) |
| `foundingDate` | Campo ACF | `founding_date` |
| `sameAs` | Collect | `spotify_url`, `instagram_url`, `wikidata_url`, … |
| `recordLabel` | Static | `{ "@type": "MusicLabel", "name": "Bauxite", "url": ":home" }` |
| `album` | Relation | rel `releases`, as_type `MusicAlbum`, id_suffix `#album` |

Output sul singolo artista:

```json
{
  "@context": "https://schema.org",
  "@type": "MusicGroup",
  "@id": "https://bauxite.fm/artist/ti/#artist",
  "name": "Ti",
  "url": "https://bauxite.fm/artist/ti/",
  "image": "https://bauxite.fm/wp-content/uploads/2024/ti.jpg",
  "description": "…",
  "genre": ["electronic", "ambient"],
  "sameAs": ["https://open.spotify.com/artist/…", "https://instagram.com/…"],
  "recordLabel": { "@type": "MusicLabel", "name": "Bauxite", "url": "https://bauxite.fm" },
  "album": [
    { "@type": "MusicAlbum", "@id": "https://bauxite.fm/music/foo/#album", "name": "Foo", "url": "https://bauxite.fm/music/foo/" }
  ]
}
```

## Architettura

```
┌────────────────────────────────────────┐
│  Admin UI                              │
│  Schema Graph menu → Edit CPT screen   │
└───────────────────┬────────────────────┘
                    ▼ (save / ajax preview)
       wp_options['schema_graph_config']
                    ▼ (read)
       Schema_Graph_Render::output()
                    ▼
       <script type="application/ld+json">…</script>
```

### File layout

```
schema-graph.php                              # bootstrap (header + autoload)
includes/
  class-schema-graph-config-store.php         # CRUD sull'option
  class-schema-graph-render.php               # output JSON-LD, build_node, resolve
  class-schema-graph-catalog.php              # carica schema-catalog.json
  class-schema-graph-acf-introspect.php       # lista campi ACF per CPT
  class-schema-graph-admin.php                # menu, screens, save, ajax preview
  views/
    list.php                                  # tabella CPT
    edit.php                                  # form di mapping
data/
  schema-catalog.json                         # catalogo curato @type + properties
assets/
  admin.css
  admin.js
```

## Validazione

- [Schema Markup Validator](https://validator.schema.org/) — incolla l'URL della pagina
- [Rich Results Test](https://search.google.com/test/rich-results)
- Ispezione veloce da terminale:
  ```bash
  curl -s https://tuosito.it/artist/nome-artista/ | grep -A2 'application/ld+json'
  ```

## Compatibilità

- **Yoast SEO** — su un CPT mappato il graph Yoast viene neutralizzato (`wpseo_schema_graph` → `[]`) per evitare doppioni. Sui CPT non mappati Yoast continua a funzionare normalmente.
- **og:type** — configurabile per CPT via UI (default `profile`).
- **Altri plugin SEO** (Rank Math, AIOSEO) — niente anti-doppione automatico: disattiva manualmente lo schema sul CPT mappato.
- **ACF mancante** — il wrapper su `get_field()` controlla `function_exists()`, niente errori fatali.

## Estendibilità

Il catalogo è in `data/schema-catalog.json`. Per aggiungere nuovi `@type` con le loro proprietà suggerite basta editare quel file: nuova entry e si trovano in autocomplete.

L'utente può comunque scrivere `@type` e nomi proprietà a mano (free text): il catalogo è solo un aiuto, non un vincolo.

## Migrazione da v2.x

La v3.0.0 **rimuove il filtro `schema_graph/config`** in favore di una option gestita da UI. Se in passato avevi una config PHP, riporta i CPT a mano dal menu Schema Graph (operazione una-tantum, pochi minuti per sito).

## Licenza

[GPL-2.0-or-later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html) — stesso modello di WordPress.

## Autore

[3Heads Agency](https://3heads.it)
