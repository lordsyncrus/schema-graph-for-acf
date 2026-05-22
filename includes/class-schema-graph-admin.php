<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Schema_Graph_Admin {

    const MENU_SLUG = 'schema-graph';
    const CAPABILITY = 'manage_options';

    /** @var Schema_Graph_Config_Store */
    private $store;

    public function __construct( Schema_Graph_Config_Store $store ) {
        $this->store = $store;
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'admin_post_schema_graph_save', [ $this, 'handle_save' ] );
        add_action( 'admin_post_schema_graph_reset', [ $this, 'handle_reset' ] );
        add_action( 'wp_ajax_schema_graph_preview', [ $this, 'ajax_preview' ] );
        add_action( 'wp_ajax_schema_graph_properties', [ $this, 'ajax_properties' ] );
    }

    public function register_menu() {
        add_menu_page(
            __( 'Schema Graph', 'schema-graph-for-acf' ),
            __( 'Schema Graph', 'schema-graph-for-acf' ),
            self::CAPABILITY,
            self::MENU_SLUG,
            [ $this, 'render_screen' ],
            'dashicons-share-alt2',
            80
        );
    }

    public function enqueue_assets( $hook ) {
        if ( strpos( (string) $hook, self::MENU_SLUG ) === false ) return;

        wp_enqueue_style(
            'schema-graph-admin',
            SCHEMA_GRAPH_URL . 'assets/admin.css',
            [],
            SCHEMA_GRAPH_VERSION
        );

        wp_enqueue_script(
            'schema-graph-admin',
            SCHEMA_GRAPH_URL . 'assets/admin.js',
            [ 'jquery', 'jquery-ui-sortable' ],
            SCHEMA_GRAPH_VERSION,
            true
        );

        wp_localize_script( 'schema-graph-admin', 'SchemaGraph', [
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'previewNonce' => wp_create_nonce( 'schema_graph_preview' ),
            'propsNonce'   => wp_create_nonce( 'schema_graph_properties' ),
            'tokens'       => Schema_Graph_Catalog::tokens(),
            'ogTypeMap'    => Schema_Graph_Catalog::og_type_map(),
            'i18n'         => [
                'remove' => __( 'Rimuovi', 'schema-graph-for-acf' ),
            ],
        ] );
    }

    public function render_screen() {
        $cpt = isset( $_GET['cpt'] ) ? sanitize_key( $_GET['cpt'] ) : '';
        if ( $cpt ) {
            $this->render_edit_screen( $cpt );
        } else {
            $this->render_list_screen();
        }
    }

    private function render_list_screen() {
        require SCHEMA_GRAPH_PATH . 'includes/views/list.php';
    }

    private function render_edit_screen( $cpt ) {
        $types  = Schema_Graph_ACF_Introspect::public_post_types();
        if ( ! isset( $types[ $cpt ] ) ) {
            wp_die( esc_html__( 'CPT non valido.', 'schema-graph-for-acf' ) );
        }
        $cpt_obj    = $types[ $cpt ];
        $definition = $this->store->get_for_cpt( $cpt ) ?: [
            'type'      => '',
            'id_suffix' => '',
            'og_type'   => 'profile',
            'map'       => new stdClass(),
        ];
        $acf_fields = Schema_Graph_ACF_Introspect::fields_for_cpt( $cpt );

        require SCHEMA_GRAPH_PATH . 'includes/views/edit.php';
    }

    public function handle_save() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Permesso negato.', 'schema-graph-for-acf' ) );
        }

        $cpt = isset( $_POST['cpt'] ) ? sanitize_key( $_POST['cpt'] ) : '';
        check_admin_referer( 'schema_graph_save_' . $cpt );

        $type    = isset( $_POST['type'] )    ? sanitize_text_field( wp_unslash( $_POST['type'] ) )    : '';
        $og_type = isset( $_POST['og_type'] ) ? sanitize_text_field( wp_unslash( $_POST['og_type'] ) ) : 'profile';

        // @id suffix: auto-derivato da slug CPT. Preserva eventuali valori
        // custom già in DB (es. impostati prima manualmente o via filtro).
        $existing  = $this->store->get_for_cpt( $cpt );
        $id_suffix = ! empty( $existing['id_suffix'] ) ? $existing['id_suffix'] : '#' . $cpt;

        $map_raw = isset( $_POST['map'] ) ? wp_unslash( $_POST['map'] ) : '[]';
        $map     = json_decode( $map_raw, true );
        if ( ! is_array( $map ) ) $map = [];

        $definition = [
            'type'      => $type,
            'id_suffix' => $id_suffix,
            'og_type'   => $og_type,
            'map'       => $this->sanitize_map( $map ),
        ];

        if ( empty( $definition['type'] ) ) {
            wp_safe_redirect( add_query_arg(
                [ 'page' => self::MENU_SLUG, 'cpt' => $cpt, 'error' => 'missing_type' ],
                admin_url( 'admin.php' )
            ) );
            exit;
        }

        $this->store->save_for_cpt( $cpt, $definition );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::MENU_SLUG, 'cpt' => $cpt, 'saved' => '1' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    public function handle_reset() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            wp_die( esc_html__( 'Permesso negato.', 'schema-graph-for-acf' ) );
        }
        $cpt = isset( $_POST['cpt'] ) ? sanitize_key( $_POST['cpt'] ) : '';
        check_admin_referer( 'schema_graph_reset_' . $cpt );

        $this->store->delete_for_cpt( $cpt );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::MENU_SLUG, 'reset' => '1' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    /** Normalizza la struttura `map` proveniente dal form. */
    private function sanitize_map( array $map ) {
        $out = [];
        foreach ( $map as $prop => $source ) {
            $prop = sanitize_text_field( $prop );
            if ( $prop === '' ) continue;
            $out[ $prop ] = $this->sanitize_source( $source );
        }
        return $out;
    }

    private function sanitize_source( $source ) {
        if ( is_string( $source ) ) {
            return sanitize_text_field( $source );
        }
        if ( ! is_array( $source ) ) return null;

        if ( array_key_exists( 'static', $source ) ) {
            return [ 'static' => $this->sanitize_deep( $source['static'] ) ];
        }
        if ( isset( $source['fallback'] ) && is_array( $source['fallback'] ) ) {
            return [ 'fallback' => array_values( array_map( 'sanitize_text_field', $source['fallback'] ) ) ];
        }
        if ( isset( $source['acf'] ) ) {
            $out = [ 'acf' => sanitize_text_field( $source['acf'] ) ];
            if ( ! empty( $source['split'] ) ) $out['split'] = true;
            return $out;
        }
        if ( isset( $source['collect'] ) && is_array( $source['collect'] ) ) {
            return [ 'collect' => array_values( array_map( 'sanitize_text_field', $source['collect'] ) ) ];
        }
        if ( isset( $source['relation'] ) ) {
            $out = [
                'relation' => sanitize_text_field( $source['relation'] ),
                'as_type'  => sanitize_text_field( $source['as_type'] ?? '' ),
            ];
            if ( ! empty( $source['id_suffix'] ) ) $out['id_suffix'] = sanitize_text_field( $source['id_suffix'] );
            if ( ! empty( $source['single'] ) )    $out['single']    = true;
            return $out;
        }
        return null;
    }

    private function sanitize_deep( $value ) {
        return map_deep( $value, 'sanitize_text_field' );
    }

    public function ajax_preview() {
        check_ajax_referer( 'schema_graph_preview', 'nonce' );
        if ( ! current_user_can( self::CAPABILITY ) ) wp_send_json_error( 'forbidden', 403 );

        $cpt     = isset( $_POST['cpt'] )     ? sanitize_key( $_POST['cpt'] ) : '';
        $post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] )   : 0;
        $def_raw = isset( $_POST['definition'] ) ? wp_unslash( $_POST['definition'] ) : '';
        $def     = json_decode( $def_raw, true );

        if ( ! $cpt || ! is_array( $def ) ) wp_send_json_error( 'bad_request', 400 );

        if ( ! $post_id ) {
            $sample = get_posts( [
                'post_type'      => $cpt,
                'posts_per_page' => 1,
                'post_status'    => 'publish',
                'fields'         => 'ids',
            ] );
            $post_id = $sample[0] ?? 0;
        }

        if ( ! $post_id ) wp_send_json_error( 'no_post', 404 );

        $post   = get_post( $post_id );
        $render = new Schema_Graph_Render( $this->store );
        $node   = $render->build_node( $post, $def );

        wp_send_json_success( [
            'post_id' => $post_id,
            'title'   => get_the_title( $post_id ),
            'node'    => $node,
        ] );
    }

    public function ajax_properties() {
        check_ajax_referer( 'schema_graph_properties', 'nonce' );
        if ( ! current_user_can( self::CAPABILITY ) ) wp_send_json_error( 'forbidden', 403 );

        $type = isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '';

        wp_send_json_success( [
            'type'       => $type,
            'properties' => Schema_Graph_Catalog::properties_for( $type ),
        ] );
    }
}
