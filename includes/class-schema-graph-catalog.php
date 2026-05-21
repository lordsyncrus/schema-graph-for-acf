<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Schema_Graph_Catalog {

    /** @var array|null */
    private static $data = null;

    public static function load() {
        if ( self::$data === null ) {
            $path = SCHEMA_GRAPH_PATH . 'data/schema-catalog.json';
            $raw  = is_readable( $path ) ? file_get_contents( $path ) : '';
            $json = $raw ? json_decode( $raw, true ) : null;
            self::$data = is_array( $json ) ? $json : [ 'types' => [] ];
        }
        return self::$data;
    }

    public static function types() {
        $data = self::load();
        return $data['types'] ?? [];
    }

    public static function type_names() {
        return array_keys( self::types() );
    }

    public static function properties_for( $type ) {
        $types = self::types();
        return $types[ $type ]['properties'] ?? [];
    }

    public static function tokens() {
        return [
            ':title'     => __( 'Titolo del post', 'schema-graph-for-acf' ),
            ':permalink' => __( 'Permalink', 'schema-graph-for-acf' ),
            ':thumbnail' => __( 'Immagine in evidenza', 'schema-graph-for-acf' ),
            ':excerpt'   => __( 'Excerpt (testo pulito)', 'schema-graph-for-acf' ),
            ':content'   => __( 'Contenuto (testo pulito)', 'schema-graph-for-acf' ),
            ':home'      => __( 'Home URL', 'schema-graph-for-acf' ),
            ':site_name' => __( 'Nome del sito', 'schema-graph-for-acf' ),
        ];
    }
}
