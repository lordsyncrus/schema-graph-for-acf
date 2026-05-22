<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
/** @var string $cpt */
/** @var WP_Post_Type $cpt_obj */
/** @var array $definition */
/** @var array $acf_fields */

$back_url = add_query_arg( [ 'page' => Schema_Graph_Admin::MENU_SLUG ], admin_url( 'admin.php' ) );
$catalog  = Schema_Graph_Catalog::types();
$tokens   = Schema_Graph_Catalog::tokens();
?>
<div class="wrap schema-graph-wrap schema-graph-edit"
     data-cpt="<?php echo esc_attr( $cpt ); ?>"
     data-acf-fields="<?php echo esc_attr( wp_json_encode( $acf_fields ) ); ?>"
     data-definition="<?php echo esc_attr( wp_json_encode( $definition ) ); ?>">

    <h1>
        <a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">← <?php esc_html_e( 'Tutti i CPT', 'schema-graph-for-acf' ); ?></a>
        <?php echo esc_html( sprintf( __( 'Mapping: %s', 'schema-graph-for-acf' ), $cpt_obj->labels->singular_name ) ); ?>
        <code style="font-size:14px;font-weight:normal;"><?php echo esc_html( $cpt ); ?></code>
    </h1>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Mapping salvato.', 'schema-graph-for-acf' ); ?></p></div>
    <?php endif; ?>
    <?php if ( isset( $_GET['error'] ) && $_GET['error'] === 'missing_type' ) : ?>
        <div class="notice notice-error"><p><?php esc_html_e( 'Devi specificare un @type schema.org.', 'schema-graph-for-acf' ); ?></p></div>
    <?php endif; ?>

    <form id="schema-graph-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'schema_graph_save_' . $cpt ); ?>
        <input type="hidden" name="action" value="schema_graph_save">
        <input type="hidden" name="cpt" value="<?php echo esc_attr( $cpt ); ?>">
        <input type="hidden" name="map" id="schema-graph-map-json" value="">

        <div class="schema-graph-layout">

            <div class="schema-graph-main">

                <table class="form-table">
                    <tr>
                        <th><label for="sg-type"><?php esc_html_e( '@type schema.org', 'schema-graph-for-acf' ); ?> *</label></th>
                        <td>
                            <input list="sg-type-list" id="sg-type" name="type" class="regular-text"
                                value="<?php echo esc_attr( $definition['type'] ?? '' ); ?>"
                                placeholder="es. MusicGroup, Article, Person…">
                            <datalist id="sg-type-list">
                                <?php foreach ( $catalog as $name => $meta ) : ?>
                                    <option value="<?php echo esc_attr( $name ); ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <datalist id="sg-prop-suggestions"></datalist>
                            <p class="description"><?php esc_html_e( 'Scrivi un @type del catalogo o uno custom (free text).', 'schema-graph-for-acf' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sg-og-type">og:type</label></th>
                        <td>
                            <?php
                            $og_current = $definition['og_type'] ?? '';
                            $og_options = Schema_Graph_Catalog::og_types();
                            ?>
                            <select id="sg-og-type" name="og_type" class="regular-text">
                                <?php foreach ( $og_options as $val => $label ) : ?>
                                    <option value="<?php echo esc_attr( $val ); ?>"<?php selected( $og_current, $val ); ?>>
                                        <?php echo esc_html( $val . ' — ' . $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ( $og_current && ! isset( $og_options[ $og_current ] ) ) : ?>
                                    <option value="<?php echo esc_attr( $og_current ); ?>" selected>
                                        <?php echo esc_html( $og_current . ' (custom)' ); ?>
                                    </option>
                                <?php endif; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Open Graph type per le anteprime social. Pre-compilato in base al @type schema; puoi modificarlo.', 'schema-graph-for-acf' ); ?></p>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Mapping proprietà → sorgente', 'schema-graph-for-acf' ); ?></h2>
                <table class="schema-graph-map widefat striped">
                    <thead>
                        <tr>
                            <th class="sg-col-handle"></th>
                            <th class="sg-col-prop"><?php esc_html_e( 'Proprietà schema', 'schema-graph-for-acf' ); ?></th>
                            <th class="sg-col-kind"><?php esc_html_e( 'Sorgente', 'schema-graph-for-acf' ); ?></th>
                            <th class="sg-col-value"><?php esc_html_e( 'Valore', 'schema-graph-for-acf' ); ?></th>
                            <th class="sg-col-actions"></th>
                        </tr>
                    </thead>
                    <tbody id="sg-rows"></tbody>
                </table>
                <p>
                    <button type="button" class="button" id="sg-add-row">+ <?php esc_html_e( 'Aggiungi proprietà', 'schema-graph-for-acf' ); ?></button>
                </p>

                <p class="submit">
                    <button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Salva mapping', 'schema-graph-for-acf' ); ?></button>
                    <a href="<?php echo esc_url( $back_url ); ?>" class="button"><?php esc_html_e( 'Annulla', 'schema-graph-for-acf' ); ?></a>
                </p>
            </div>

            <aside class="schema-graph-side">
                <div class="schema-graph-card">
                    <h3><?php esc_html_e( 'Proprietà del post', 'schema-graph-for-acf' ); ?></h3>
                    <p class="description" style="margin:0 0 8px;">
                        <?php esc_html_e( 'Click per assegnare alla riga attiva.', 'schema-graph-for-acf' ); ?>
                    </p>
                    <ul class="schema-graph-fields schema-graph-tokens">
                        <?php foreach ( $tokens as $tk => $tk_label ) : ?>
                            <li data-token="<?php echo esc_attr( $tk ); ?>">
                                <code><?php echo esc_html( $tk ); ?></code>
                                <span class="schema-graph-field-meta"><?php echo esc_html( $tk_label ); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="schema-graph-card">
                    <h3><?php esc_html_e( 'Campi ACF disponibili', 'schema-graph-for-acf' ); ?></h3>
                    <?php if ( $acf_fields ) : ?>
                        <ul class="schema-graph-fields schema-graph-acfs">
                            <?php foreach ( $acf_fields as $f ) : ?>
                                <li data-acf="<?php echo esc_attr( $f['name'] ); ?>">
                                    <code><?php echo esc_html( $f['name'] ); ?></code>
                                    <span class="schema-graph-field-meta"><?php echo esc_html( $f['type'] ); ?> · <?php echo esc_html( $f['label'] ); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else : ?>
                        <p class="description"><?php esc_html_e( 'Nessun campo ACF associato a questo CPT.', 'schema-graph-for-acf' ); ?></p>
                    <?php endif; ?>
                </div>

                <div class="schema-graph-card">
                    <h3><?php esc_html_e( 'Live preview', 'schema-graph-for-acf' ); ?></h3>
                    <p>
                        <button type="button" class="button" id="sg-preview-btn"><?php esc_html_e( 'Genera anteprima', 'schema-graph-for-acf' ); ?></button>
                    </p>
                    <p class="description schema-graph-preview-meta" id="sg-preview-meta"></p>
                    <pre id="sg-preview-output" class="schema-graph-preview" hidden></pre>
                </div>
            </aside>
        </div>
    </form>
</div>
