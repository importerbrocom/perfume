<?php
/**
 * STAGING ONLY. Run with WP-CLI:  wp eval-file import-collection.php
 * Env: PM_COLLECTION_SRC (default https://perfumarathi.com/pm-page-new/), PM_KEEP_TEST=1 to keep [TEST] products.
 *
 * Reads the public collection page (read-only GET), then on THIS site:
 *  - creates the 12 Perfumarathi perfumes + 3 combos (with notes, tagline, description; no prices),
 *  - attaches each product's image from the page,
 *  - imports the page's customer feedback cards as approved 5-star reviews (marked _pm_imported_feedback),
 *  - stores the hero portrait for the collection page, and drafts the [TEST] products.
 * Safe to run again: existing products/images/reviews are left untouched.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}
if ( 'staging' !== wp_get_environment_type() || ! defined( 'PM_STAGING_HOST' ) ) {
	WP_CLI::error( 'Refusing: this is not the staging site (WP_ENVIRONMENT_TYPE must be staging and PM_STAGING_HOST defined).' );
}
if ( ! class_exists( 'WooCommerce' ) || ! taxonomy_exists( 'pm_brand' ) ) {
	WP_CLI::error( 'WooCommerce and perfumarathi-shop must be active.' );
}
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

$dir  = getenv( 'PM_IMPORT_DIR' ) ? getenv( 'PM_IMPORT_DIR' ) : __DIR__;
$data = require rtrim( $dir, '/' ) . '/collection-data.php';
$src  = getenv( 'PM_COLLECTION_SRC' ) ? getenv( 'PM_COLLECTION_SRC' ) : 'https://perfumarathi.com/pm-page-new/';
$key  = function ( $s ) { return strtolower( preg_replace( '/[^a-z0-9]+/i', '', (string) $s ) ); };

/* ---------- 1. Read the page ---------- */
WP_CLI::log( "Reading $src" );
$res = wp_remote_get( $src, array( 'timeout' => 90, 'user-agent' => 'Mozilla/5.0 (Perfumarathi staging import)' ) );
if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
	WP_CLI::error( 'Could not read the collection page: ' . ( is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_response_code( $res ) ) );
}
$html = (string) wp_remote_retrieve_body( $res );
unset( $res );

// Images: walk <img ...> tags with strpos (the page embeds ~10 MB of base64; avoids regex backtracking limits).
$images = array();
$pos    = 0;
while ( false !== ( $start = stripos( $html, '<img', $pos ) ) ) {
	$end = strpos( $html, '>', $start );
	if ( false === $end ) {
		break;
	}
	$tag = substr( $html, $start, $end - $start + 1 );
	$pos = $end + 1;
	if ( ! preg_match( '/\balt="([^"]*)"/i', $tag, $alt ) ) {
		continue;
	}
	$s = stripos( $tag, 'src="data:image/' );
	if ( false === $s ) {
		continue;
	}
	$s   += 5;
	$e    = strpos( $tag, '"', $s );
	$uri  = substr( $tag, $s, $e - $s );
	$comma = strpos( $uri, ',' );
	if ( ! preg_match( '#^data:image/(jpeg|png|webp);base64$#', substr( $uri, 0, $comma ), $mt ) ) {
		continue;
	}
	$bytes = base64_decode( substr( $uri, $comma + 1 ), true );
	if ( $bytes && ! isset( $images[ $key( html_entity_decode( $alt[1] ) ) ] ) ) {
		$images[ $key( html_entity_decode( $alt[1] ) ) ] = array( 'bytes' => $bytes, 'ext' => 'jpeg' === $mt[1] ? 'jpg' : $mt[1] );
	}
}
WP_CLI::log( 'Images found on page: ' . count( $images ) );

