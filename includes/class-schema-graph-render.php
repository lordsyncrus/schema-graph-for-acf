<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Schema_Graph_Render {

    /** @var Schema_Graph_Config_Store */
    private $store;

    /** @var array|null Cache per-request della definizione del post corrente. */
    private $current_def;
    /** @var bool Flag "definizione corrente già risolta" (distingue null da non-risolta). */
    private $current_def_done = false;

    public function __construct( Schema_Graph_Config_Store $store ) {
        $this->store = $store;

        add_action( 'wp_head', [ $this, 'output' ], 5 );
        add_filter( 'wpseo_schema_graph', [ $this, 'disable_yoast' ], 10, 2 );
        add_filter( 'wpseo_opengraph_type', [ $this, 'og_type' ] );
        // ACF Pro 6.x emette il proprio JSON-LD via acf/schema/data.
        // Disabilitiamo l'output ACF sui CPT che gestiamo noi per evitare doppioni.
        add_filter( 'acf/schema/disable_output', [ $this, 'disable_acf_schema' ], 10, 2 );
    }

    /**
     * Risolve la definizione del CPT corrente una sola volta per request.
     * Hooks coinvolti su una pagina singular: wp_head, wpseo_schema_graph,
     * wpseo_opengraph_type → senza cache faremmo 3 get_option + 3 get_post_type.
     */
    private function def_for_current() {
        if ( $this->current_def_done ) return $this->current_def;
        $this->current_def_done = true;
        $post = is_singular() ? get_queried_object() : null;
        $this->current_def = ( $post instanceof WP_Post )
            ? $this->store->get_for_cpt( $post->post_type )
            : null;
        return $this->current_def;
    }

    public function output() {
        $def = $this->def_for_current();
        if ( ! $def ) return;

        $node = $this->build_node( get_queried_object(), $def );
        if ( $node ) {
            echo "\n<script type=\"application/ld+json\">"
               . wp_json_encode( $node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
               . "</script>\n";
        }
    }

    public function build_node( $post, $def ) {
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

        return $node;
    }

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
                $v = $this->normalize_acf_value( $this->acf( $source['acf'], $post->ID ) );
                if ( ! empty( $source['split'] ) && is_string( $v ) ) {
                    return array_values( array_filter( array_map( 'trim', explode( ',', $v ) ) ) );
                }
                return $v;
            }

            if ( isset( $source['collect'] ) ) {
                $vals = array_map( function( $f ) use ( $post ) {
                    return $this->normalize_acf_value( $this->acf( $f, $post->ID ) );
                }, (array) $source['collect'] );
                $vals = array_values( array_filter( $vals ) );
                return $vals ?: null;
            }

            if ( isset( $source['repeater'] ) ) {
                $sub = $source['subfield'] ?? '';
                if ( $sub === '' ) return null;
                $rows = $this->acf( $source['repeater'], $post->ID );
                if ( ! is_array( $rows ) ) return null;
                $vals = [];
                foreach ( $rows as $row ) {
                    if ( ! is_array( $row ) || ! array_key_exists( $sub, $row ) ) continue;
                    $v = $this->normalize_acf_value( $row[ $sub ] );
                    if ( $this->not_empty( $v ) ) $vals[] = $v;
                }
                return $vals ?: null;
            }

            if ( isset( $source['relation'] ) ) {
                $related = $this->acf( $source['relation'], $post->ID );
                if ( ! $related ) return null;
                $related = is_array( $related ) ? $related : [ $related ];
                $items = array_filter( array_map( function( $rel ) use ( $source ) {
                    $rel_id = is_object( $rel ) ? $rel->ID : (int) $rel;
                    if ( ! $rel_id ) return null;
                    $item = [
                        '@type' => $source['as_type'],
                        'name'  => get_the_title( $rel_id ),
                        'url'   => get_permalink( $rel_id ),
                    ];
                    if ( ! empty( $source['id_suffix'] ) ) {
                        $item['@id'] = get_permalink( $rel_id ) . $source['id_suffix'];
                    }
                    return array_filter( $item, [ $this, 'not_empty' ] );
                }, $related ) );
                if ( ! empty( $source['single'] ) ) {
                    return $items[0] ?? null;
                }
                return array_values( $items );
            }
        }

        return null;
    }

    private function resolve_scalar( $token, $post ) {
        switch ( $token ) {
            case ':title':          return get_the_title( $post );
            case ':permalink':      return get_permalink( $post );
            case ':thumbnail':      return get_the_post_thumbnail_url( $post, 'full' ) ?: null;
            case ':excerpt':        return $post->post_excerpt ? wp_strip_all_tags( $post->post_excerpt ) : null;
            case ':content':        return $post->post_content ? wp_strip_all_tags( $post->post_content ) : null;
            case ':home':           return home_url();
            case ':site_name':      return get_bloginfo( 'name' );
            case ':site_icon':      return function_exists( 'get_site_icon_url' ) ? ( get_site_icon_url() ?: null ) : null;
            case ':date_published': return self::iso8601( $post, 'date' );
            case ':date_modified':  return self::iso8601( $post, 'modified' );
        }
        return $this->acf( $token, $post->ID );
    }

    /** Restituisce data del post in ISO 8601 col fuso WP. Usa il datetime WP-aware. */
    private static function iso8601( $post, $which ) {
        $dt = get_post_datetime( $post, $which );
        return $dt ? $dt->format( 'c' ) : null;
    }

    private function resolve_deep( $value, $post ) {
        if ( is_string( $value ) ) {
            // Stringa che inizia con ':' → token completo (es. ":site_name").
            if ( isset( $value[0] ) && $value[0] === ':' && strpos( $value, '{{' ) === false ) {
                return $this->resolve_scalar( $value, $post );
            }
            // Interpolazione "{{:token}}" dentro stringhe miste.
            if ( strpos( $value, '{{' ) !== false ) {
                return preg_replace_callback( '/\{\{(:[a-z_]+)\}\}/', function( $m ) use ( $post ) {
                    $v = $this->resolve_scalar( $m[1], $post );
                    return is_scalar( $v ) ? (string) $v : '';
                }, $value );
            }
            return $value;
        }
        if ( is_array( $value ) ) {
            return array_map( function( $v ) use ( $post ) {
                return $this->resolve_deep( $v, $post );
            }, $value );
        }
        return $value;
    }

    /** Ritorna il valore RAW del campo ACF (può essere WP_Post per relationship/post_object). */
    private function acf( $field, $post_id ) {
        if ( ! function_exists( 'get_field' ) ) return null;
        $v = get_field( $field, $post_id );
        return ( $v === '' || $v === false ) ? null : $v;
    }

    /**
     * Coerce WP_Post → permalink. Applicato nei source kind `acf` e `collect`
     * (che si aspettano scalari/array di scalari) per evitare di dumpare oggetti
     * WP_Post interi. NON applicato a `relation`, che lavora col WP_Post raw
     * per estrarre `->ID` e produrre sub-nodi tipati.
     */
    private function normalize_acf_value( $v ) {
        if ( $v === null ) return null;
        if ( $v instanceof WP_Post ) {
            return get_permalink( $v->ID );
        }
        if ( is_array( $v ) ) {
            $out = [];
            foreach ( $v as $item ) {
                $n = $this->normalize_acf_value( $item );
                if ( $n !== null && $n !== '' && $n !== [] ) $out[] = $n;
            }
            return $out ? $out : null;
        }
        return $v;
    }

    private function not_empty( $v ) {
        return $v !== null && $v !== '' && $v !== [];
    }

    public function disable_yoast( $graph, $context ) {
        return $this->def_for_current() ? [] : $graph;
    }

    public function disable_acf_schema( $disabled, $context = null ) {
        return $this->def_for_current() ? true : $disabled;
    }

    public function og_type( $type ) {
        $def = $this->def_for_current();
        if ( ! $def ) return $type;
        if ( ! empty( $def['og_type'] ) ) return $def['og_type'];
        return Schema_Graph_Catalog::og_type_for( $def['type'] ?? '' );
    }
}
