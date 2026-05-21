<?php
/**
 * Schema Graph for ACF — config per bauxite.fm
 *
 * Deploy: copiare in `wp-content/mu-plugins/schema-graph-config.php`
 * (rinominare, NON tenere il suffisso `-bauxite`).
 *
 * Adattare i nomi di CPT e campi ACF alla realtà del sito prima di andare in
 * produzione (verificare con `wp post-type list` e i field group ACF).
 */

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
