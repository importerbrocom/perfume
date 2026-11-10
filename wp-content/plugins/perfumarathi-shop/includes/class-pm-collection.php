<?php
defined( 'ABSPATH' ) || exit;

/**
 * [pm_collection brand="perfumarathi"]
 * Shop page in the layout of the Perfumarathi collection page (perfumarathi.com/pm-page-new):
 * hero, customer feedback (real approved reviews), special offers, combos, perfume collection.
 * Every card opens its own product page; buyable products get Add to Cart, products without a
 * price show "Price on request" + WhatsApp enquiry (no invented prices).
 *
 * Product page extras: tagline, character words, price-on-request + WhatsApp enquiry.
 */
class PM_Collection {

	const WA = '971528209716';

	public static function init() {
		add_shortcode( 'pm_collection', array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'tagline' ), 6 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'enquire' ), 31 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'traits' ), 26 );
		add_shortcode( 'pm_shop_hero', array( __CLASS__, 'sc_hero' ) );
		// Main Shop page: hero above the product grid (replaces the plain "Shop" title).
		add_filter( 'woocommerce_show_page_title', array( __CLASS__, 'hide_shop_title' ) );
		add_action( 'woocommerce_archive_description', array( __CLASS__, 'shop_hero' ), 1 );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	private static function is_shop_front() {
		return function_exists( 'is_shop' ) && is_shop() && ! is_search() && ! is_paged();
	}

	public static function hide_shop_title( $show ) {
		return self::is_shop_front() ? false : $show;
	}

	public static function shop_hero() {
		if ( self::is_shop_front() ) {
			// The Shop page's own content ([pm_shop]) would add a second product grid under WooCommerce's grid.
			remove_action( 'woocommerce_archive_description', 'woocommerce_product_archive_description', 10 );
			echo self::hero_html( 'perfumarathi' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in hero_html().
		}
	}

	public static function sc_hero( $atts ) {
		$a = shortcode_atts( array( 'brand' => 'perfumarathi' ), $atts, 'pm_shop_hero' );
		return self::hero_html( sanitize_title( $a['brand'] ) );
	}

	/** Pages showing the hero (it has its own big wordmark) get a compact header. */
	public static function body_class( $classes ) {
		$post = get_post();
		if ( self::is_shop_front() || ( is_page() && $post && ( has_shortcode( $post->post_content, 'pm_collection' ) || has_shortcode( $post->post_content, 'pm_shop_hero' ) ) ) ) {
			$classes[] = 'pm-has-hero';
		}
		return $classes;
	}

	/**
	 * Hero of perfumarathi.com/pm-page-new: wordmark, signature portrait, kicker, trust line,
	 * "Find Your ...", intro text and the WhatsApp button. Same wording as the live page.
	 */
	public static function hero_html( $brand ) {
		$term  = get_term_by( 'slug', $brand, 'pm_brand' );
		$label = $term ? $term->name : ucfirst( $brand );
		$img   = (int) get_option( 'pm_collection_hero_' . $brand );
		$h  = '<section class="pmc-hero" aria-label="' . esc_attr( $label ) . '">';
		$h .= '<div class="pmc-wordmark"><div class="pmc-wordmark-main">' . esc_html( strtoupper( $label ) ) . '</div>'
			. '<div class="pmc-wordmark-tag">SECRET OF ATTRACTIVENESS</div></div>';
		$h .= '<div class="pmc-hero-grid">';
		if ( $img ) {
			$h .= wp_get_attachment_image( $img, 'large', false, array( 'class' => 'pmc-portrait', 'alt' => $label . ' signature', 'fetchpriority' => 'high' ) );
		}
		$h .= '<div><div class="pmc-kicker">PURE FRAGRANCES • TIMELESS IMPRESSIONS</div>'
			. '<div class="pmc-trust">12–24H LASTING &nbsp;•&nbsp; HIGH PROJECTION &nbsp;•&nbsp; TRUSTED SINCE 2015</div>'
			. '<h1>Find Your ' . esc_html( $label ) . '</h1>'
			. '<p>Explore our own-brand perfume collection and premium combo options. For current prices, enquiries and orders, message us directly on WhatsApp.</p>'
			. '<a class="pmc-cta" href="' . esc_url( self::wa( "Hi Perfumarathi, I'd like to get the price and details for your perfumes." ) ) . '" target="_blank" rel="noopener noreferrer">GET PRICE • ENQUIRIES • ORDER ON WHATSAPP →</a></div>';
		return $h . '</div></section>';
	}

	public static function wa( $text ) {
		return 'https://wa.me/' . self::WA . '?text=' . rawurlencode( $text );
	}

	/** Published, catalog-visible products of a brand, either only combos or everything except combos. */
	private static function products( $brand, $in_combos ) {
		$has_combos = (bool) term_exists( 'combos', 'product_cat' );
		if ( $in_combos && ! $has_combos ) {
			return array();
		}
		$tax = array( 'relation' => 'AND', array( 'taxonomy' => 'pm_brand', 'field' => 'slug', 'terms' => $brand ) );
		if ( $has_combos ) {
			$tax[] = array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => 'combos', 'operator' => $in_combos ? 'IN' : 'NOT IN' );
		}
		$q   = new WP_Query( array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 48,
			'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			'tax_query'      => $tax, // phpcs:ignore WordPress.DB.SlowDBQuery
			'no_found_rows'  => true,
		) );
		$out = array();
		foreach ( $q->posts as $post ) {
			$prod = wc_get_product( $post );
			if ( $prod && $prod->is_visible() ) {
				$out[] = $prod;
			}
		}
		return $out;
	}

	public static function card( WC_Product $p, $combo = false ) {
		$id      = $p->get_id();
		$url     = get_permalink( $id );
		$name    = $p->get_name();
		$img     = $p->get_image_id() ? wp_get_attachment_image( $p->get_image_id(), 'woocommerce_thumbnail', false, array( 'loading' => 'lazy', 'alt' => $name ) ) : wc_placeholder_img();
		$tagline = (string) get_post_meta( $id, '_pm_tagline', true );
		$priced  = '' !== (string) $p->get_price();
		$h  = '<article class="pmc-card' . ( $combo ? ' pmc-card--combo' : '' ) . '">';
		$h .= '<a class="pmc-card__media" href="' . esc_url( $url ) . '" aria-label="' . esc_attr( $name ) . '">' . wp_kses_post( $img );
		if ( $p->is_on_sale() ) {
			$h .= '<span class="pm-badge">Sale</span>';
		}
		$h .= '</a><div class="pmc-card__body">';
		$h .= '<h3><a href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a></h3>';
		if ( $tagline ) {
			$h .= '<p class="pmc-card__tag">' . esc_html( $tagline ) . '</p>';
		}
		if ( $p->get_review_count() ) {
			$r  = (float) $p->get_average_rating();
			$n  = (int) round( $r );
			$h .= '<div class="pmc-stars" aria-label="Rated ' . esc_attr( number_format( $r, 1 ) ) . ' out of 5">' . str_repeat( '★', $n ) . '<span>' . str_repeat( '★', 5 - $n ) . '</span> <small>(' . (int) $p->get_review_count() . ')</small></div>';
		}
		$h .= $priced ? '<div class="pmc-price">' . wp_kses_post( $p->get_price_html() ) . '</div>' : '<div class="pmc-price pmc-price--req">Price on request</div>';
		$h .= '<div class="pmc-actions">';
		if ( $priced && $p->is_purchasable() && $p->is_in_stock() && $p->is_type( 'simple' ) ) {
			$h .= '<button type="button" class="pmc-btn" data-add="' . esc_attr( $id ) . '">Add to Cart</button>';
			$h .= '<a class="pmc-btn pmc-btn--ghost" href="' . esc_url( $url ) . '">View</a>';
		} elseif ( $priced && $p->is_in_stock() && ! $p->is_type( 'simple' ) ) {
			$h .= '<a class="pmc-btn" href="' . esc_url( $url ) . '">Choose Size</a>';
		} else {
			$h .= '<a class="pmc-btn pmc-btn--ghost" href="' . esc_url( $url ) . '">View Details</a>';
			$h .= '<a class="pmc-btn pmc-btn--wa" href="' . esc_url( self::wa( "Hi Perfumarathi, I'd like to know the price of " . $name . '.' ) ) . '" target="_blank" rel="noopener noreferrer">Enquire on WhatsApp</a>';
		}
		if ( $priced && ! $p->is_in_stock() ) {
			$h .= '<span class="pm-stock out">Out of stock</span>';
		}
		$h .= '</div></div></article>';
		return $h;
	}

	private static function feedback( $brand ) {
		$ids = get_posts( array(
			'post_type' => 'product', 'post_status' => 'publish', 'numberposts' => 200, 'fields' => 'ids',
			'tax_query' => array( array( 'taxonomy' => 'pm_brand', 'field' => 'slug', 'terms' => $brand ) ),
		) );
		if ( ! $ids ) {
			return '';
		}
		$reviews = get_comments( array(
			'post__in' => $ids, 'type' => 'review', 'status' => 'approve', 'number' => 12,
			'meta_query' => array( array( 'key' => 'rating', 'value' => 4, 'compare' => '>=', 'type' => 'NUMERIC' ) ),
		) );
		if ( ! $reviews ) {
			return '';
		}
		$cards = '';
		foreach ( $reviews as $c ) {
			$stars  = (int) get_comment_meta( $c->comment_ID, 'rating', true );
			$cards .= '<div class="pmc-fb-card"><strong>' . esc_html( get_the_title( $c->comment_post_ID ) ) . '</strong>'
				. '<div class="pmc-fb-name">' . esc_html( $c->comment_author ) . '</div>'
				. '<div class="pmc-fb-stars" aria-label="' . esc_attr( $stars ) . ' out of 5">' . str_repeat( '★', max( 0, min( 5, $stars ) ) ) . '</div>'
				. '<p>“' . esc_html( wp_trim_words( $c->comment_content, 30 ) ) . '”</p></div>';
		}
		// Second copy makes the marquee loop seamlessly; hidden from screen readers.
		return '<section class="pmc-feedback" aria-label="Customer feedback"><h2>Customer Feedback ❤</h2>'
			. '<div class="pmc-fb-track"><div class="pmc-fb-set">' . $cards . '</div><div class="pmc-fb-set" aria-hidden="true">' . $cards . '</div></div></section>';
	}

	private static function offers() {
		$regions = array(
			'uae'           => array( '🇦🇪', 'UAE SPECIAL OFFER', "Hi Perfumarathi, I'd like the UAE special offer." ),
			'india'         => array( '🇮🇳', 'INDIA / KERALA SPECIAL OFFER', "Hi Perfumarathi, I'd like the India / Kerala special offer." ),
			'international' => array( '🌐', 'INTERNATIONAL ORDER', "Hi Perfumarathi, I'd like to place an international order." ),
		);
		$h = '<section class="pmc-offer" aria-label="Special offers"><div class="pmc-offer-badge">SPECIAL OFFERS</div><h2>🔥 SPECIAL PERFUME OFFER 🔥</h2><div class="pmc-regions">';
		foreach ( $regions as $key => $r ) {
			// Admin can point a button at a page: wp option update pm_offer_url_uae https://...
			$custom = (string) get_option( 'pm_offer_url_' . $key );
			$url    = $custom ? $custom : self::wa( $r[2] );
			$ext    = $custom ? '' : ' target="_blank" rel="noopener noreferrer"';
			$h     .= '<a class="pmc-region' . ( 'international' === $key ? ' pmc-region--intl' : '' ) . '" href="' . esc_url( $url ) . '"' . $ext . '><span aria-hidden="true">' . esc_html( $r[0] ) . '</span> <span>' . esc_html( $r[1] ) . '</span></a>';
		}
		return $h . '</div></section>';
	}

	public static function render( $atts ) {
		$a     = shortcode_atts( array( 'brand' => 'perfumarathi' ), $atts, 'pm_collection' );
		$brand = sanitize_title( $a['brand'] );
		$term  = get_term_by( 'slug', $brand, 'pm_brand' );
		$label = $term ? $term->name : ucfirst( $brand );
		$combos     = self::products( $brand, true );
		$collection = self::products( $brand, false );

		$h  = '<div class="pmc" data-brand="' . esc_attr( $brand ) . '">';
		$h .= self::hero_html( $brand );
		$h .= self::feedback( $brand );
		$h .= self::offers();
		if ( $combos ) {
			$h .= '<section class="pmc-section" aria-label="Combos"><h2>Choose Your Combo</h2><p class="pmc-sub">Premium combo collections. Open a combo for details, or add it to your bag.</p><div class="pmc-grid pmc-grid--combos">';
			foreach ( $combos as $p ) {
				$h .= self::card( $p, true );
			}
			$h .= '</div></section>';
		}
		$h .= '<section class="pmc-section" id="pmc-collection" aria-label="Perfume collection"><h2>Our Perfume Collection</h2><p class="pmc-sub">Discover our fragrances and their notes. Tap a perfume to open its page.</p>';
		if ( $collection ) {
			$h .= '<div class="pmc-grid">';
			foreach ( $collection as $p ) {
				$h .= self::card( $p );
			}
			$h .= '</div>';
		} else {
			$h .= '<div class="pm-empty"><h3>New perfumes coming soon</h3><p>Message us on WhatsApp for the latest collection.</p></div>';
		}
		$h .= '</section></div>';
		return $h;
	}

	/* ---------- Single product page ---------- */

	public static function tagline() {
		global $product;
		if ( $product ) {
			$t = (string) get_post_meta( $product->get_id(), '_pm_tagline', true );
			if ( $t ) {
				echo '<p class="pmc-single-tag">' . esc_html( $t ) . '</p>';
			}
		}
	}

	public static function traits() {
		global $product;
		if ( $product ) {
			$t = (string) get_post_meta( $product->get_id(), '_pm_traits', true );
			if ( $t ) {
				echo '<p class="pmc-traits">' . esc_html( $t ) . '</p>';
			}
		}
	}

	public static function enquire() {
		global $product;
		if ( ! $product ) {
			return;
		}
		if ( '' === (string) $product->get_price() ) {
			echo '<span class="pm-price-on-request">Price on request</span>';
		}
		echo '<a class="pm-enquire" href="' . esc_url( self::wa( "Hi Perfumarathi, I'm interested in " . $product->get_name() . '.' ) ) . '" target="_blank" rel="noopener noreferrer">WhatsApp • Ask about ' . esc_html( $product->get_name() ) . '</a>';
	}
}
