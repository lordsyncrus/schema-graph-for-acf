<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
$store = $this->store;
$types = Schema_Graph_ACF_Introspect::public_post_types();
$all   = $store->get_all();
?>
<div class="wrap schema-graph-wrap">
    <h1><?php esc_html_e( 'Schema Graph — Custom Post Types', 'schema-graph-for-acf' ); ?></h1>

    <?php if ( isset( $_GET['reset'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Mapping rimosso.', 'schema-graph-for-acf' ); ?></p></div>
    <?php endif; ?>

    <p class="description">
        <?php esc_html_e( 'Configura il mapping JSON-LD per ogni Custom Post Type registrato. Senza mapping, nessun output viene generato.', 'schema-graph-for-acf' ); ?>
    </p>

    <table class="widefat fixed striped schema-graph-list">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Post Type', 'schema-graph-for-acf' ); ?></th>
                <th><?php esc_html_e( 'Slug', 'schema-graph-for-acf' ); ?></th>
                <th><?php esc_html_e( 'Stato', 'schema-graph-for-acf' ); ?></th>
                <th><?php esc_html_e( '@type', 'schema-graph-for-acf' ); ?></th>
                <th><?php esc_html_e( 'Azioni', 'schema-graph-for-acf' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $types as $slug => $obj ) :
            $def      = $all[ $slug ] ?? null;
            $is_mapped = ! empty( $def['type'] );
            $edit_url = add_query_arg(
                [ 'page' => Schema_Graph_Admin::MENU_SLUG, 'cpt' => $slug ],
                admin_url( 'admin.php' )
            );
        ?>
            <tr>
                <td><strong><?php echo esc_html( $obj->labels->singular_name ); ?></strong></td>
                <td><code><?php echo esc_html( $slug ); ?></code></td>
                <td>
                    <?php if ( $is_mapped ) : ?>
                        <span class="schema-graph-badge schema-graph-badge--on">✓ <?php esc_html_e( 'Mappato', 'schema-graph-for-acf' ); ?></span>
                    <?php else : ?>
                        <span class="schema-graph-badge schema-graph-badge--off">—</span>
                    <?php endif; ?>
                </td>
                <td><?php echo $is_mapped ? '<code>' . esc_html( $def['type'] ) . '</code>' : '—'; ?></td>
                <td>
                    <a href="<?php echo esc_url( $edit_url ); ?>" class="button button-small">
                        <?php echo $is_mapped ? esc_html__( 'Modifica', 'schema-graph-for-acf' ) : esc_html__( 'Configura', 'schema-graph-for-acf' ); ?>
                    </a>
                    <?php if ( $is_mapped ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('<?php esc_attr_e( 'Confermi la rimozione del mapping?', 'schema-graph-for-acf' ); ?>')">
                            <?php wp_nonce_field( 'schema_graph_reset_' . $slug ); ?>
                            <input type="hidden" name="action" value="schema_graph_reset">
                            <input type="hidden" name="cpt" value="<?php echo esc_attr( $slug ); ?>">
                            <button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Reset', 'schema-graph-for-acf' ); ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
