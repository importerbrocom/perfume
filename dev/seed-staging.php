<?php
// STAGING ONLY. Creates clearly labelled TEST products so the shopping flow can be exercised.
// Never run on production. Delete afterwards: wp post delete $(wp post list --post_type=product --s="[TEST]" --format=ids) --force
if ( 'production' === wp_get_environment_type() ) { WP_CLI::error( 'Refusing to seed production.' ); }
$cats = array( 'perfumarathi' => 'Men\'s Perfumes', 'fixnote' => 'Inspired Fragrances' );
foreach ( $cats as $brand => $cat ) {
	$p = new WC_Product_Variable();
	$p->set_name( '[TEST] ' . ucfirst( $brand ) . ' Sample Oud' );
	$p->set_status( 'publish' );
	$a = new WC_Product_Attribute();
	$a->set_name( 'Size' ); $a->set_options( array( '30ml', '50ml', '100ml' ) ); $a->set_visible( true ); $a->set_variation( true );
	$p->set_attributes( array( $a ) );
	$id = $p->save();
	wp_set_object_terms( $id, $brand, 'pm_brand' );
	wp_set_object_terms( $id, $cat, 'product_cat' );
	update_post_meta( $id, '_pm_notes_top', 'Bergamot, Saffron' );
	update_post_meta( $id, '_pm_notes_middle', 'Rose, Oud' );
	update_post_meta( $id, '_pm_notes_base', 'Amber, Musk' );
	foreach ( array( '30ml' => 80, '50ml' => 120, '100ml' => 200 ) as $size => $price ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $id ); $v->set_attributes( array( 'size' => $size ) );
		$v->set_regular_price( $price ); $v->set_manage_stock( true ); $v->set_stock_quantity( 25 );
		$v->save();
	}
	WC_Product_Variable::sync( $id );
}
WP_CLI::success( 'Seeded 2 [TEST] products.' );
