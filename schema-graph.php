<?php
/**
 * Plugin Name: Schema Graph for ACF
 * Plugin URI:  https://github.com/lordsyncrus/schema-graph-for-acf
 * Description: Motore generico ACF → JSON-LD schema.org. Mappa qualsiasi CPT/campo ACF tramite una UI di admin no-code. Compatibile con ACF Free e ACF Pro.
 * Version:     3.4.0
 * Author:      3Heads Agency
 * License:     GPL-2.0-or-later
 * Text Domain: schema-graph-for-acf
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SCHEMA_GRAPH_VERSION', '3.4.0' );
define( 'SCHEMA_GRAPH_FILE', __FILE__ );
define( 'SCHEMA_GRAPH_PATH', plugin_dir_path( __FILE__ ) );
define( 'SCHEMA_GRAPH_URL', plugin_dir_url( __FILE__ ) );

require_once SCHEMA_GRAPH_PATH . 'includes/class-schema-graph-config-store.php';
require_once SCHEMA_GRAPH_PATH . 'includes/class-schema-graph-render.php';
require_once SCHEMA_GRAPH_PATH . 'includes/class-schema-graph-catalog.php';
require_once SCHEMA_GRAPH_PATH . 'includes/class-schema-graph-acf-introspect.php';

add_action( 'plugins_loaded', function() {

    // ACF (Free o Pro) richiesto a runtime: lo individuiamo da get_field().
    if ( ! function_exists( 'get_field' ) ) {
        add_action( 'admin_notices', function() {
            if ( ! current_user_can( 'activate_plugins' ) ) return;
            echo '<div class="notice notice-error"><p>'
               . esc_html__( 'Schema Graph for ACF richiede Advanced Custom Fields (Free o Pro) attivo.', 'schema-graph-for-acf' )
               . '</p></div>';
        } );
        return;
    }

    $store = new Schema_Graph_Config_Store();
    new Schema_Graph_Render( $store );

    if ( is_admin() ) {
        require_once SCHEMA_GRAPH_PATH . 'includes/class-schema-graph-admin.php';
        new Schema_Graph_Admin( $store );
    }
} );
