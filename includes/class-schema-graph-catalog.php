<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Schema_Graph_Catalog {

    /** @var array|null */
    private static $expanded = null;
    /** @var array|null */
    private static $curated = null;

    private static function load_json( $filename ) {
        $path = SCHEMA_GRAPH_PATH . 'data/' . $filename;
        $raw  = is_readable( $path ) ? file_get_contents( $path ) : '';
        $json = $raw ? json_decode( $raw, true ) : null;
        return is_array( $json ) ? $json : [ 'types' => [] ];
    }

    public static function load() {
        if ( self::$expanded === null ) {
            self::$expanded = self::load_json( 'schema-catalog.json' );
        }
        return self::$expanded;
    }

    public static function load_curated() {
        if ( self::$curated === null ) {
            self::$curated = self::load_json( 'schema-catalog-curated.json' );
        }
        return self::$curated;
    }

    /** Unione dei tipi: i curati prendono precedenza, gli espansi aggiungono. */
    public static function types() {
        $expanded = self::load()['types']         ?? [];
        $curated  = self::load_curated()['types'] ?? [];
        return $curated + $expanded;
    }

    /**
     * Proprietà suggerite per un @type.
     * - Type vuoto → tutte le proprietà schema.org (universo)
     * - Type nel catalogo curato → lista curata (proprietà rilevanti per SEO)
     * - Type solo nell'espanso → lista completa con eredità
     * - Type sconosciuto → tutte le proprietà
     *
     * L'input nella UI resta libero (free text), quindi proprietà extra
     * possono comunque essere inserite a mano.
     */
    public static function properties_for( $type ) {
        if ( $type === '' || $type === null ) {
            return self::all_properties();
        }
        $curated = self::load_curated()['types'] ?? [];
        if ( isset( $curated[ $type ]['properties'] ) ) {
            return $curated[ $type ]['properties'];
        }
        $expanded = self::load()['types'] ?? [];
        if ( isset( $expanded[ $type ]['properties'] ) ) {
            return $expanded[ $type ]['properties'];
        }
        return self::all_properties();
    }

    public static function all_properties() {
        return self::load()['allProperties'] ?? [];
    }

    /** Mappa @type schema.org → og:type Open Graph. Fallback: website. */
    public static function og_type_for( $schema_type ) {
        $map = self::og_type_map();
        return $map[ $schema_type ] ?? 'website';
    }

    public static function og_type_map() {
        return [
            'MusicGroup'        => 'profile',
            'MusicAlbum'        => 'music.album',
            'MusicRecording'    => 'music.song',
            'AudioObject'       => 'music.song',
            'MusicPlaylist'     => 'music.playlist',
            'BroadcastService'  => 'music.radio_station',
            'RadioStation'      => 'music.radio_station',
            'Article'           => 'article',
            'NewsArticle'       => 'article',
            'BlogPosting'       => 'article',
            'Report'            => 'article',
            'Book'              => 'book',
            'Person'            => 'profile',
            'Organization'      => 'profile',
            'LocalBusiness'     => 'profile',
            'MusicLabel'        => 'profile',
            'VideoObject'       => 'video.other',
            'Movie'             => 'video.movie',
            'TVEpisode'         => 'video.episode',
            'TVSeries'          => 'video.tv_show',
        ];
    }

    public static function og_types() {
        return [
            'website'              => __( 'Generico (website)', 'schema-graph-for-acf' ),
            'article'              => __( 'Articolo / blog post', 'schema-graph-for-acf' ),
            'profile'              => __( 'Profilo (persona, gruppo, brand)', 'schema-graph-for-acf' ),
            'book'                 => __( 'Libro', 'schema-graph-for-acf' ),
            'music.song'           => __( 'Brano musicale', 'schema-graph-for-acf' ),
            'music.album'          => __( 'Album musicale', 'schema-graph-for-acf' ),
            'music.playlist'       => __( 'Playlist', 'schema-graph-for-acf' ),
            'music.radio_station'  => __( 'Stazione radio', 'schema-graph-for-acf' ),
            'video.movie'          => __( 'Film', 'schema-graph-for-acf' ),
            'video.episode'        => __( 'Episodio TV', 'schema-graph-for-acf' ),
            'video.tv_show'        => __( 'Serie TV', 'schema-graph-for-acf' ),
            'video.other'          => __( 'Altro video', 'schema-graph-for-acf' ),
        ];
    }

    public static function tokens() {
        return [
            ':title'          => __( 'Titolo del post', 'schema-graph-for-acf' ),
            ':permalink'      => __( 'Permalink', 'schema-graph-for-acf' ),
            ':thumbnail'      => __( 'Immagine in evidenza', 'schema-graph-for-acf' ),
            ':excerpt'        => __( 'Excerpt (testo pulito)', 'schema-graph-for-acf' ),
            ':content'        => __( 'Contenuto (testo pulito)', 'schema-graph-for-acf' ),
            ':date_published' => __( 'Data di pubblicazione (ISO 8601)', 'schema-graph-for-acf' ),
            ':date_modified'  => __( 'Data di modifica (ISO 8601)', 'schema-graph-for-acf' ),
            ':home'           => __( 'Home URL', 'schema-graph-for-acf' ),
            ':site_name'      => __( 'Nome del sito', 'schema-graph-for-acf' ),
            ':site_icon'      => __( 'Icona del sito (Site Icon WP)', 'schema-graph-for-acf' ),
        ];
    }
}
