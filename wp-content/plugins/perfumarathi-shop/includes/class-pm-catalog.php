<?php
defined( 'ABSPATH' ) || exit;

/**
 * Brand taxonomy, fragrance family, fragrance notes and default categories.
 * Products, sizes (variations), prices, stock, SKU, images, video, featured and
 * visibility remain native WooCommerce fields.
 */
class PM_Catalog {

	const NOTE_KEYS = array(
		'top'    => '_pm_notes_top',
		'middle' => '_pm_notes_middle',
		'base'   => '_pm_notes_base',
	);

	public static function default_categories() {
		return array(
			"Men's Perfumes", "Women's Perfumes", 'Unisex Perfumes', 'Luxury Collection',
			'Oud Collection', 'Arabic Perfumes', 'Inspired Fragrances', 'Best Sellers',
			'New Arrivals', 'Special Offers',
		);
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_notes' ) );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_notes' ), 25 );
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'brand_column' ) );
	}

	public static function register_taxonomies() {
		register_taxonomy( 'pm_brand', 'product', array(
			'labels'            => array( 'name' => 'Brands', 'singular_name' => 'Brand', 'add_new_item' => 'Add New Brand' ),
			'hierarchical'      => true,
			'public'            => true,
			'show_admin_column' => true,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'brand' ),
			'capabilities'      => array(
				'manage_terms' => 'manage_product_terms',
				'edit_terms'   => 'edit_product_terms',
				'delete_terms' => 'delete_product_terms',
				'assign_terms' => 'assign_product_terms',
			),
		) );
		register_taxonomy( 'pm_family', 'product', array(
			'labels'            => array( 'name' => 'Fragrance Families', 'singular_name' => 'Fragrance Family' ),
			'hierarchical'      => false,
			'public'            => true,
			'show_admin_column' => false,
			'show_in_rest'      => true,
			'rewrite'           => array( 'slug' => 'fragrance-family' ),
			'capabilities'      => array(
				'manage_terms' => 'manage_product_terms',
				'edit_terms'   => 'edit_product_terms',
				'delete_terms' => 'delete_product_terms',
				'assign_terms' => 'assign_product_terms',
			),
		) );
	}

	/** Create brands and default categories (idempotent). */
	public static function activate() {
		self::register_taxonomies();
		foreach ( array( 'Perfumarathi', 'Fixnote' ) as $brand ) {
			if ( ! term_exists( $brand, 'pm_brand' ) ) {
				wp_insert_term( $brand, 'pm_brand' );
			}
		}
		if ( taxonomy_exists( 'product_cat' ) ) {
			foreach ( self::default_categories() as $cat ) {
				if ( ! term_exists( $cat, 'product_cat' ) ) {
					wp_insert_term( $cat, 'product_cat' );
				}
			}
		}
		flush_rewrite_rules();
	}

	public static function add_meta_box() {
		add_meta_box( 'pm_notes', 'Fragrance Notes', array( __CLASS__, 'meta_box' ), 'product', 'normal', 'default' );
	}

	public static function meta_box( $post ) {
		wp_nonce_field( 'pm_save_notes', 'pm_notes_nonce' );
		echo '<p>Comma-separated notes (e.g. <em>Bergamot, Saffron</em>).</p>';
		foreach ( self::NOTE_KEYS as $label => $key ) {
			printf(
				'<p><label><strong>%s notes</strong><br><input type="text" class="widefat" name="%s" value="%s"></label></p>',
				esc_html( ucfirst( $label ) ),
				esc_attr( $key ),
				esc_attr( get_post_meta( $post->ID, $key, true ) )
			);
		}
	}

	public static function save_notes( $post_id ) {
		if ( ! isset( $_POST['pm_notes_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pm_notes_nonce'] ) ), 'pm_save_notes' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		foreach ( self::NOTE_KEYS as $key ) {
			$val = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
			update_post_meta( $post_id, $key, $val );
		}
	}

	public static function get_notes( $product_id ) {
		$out = array();
		foreach ( self::NOTE_KEYS as $label => $key ) {
			$out[ $label ] = (string) get_post_meta( $product_id, $key, true );
		}
		return $out;
	}

	public static function get_brand( $product_id ) {
		$terms = get_the_terms( $product_id, 'pm_brand' );
		return ( $terms && ! is_wp_error( $terms ) ) ? $terms[0] : null;
	}

	/** Fragrance notes + family on the single product page. */
	public static function render_notes() {
		global $product;
		if ( ! $product ) {
			return;
		}
		$id     = $product->get_id();
		$notes  = array_filter( self::get_notes( $id ) );
		$family = get_the_terms( $id, 'pm_family' );
		$brand  = self::get_brand( $id );
		if ( ! $notes && ! $family && ! $brand ) {
			return;
		}
		echo '<div class="pm-notes">';
		if ( $brand ) {
			echo '<p><strong>Brand:</strong> <a href="' . esc_url( get_term_link( $brand ) ) . '">' . esc_html( $brand->name ) . '</a></p>';
		}
		if ( $family && ! is_wp_error( $family ) ) {
			echo '<p><strong>Fragrance family:</strong> ' . esc_html( implode( ', ', wp_list_pluck( $family, 'name' ) ) ) . '</p>';
		}
		foreach ( $notes as $label => $val ) {
			echo '<p><strong>' . esc_html( ucfirst( $label ) ) . ' notes:</strong> ' . esc_html( $val ) . '</p>';
		}
		echo '</div>';
	}

	public static function brand_column( $cols ) {
		return $cols; // taxonomy admin column is added by show_admin_column.
	}
}
