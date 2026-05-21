<?php
/**
 * Schema Graph for ACF — config per angapp.it
 *
 * Deploy: copiare in `wp-content/mu-plugins/schema-graph-config.php`
 * (rinominare, NON tenere il suffisso `-angapp`).
 *
 * ADATTARE i nomi di CPT e campi ACF alla realtà del sito prima di andare in
 * produzione (verificare con `wp post-type list` e i field group ACF).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_filter( 'schema_graph/config', function( $config ) {

    // ADATTARE lo slug del CPT artista alla realtà di Angapp.
    $config['artist'] = [
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
                'name'  => 'Angapp Records',
                'url'   => ':home',
            ] ],
        ],
    ];

    return $config;
} );