// Feedback cards.
$feedback = array();
if ( preg_match_all( '#<div class="feedback-card">\s*<strong>(.*?)</strong>\s*<div class="reviewer">(.*?)</div>\s*<div class="stars">(.*?)</div>\s*<p>(.*?)</p>#s', $html, $fm, PREG_SET_ORDER ) ) {
	foreach ( $fm as $m ) {
		$prod = trim( wp_strip_all_tags( html_entity_decode( $m[1] ) ) );
		$prod = isset( $data['aliases'][ $prod ] ) ? $data['aliases'][ $prod ] : $prod;
		$who  = trim( wp_strip_all_tags( html_entity_decode( $m[2] ) ) );
		$text = trim( wp_strip_all_tags( html_entity_decode( $m[4] ) ), " \t\n\r\0\x0B“”\"" );
		$feedback[ $key( $prod ) . '|' . $who ] = array( 'product' => $prod, 'author' => $who, 'rating' => max( 1, min( 5, substr_count( $m[3], '★' ) ) ), 'text' => $text );
	}
}
WP_CLI::log( 'Customer feedback cards found: ' . count( $feedback ) );
unset( $html );

/* ---------- helpers ---------- */
$attach = function ( $label, $title ) use ( $images, $key ) {
	$k = $key( $label );
	$existing = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_pm_src', 'meta_value' => $k ) );
	if ( $existing ) {
		return (int) $existing[0];
	}
	if ( empty( $images[ $k ] ) ) {
		WP_CLI::warning( "No image on the page for '$label'." );
		return 0;
	}
	$tmp = wp_tempnam( $k );
	file_put_contents( $tmp, $images[ $k ]['bytes'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	$id = media_handle_sideload( array( 'name' => sanitize_title( $label ) . '.' . $images[ $k ]['ext'], 'tmp_name' => $tmp ), 0, $title );
	if ( is_wp_error( $id ) ) {
		@unlink( $tmp ); // phpcs:ignore
		WP_CLI::warning( "Image for '$label' failed: " . $id->get_error_message() );
		return 0;
	}
	update_post_meta( $id, '_pm_src', $k );
	update_post_meta( $id, '_wp_attachment_image_alt', $title );
	return (int) $id;
};
$cat = function ( $name ) {
	$t = term_exists( $name, 'product_cat' );
	if ( ! $t ) {
		$t = wp_insert_term( $name, 'product_cat' );
	}
	return is_wp_error( $t ) ? 0 : (int) $t['term_id'];
};
$brand = term_exists( 'perfumarathi', 'pm_brand' );
if ( ! $brand ) {
	$brand = wp_insert_term( 'Perfumarathi', 'pm_brand' );
}
$brand_id   = (int) $brand['term_id'];
$collection = $cat( 'Perfumarathi Collection' );
$combos_cat = $cat( 'Combos' );

$create = function ( $row, $cats, $is_combo ) use ( $attach, $brand_id ) {
	$slug = sanitize_title( $row['name'] );
	$have = get_page_by_path( $slug, OBJECT, 'product' );
	if ( $have ) {
		WP_CLI::log( "  exists: {$row['name']} (#{$have->ID}), left unchanged" );
		return array( (int) $have->ID, false );
	}
	$p = new WC_Product_Simple();
	$p->set_name( $row['name'] );
	$p->set_slug( $slug );
	$p->set_status( 'publish' );
	$p->set_catalog_visibility( 'visible' );
	$p->set_reviews_allowed( true );
	$p->set_short_description( esc_html( $row['desc'] ) );
	$long = '<p>' . esc_html( $row['desc'] ) . '</p>';
	if ( ! empty( $row['size'] ) ) {
		$long .= '<p><strong>Pure Parfume</strong> · ' . esc_html( $row['size'] ) . '</p>';
	}
	$p->set_description( $long );
	$sku = 'PM-' . strtoupper( $slug );
	if ( ! wc_get_product_id_by_sku( $sku ) ) {
		$p->set_sku( $sku );
	}
	$img = $attach( $row['image'], $row['name'] );
	if ( $img ) {
		$p->set_image_id( $img );
	}
	$id = $p->save();
	wp_set_object_terms( $id, array( $brand_id ), 'pm_brand' );
	wp_set_object_terms( $id, array_values( array_filter( $cats ) ), 'product_cat' );
	update_post_meta( $id, '_pm_tagline', $row['tagline'] );
	if ( ! $is_combo ) {
		update_post_meta( $id, '_pm_notes_top', $row['top'] );
		update_post_meta( $id, '_pm_notes_middle', $row['middle'] );
		update_post_meta( $id, '_pm_notes_base', $row['base'] );
		update_post_meta( $id, '_pm_traits', $row['traits'] );
	}
	update_post_meta( $id, '_pm_imported_from', 'pm-page-new' );
	WP_CLI::log( "  created: {$row['name']} (#$id)" . ( $img ? '' : ' (no image)' ) );
	return array( (int) $id, true );
};

/* ---------- 2. Perfumes ---------- */
WP_CLI::log( 'Perfumes:' );
$ids = array();
foreach ( $data['perfumes'] as $row ) {
	$cats = array( $collection );
	foreach ( $row['cats'] as $c ) {
		$cats[] = $cat( $c );
	}
	list( $id ) = $create( $row, $cats, false );
	$ids[ $key( $row['name'] ) ] = $id;
}

/* ---------- 3. Combos (Frequently bought together = the perfumes in the combo) ---------- */
WP_CLI::log( 'Combos:' );
foreach ( $data['combos'] as $row ) {
	list( $id, $new ) = $create( $row, array( $combos_cat ), true );
	if ( $new ) {
		$items = array();
		foreach ( $row['items'] as $n ) {
			if ( ! empty( $ids[ $key( $n ) ] ) ) {
				$items[] = $ids[ $key( $n ) ];
			}
		}
		update_post_meta( $id, '_pm_fbt_ids', implode( ',', array_slice( $items, 0, 4 ) ) );
	}
}

/* ---------- 4. Customer feedback -> reviews ---------- */
$added = 0;
foreach ( $feedback as $f ) {
	$pid = isset( $ids[ $key( $f['product'] ) ] ) ? $ids[ $key( $f['product'] ) ] : 0;
	if ( ! $pid || '' === $f['text'] ) {
		continue;
	}
	$dupe = false;
	foreach ( get_comments( array( 'post_id' => $pid, 'type' => 'review', 'status' => 'all' ) ) as $c ) {
		if ( $c->comment_author === $f['author'] && trim( $c->comment_content ) === $f['text'] ) {
			$dupe = true;
			break;
		}
	}
	if ( $dupe ) {
		continue;
	}
	$cid = wp_insert_comment( array(
		'comment_post_ID'  => $pid,
		'comment_author'   => $f['author'],
		'comment_content'  => $f['text'],
		'comment_type'     => 'review',
		'comment_approved' => 1,
	) );
	if ( $cid ) {
		add_comment_meta( $cid, 'rating', $f['rating'] );
		add_comment_meta( $cid, '_pm_imported_feedback', 1 );
		$added++;
	}
}
foreach ( array_unique( array_values( $ids ) ) as $pid ) {
	$p = wc_get_product( $pid );
	if ( $p ) {
		$p->set_rating_counts( WC_Comments::get_rating_counts_for_product( $p ) );
		$p->set_average_rating( WC_Comments::get_average_rating_for_product( $p ) );
		$p->set_review_count( WC_Comments::get_review_count_for_product( $p ) );
		$p->save();
	}
}
WP_CLI::log( "Reviews imported from customer feedback: $added" );

/* ---------- 5. Hero portrait, [TEST] products ---------- */
$hero = $attach( $data['hero_image'], 'Perfumarathi signature' );
if ( $hero ) {
	update_option( 'pm_collection_hero_perfumarathi', $hero );
}
if ( '1' !== getenv( 'PM_KEEP_TEST' ) ) {
	foreach ( get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 50, 's' => '[TEST]' ) ) as $t ) {
		if ( 0 === strpos( $t->post_title, '[TEST]' ) ) {
			wp_update_post( array( 'ID' => $t->ID, 'post_status' => 'draft' ) );
			WP_CLI::log( "  drafted test product: {$t->post_title}" );
		}
	}
}
WP_CLI::success( count( $data['perfumes'] ) . ' perfumes and ' . count( $data['combos'] ) . ' combos are in the catalog. Prices are empty: set them in Products to enable Add to Cart.' );
