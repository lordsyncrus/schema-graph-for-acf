<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Schema_Graph_ACF_Introspect {

    /**
     * Restituisce i campi ACF disponibili per un CPT.
     *
     * @return array<int,array{key:string,name:string,label:string,type:string}>
     */
    public static function fields_for_cpt( $cpt ) {
        if ( ! function_exists( 'acf_get_field_groups' ) || ! function_exists( 'acf_get_fields' ) ) {
            return [];
        }

        $groups = acf_get_field_groups( [ 'post_type' => $cpt ] );
        $out    = [];

        foreach ( $groups as $group ) {
            $fields = acf_get_fields( $group['key'] );
            if ( ! is_array( $fields ) ) continue;
            foreach ( $fields as $f ) {
                $out[] = [
                    'key'   => $f['key'],
                    'name'  => $f['name'],
                    'label' => $f['label'],
                    'type'  => $f['type'],
                ];
            }
        }

        return $out;
    }

    /** Lista dei CPT pubblici registrati. */
    public static function public_post_types() {
        $types = get_post_types( [ 'public' => true ], 'objects' );
        unset( $types['attachment'] );
        return $types;
    }
}
