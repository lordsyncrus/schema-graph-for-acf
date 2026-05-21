<?php
/**
 * Plugin Name: Schema Graph for ACF
 * Plugin URI:  https://github.com/3heads/schema-graph-for-acf
 * Description: Motore generico ACF → JSON-LD schema.org. Mappa qualsiasi CPT/campo ACF in modo dichiarativo via filtro 'schema_graph/config'. Config per-sito separata (tipicamente in mu-plugins).
 * Version:     2.0.0
 * Author:      3Heads Agency
 * License:     GPL-2.0-or-later
 * Text Domain: schema-graph-for-acf
 * Requires PHP: 7.4
 * Requires Plugins: advanced-custom-fields
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
