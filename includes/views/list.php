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

    <?php if ( isset( $_GET['imported'] ) ) :
        $n = (int) $_GET['imported'];
        $m = isset( $_GET['mode'] ) && $_GET['mode'] === 'replace' ? __( 'sostituita', 'schema-graph-for-acf' ) : __( 'unita alla esistente', 'schema-graph-for-acf' );
    ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( _n( '%d CPT importato (config %s).', '%d CPT importati (config %s).', $n, 'schema-graph-for-acf' ), $n, $m ) ); ?></p></div>
    <?php endif; ?>

    <?php if ( isset( $_GET['import_error'] ) ) :
        $err = $_GET['import_error'] === 'no_file'
            ? __( 'Nessun file caricato.', 'schema-graph-for-acf' )
            : __( 'Il file non è un JSON valido o non contiene una config riconoscibile.', 'schema-graph-for-acf' );
    ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html( $err ); ?></p></div>
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

    <div class="schema-graph-iox">
        <div class="schema-graph-iox-card">
            <h2><?php esc_html_e( 'Esporta configurazione', 'schema-graph-for-acf' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Scarica tutti i mapping come file JSON, utile per backup o per portarli su un altro sito.', 'schema-graph-for-acf' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'schema_graph_export' ); ?>
                <input type="hidden" name="action" value="schema_graph_export">
                <button type="submit" class="button"<?php disabled( empty( $all ) ); ?>>
                    <?php esc_html_e( 'Esporta JSON', 'schema-graph-for-acf' ); ?>
                </button>
            </form>
        </div>

        <div class="schema-graph-iox-card">
            <h2><?php esc_html_e( 'Importa configurazione', 'schema-graph-for-acf' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Carica un file JSON esportato. La modalità "Sostituisci" azzera prima la config esistente; "Unisci" aggiunge/aggiorna i CPT presenti nel file lasciando intatti gli altri.', 'schema-graph-for-acf' ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'schema_graph_import' ); ?>
                <input type="hidden" name="action" value="schema_graph_import">
                <p><input type="file" name="file" accept="application/json,.json" required></p>
                <p>
                    <label><input type="radio" name="mode" value="merge" checked> <?php esc_html_e( 'Unisci', 'schema-graph-for-acf' ); ?></label>
                    &nbsp;&nbsp;
                    <label><input type="radio" name="mode" value="replace"> <?php esc_html_e( 'Sostituisci tutto', 'schema-graph-for-acf' ); ?></label>
                </p>
                <button type="submit" class="button"><?php esc_html_e( 'Importa JSON', 'schema-graph-for-acf' ); ?></button>
            </form>
        </div>
    </div>
</div>
