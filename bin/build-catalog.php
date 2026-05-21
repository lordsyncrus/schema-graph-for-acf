<?php
/**
 * Genera data/schema-catalog.json dal vocabolario ufficiale schema.org.
 *
 * Uso:
 *   php bin/build-catalog.php
 *
 * Scarica i CSV ufficiali (types + properties), calcola le proprietà di ogni
 * @type includendo quelle ereditate da tutti i parent, scrive il JSON.
 */

declare( strict_types = 1 );

const TYPES_URL = 'https://schema.org/version/latest/schemaorg-current-https-types.csv';
const PROPS_URL = 'https://schema.org/version/latest/schemaorg-current-https-properties.csv';
const OUT_PATH  = __DIR__ . '/../data/schema-catalog.json';

function fetch( string $url ): string {
    $data = file_get_contents( $url );
    if ( $data === false ) {
        fwrite( STDERR, "✗ Errore scaricando $url\n" );
        exit( 1 );
    }
    return $data;
}

function short( string $id ): string {
    return preg_replace( '#^https?://schema\.org/#', '', $id );
}

/** Parse CSV → array di righe come array assoc. */
function csv_to_rows( string $csv ): array {
    $rows = [];
    $lines = preg_split( '/\R/', $csv );
    $header = null;
    $buf = '';
    foreach ( $lines as $line ) {
        $buf = $buf === '' ? $line : $buf . "\n" . $line;
        if ( substr_count( $buf, '"' ) % 2 !== 0 ) continue;
        $row = str_getcsv( $buf, ',', '"', '\\' );
        $buf = '';
        if ( $header === null ) { $header = $row; continue; }
        if ( count( $row ) === 1 && $row[0] === '' ) continue;
        $assoc = [];
        foreach ( $header as $i => $h ) {
            $assoc[ $h ] = $row[ $i ] ?? '';
        }
        $rows[] = $assoc;
    }
    return $rows;
}

echo "→ Scarica schema.org vocabulary...\n";
$typesCsv = fetch( TYPES_URL );
$propsCsv = fetch( PROPS_URL );

$typesRows = csv_to_rows( $typesCsv );
$propsRows = csv_to_rows( $propsCsv );

echo "  - " . count( $typesRows ) . " types\n";
echo "  - " . count( $propsRows ) . " properties\n";

// Indice tipi
$types = [];
foreach ( $typesRows as $r ) {
    $id = short( $r['id'] ?? '' );
    if ( ! $id ) continue;

    // Skip deprecated
    if ( ! empty( $r['supersededBy'] ) ) continue;

    $parents = [];
    foreach ( explode( ',', $r['subTypeOf'] ?? '' ) as $p ) {
        $p = short( trim( $p ) );
        if ( $p ) $parents[] = $p;
    }

    $types[ $id ] = [
        'label'   => $r['label']   ?? $id,
        'comment' => $r['comment'] ?? '',
        'parents' => $parents,
    ];
}

// Proprietà dirette per tipo
$directProps = [];
foreach ( $propsRows as $r ) {
    if ( ! empty( $r['supersededBy'] ) ) continue;

    $name = $r['label'] ?? '';
    if ( ! $name ) continue;

    foreach ( explode( ',', $r['domainIncludes'] ?? '' ) as $d ) {
        $d = short( trim( $d ) );
        if ( $d ) $directProps[ $d ][] = $name;
    }
}

// Eredità ricorsiva
$cache = [];
function inheritedProps( string $tid, array $types, array $directProps, array &$cache ): array {
    if ( isset( $cache[ $tid ] ) ) return $cache[ $tid ];
    $cache[ $tid ] = []; // anti-loop
    $own = $directProps[ $tid ] ?? [];
    $merged = $own;
    foreach ( $types[ $tid ]['parents'] ?? [] as $parent ) {
        if ( ! isset( $types[ $parent ] ) ) continue;
        $merged = array_merge( $merged, inheritedProps( $parent, $types, $directProps, $cache ) );
    }
    $merged = array_values( array_unique( $merged ) );
    sort( $merged, SORT_STRING | SORT_FLAG_CASE );
    $cache[ $tid ] = $merged;
    return $merged;
}

// Output
$out = [
    'version'       => 2,
    'source'        => 'https://schema.org/',
    'generated'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
    'types'         => [],
    'allProperties' => [],
];

$all_props = [];
foreach ( $types as $tid => $t ) {
    $props = inheritedProps( $tid, $types, $directProps, $cache );
    if ( empty( $props ) ) continue; // skip enumeration values puri
    $out['types'][ $tid ] = [
        'label'      => $t['label'],
        'properties' => $props,
    ];
    foreach ( $props as $p ) $all_props[ $p ] = true;
}

ksort( $out['types'] );
$all_props = array_keys( $all_props );
sort( $all_props, SORT_STRING | SORT_FLAG_CASE );
$out['allProperties'] = $all_props;

file_put_contents( OUT_PATH, json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

$count = count( $out['types'] );
$size  = filesize( OUT_PATH );
echo "✓ Generati $count @type → " . OUT_PATH . " (" . round( $size / 1024 ) . " KB)\n";
