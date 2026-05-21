<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Schema_Graph_Config_Store {

    const OPTION_KEY = 'schema_graph_config';

    /** @return array<string,array> cpt => definizione */
    public function get_all() {
        $config = get_option( self::OPTION_KEY, [] );
        return is_array( $config ) ? $config : [];
    }

    public function get_for_cpt( $cpt ) {
        $all = $this->get_all();
        return $all[ $cpt ] ?? null;
    }

    public function save_for_cpt( $cpt, array $definition ) {
        $all = $this->get_all();
        $all[ $cpt ] = $definition;
        return update_option( self::OPTION_KEY, $all );
    }

    public function delete_for_cpt( $cpt ) {
        $all = $this->get_all();
        unset( $all[ $cpt ] );
        return update_option( self::OPTION_KEY, $all );
    }

    public function replace_all( array $config ) {
        return update_option( self::OPTION_KEY, $config );
    }
}
