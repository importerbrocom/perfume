<?php
/**
 * READ-ONLY export of the WordPress/WooCommerce shop for the Laravel migration.
 * Run with WP-CLI:  PM_EXPORT_DIR=/path/to/dir wp eval-file export-wp.php
 * Writes  $PM_EXPORT_DIR/catalog.json  and copies product images into  $PM_EXPORT_DIR/images/.
 * Nothing in WordPress is changed.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
if ( ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WooCommerce is not active.' );
}
$dir = rtrim( (string) getenv( 'PM_EXPORT_DIR' ), '/' );
if ( '' === $dir ) {
	WP_CLI::error( 'Set PM_EXPORT_DIR.' );
}
wp_mkdir_p( $dir . '/images' );

$copied = array();
$image  = function ( $attachment_id ) use ( $dir, &$copied ) {
	$attachment_id = (int) $attachment_id;
	if ( ! $attachment_id ) {
		return null;
	}
	if ( isset( $copied[ $attachment_id ] ) ) {
		return $copied[ $attachment_id ];
	}
	$file = get_attached_file( $attachment_id );
	if ( ! $file || ! is_readable( $file ) ) {
		return null;
	}
	$name = $attachment_id . '-' . sanitize_file_name( basename( $file ) );
	if ( ! copy( $file, $dir . '/images/' . $name ) ) {
		return null;
	}
	$copied[ $attachment_id ] = array( 'file' => $name, 'alt' => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	return $copied[ $attachment_id ];
};
$terms = function ( $id, $tax ) {
	$t = get_the_terms( $id, $tax );
	return ( $t && ! is_wp_error( $t ) ) ? array_values( array_map( function ( $x ) { return array( 'name' => html_entity_decode( $x->name ), 'slug' => $x->slug ); }, $t ) ) : array();
};
$stock = function ( WC_Product $p ) {
	return array(
		'manage_stock' => (bool) $p->get_manage_stock(),
		'quantity'     => $p->get_manage_stock() ? (int) $p->get_stock_quantity() : null,
		'status'       => $p->get_stock_status(),
	);
};

$out = array( 'exported_at' => gmdate( 'c' ), 'site' => home_url(), 'currency' => get_woocommerce_currency(), 'products' => array() );
$ids = get_posts( array( 'post_type' => 'product', 'post_status' => array( 'publish', 'private' ), 'numberposts' => -1, 'fields' => 'ids', 'orderby' => 'menu_order title', 'order' => 'ASC' ) );
foreach ( $ids as $id ) {
	$p = wc_get_product( $id );
	if ( ! $p || 0 === strpos( $p->get_name(), '[TEST]' ) ) {
		continue;
	}
	$images = array();
	foreach ( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) as $aid ) {
		if ( $img = $image( $aid ) ) {
			$images[] = $img;
		}
	}
	$variants = array();
	if ( $p->is_type( 'variable' ) ) {
		foreach ( $p->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v ) {
				continue;
			}
			$variants[] = array(
				'name'       => wc_get_formatted_variation( $v, true, false, false ) ?: 'Standard',
				'sku'        => $v->get_sku() ?: null,
				'price'      => '' !== $v->get_regular_price() ? (float) $v->get_regular_price() : null,
				'sale_price' => '' !== $v->get_sale_price() ? (float) $v->get_sale_price() : null,
				'active'     => 'publish' === $v->get_status(),
			) + $stock( $v );
		}
	} else {
		$desc = wp_strip_all_tags( $p->get_description() . ' ' . $p->get_short_description() );
		$size = preg_match( '/\b(\d{2,4})\s?ml\b/i', $desc, $m ) ? $m[1] . ' ml' : 'Standard';
		$variants[] = array(
			'name'       => $size,
			'sku'        => null,
			'price'      => '' !== $p->get_regular_price() ? (float) $p->get_regular_price() : null,
			'sale_price' => '' !== $p->get_sale_price() ? (float) $p->get_sale_price() : null,
			'active'     => true,
		) + $stock( $p );
	}
	$reviews = array();
	foreach ( get_comments( array( 'post_id' => $id, 'type' => 'review', 'status' => 'approve' ) ) as $c ) {
		$reviews[] = array(
			'author'   => $c->comment_author,
			'rating'   => (int) get_comment_meta( $c->comment_ID, 'rating', true ),
			'body'     => $c->comment_content,
			'date'     => mysql2date( 'c', $c->comment_date_gmt ),
			'imported' => (bool) get_comment_meta( $c->comment_ID, '_pm_imported_feedback', true ),
		);
	}
	$fbt = array_filter( array_map( 'absint', explode( ',', (string) get_post_meta( $id, '_pm_fbt_ids', true ) ) ) );
	$out['products'][] = array(
		'wp_id'             => $id,
		'name'              => html_entity_decode( $p->get_name() ),
		'slug'              => $p->get_slug(),
		'sku'               => $p->get_sku() ?: null,
		'status'            => $p->get_status(),
		'featured'          => $p->is_featured(),
		'short_description' => wp_strip_all_tags( $p->get_short_description() ),
		'description'       => wp_kses_post( $p->get_description() ),
		'tagline'           => (string) get_post_meta( $id, '_pm_tagline', true ),
		'traits'            => (string) get_post_meta( $id, '_pm_traits', true ),
		'notes'             => array(
			'top'    => (string) get_post_meta( $id, '_pm_notes_top', true ),
			'middle' => (string) get_post_meta( $id, '_pm_notes_middle', true ),
			'base'   => (string) get_post_meta( $id, '_pm_notes_base', true ),
		),
		'brand'             => $terms( $id, 'pm_brand' ),
		'categories'        => $terms( $id, 'product_cat' ),
		'family'            => $terms( $id, 'pm_family' ),
		'related_wp_ids'    => array_values( $fbt ),
		'images'            => $images,
		'variants'          => $variants,
		'reviews'           => $reviews,
		'total_sales'       => (int) $p->get_total_sales(),
		'menu_order'        => (int) $p->get_menu_order(),
	);
}
file_put_contents( $dir . '/catalog.json', wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
WP_CLI::success( count( $out['products'] ) . ' products and ' . count( $copied ) . ' images exported to ' . $dir );
