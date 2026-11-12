<?php
defined( 'ABSPATH' ) || exit;

/**
 * Public, read-only storefront endpoints under /wp-json/pm/v1.
 * Cart / checkout / coupons use the WooCommerce Store API
 * (/wp-json/wc/store/v1/*), which validates everything server-side.
 */
class PM_REST {

	const NS = 'pm/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route( self::NS, '/products', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'products' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'brand'      => array( 'sanitize_callback' => 'sanitize_title' ),
				'category'   => array( 'sanitize_callback' => 'sanitize_title' ),
				'collection' => array( 'sanitize_callback' => 'sanitize_key' ),
				'search'     => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'orderby'    => array( 'sanitize_callback' => 'sanitize_key', 'default' => 'latest' ),
				'page'       => array( 'sanitize_callback' => 'absint', 'default' => 1 ),
				'per_page'   => array( 'sanitize_callback' => 'absint', 'default' => 12 ),
				'ids'        => array( 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
		register_rest_route( self::NS, '/products/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'product' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/track', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'track' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function card( WC_Product $p ) {
		$brand = PM_Catalog::get_brand( $p->get_id() );
		$img   = wp_get_attachment_image_url( $p->get_image_id(), 'woocommerce_thumbnail' );
		return array(
			'id'           => $p->get_id(),
			'name'         => $p->get_name(),
			'url'          => get_permalink( $p->get_id() ),
			'brand'        => $brand ? $brand->name : '',
			'brand_slug'   => $brand ? $brand->slug : '',
			'image'        => $img ? $img : wc_placeholder_img_src(),
			'price_html'   => wp_kses_post( $p->get_price_html() ),
			'on_sale'      => $p->is_on_sale(),
			'rating'       => (float) $p->get_average_rating(),
			'reviews'      => (int) $p->get_review_count(),
			'in_stock'     => $p->is_in_stock(),
			'stock_label'  => $p->is_in_stock() ? ( $p->is_on_backorder() ? 'On backorder' : 'In stock' ) : 'Out of stock',
			'type'         => $p->get_type(),
			'purchasable'  => $p->is_purchasable() && $p->is_in_stock(),
			'priced'       => '' !== (string) $p->get_price(),
			'simple'       => $p->is_type( 'simple' ),
		);
	}

	public static function products( WP_REST_Request $r ) {
		$per_page = min( 48, max( 1, (int) $r['per_page'] ) );
		$args     = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => max( 1, (int) $r['page'] ),
			'tax_query'      => array( 'relation' => 'AND' ),
		);
		$vis = wc_get_product_visibility_term_ids();
		if ( ! empty( $vis['exclude-from-catalog'] ) ) {
			$args['tax_query'][] = array( 'taxonomy' => 'product_visibility', 'field' => 'term_taxonomy_id', 'terms' => array( $vis['exclude-from-catalog'] ), 'operator' => 'NOT IN' );
		}
		if ( $r['brand'] ) {
			$args['tax_query'][] = array( 'taxonomy' => 'pm_brand', 'field' => 'slug', 'terms' => $r['brand'] );
		}
		if ( $r['category'] ) {
			$args['tax_query'][] = array( 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $r['category'] );
		}
		if ( $r['search'] ) {
			$args['s'] = $r['search'];
		}
		if ( $r['ids'] ) {
			$args['post__in'] = array_filter( array_map( 'absint', explode( ',', $r['ids'] ) ) ) ?: array( 0 );
			$args['orderby']  = 'post__in';
		}
		switch ( $r['collection'] ) {
			case 'featured':
				if ( ! empty( $vis['featured'] ) ) {
					$args['tax_query'][] = array( 'taxonomy' => 'product_visibility', 'field' => 'term_taxonomy_id', 'terms' => array( $vis['featured'] ) );
				}
				break;
			case 'on_sale':
				$args['post__in'] = wc_get_product_ids_on_sale() ?: array( 0 );
				break;
			case 'best_selling':
				$r['orderby'] = 'popularity';
				break;
		}
		if ( empty( $args['orderby'] ) ) {
			switch ( $r['orderby'] ) {
				case 'price_asc':
					$args['meta_key'] = '_price'; $args['orderby'] = 'meta_value_num'; $args['order'] = 'ASC'; break;
				case 'price_desc':
					$args['meta_key'] = '_price'; $args['orderby'] = 'meta_value_num'; $args['order'] = 'DESC'; break;
				case 'popularity':
					$args['meta_key'] = 'total_sales'; $args['orderby'] = 'meta_value_num'; $args['order'] = 'DESC'; break;
				case 'rating':
					$args['meta_key'] = '_wc_average_rating'; $args['orderby'] = 'meta_value_num'; $args['order'] = 'DESC'; break;
				default:
					$args['orderby'] = 'date'; $args['order'] = 'DESC';
			}
		}
		$q     = new WP_Query( $args );
		$items = array();
		foreach ( $q->posts as $post ) {
			$p = wc_get_product( $post );
			if ( $p && $p->is_visible() ) {
				$items[] = self::card( $p );
			}
		}
		$res = new WP_REST_Response( array( 'items' => $items, 'total' => (int) $q->found_posts, 'pages' => (int) $q->max_num_pages ) );
		$res->header( 'Cache-Control', 'public, max-age=60' );
		return $res;
	}

	public static function product( WP_REST_Request $r ) {
		$p = wc_get_product( (int) $r['id'] );
		if ( ! $p || 'publish' !== $p->get_status() || ! $p->is_visible() ) {
			return new WP_Error( 'pm_not_found', 'Product not found', array( 'status' => 404 ) );
		}
		$data              = self::card( $p );
		$data['excerpt']   = wp_kses_post( wp_trim_words( $p->get_short_description() ?: $p->get_description(), 40 ) );
		$data['notes']     = PM_Catalog::get_notes( $p->get_id() );
		$data['gallery']   = array();
		foreach ( array_merge( array( $p->get_image_id() ), $p->get_gallery_image_ids() ) as $aid ) {
			$u = $aid ? wp_get_attachment_image_url( $aid, 'large' ) : '';
			if ( $u ) {
				$data['gallery'][] = $u;
			}
		}
		$data['sizes'] = array();
		if ( $p->is_type( 'variable' ) ) {
			foreach ( $p->get_available_variations() as $v ) {
				$data['sizes'][] = array(
					'variation_id' => $v['variation_id'],
					'label'        => implode( ' / ', array_values( $v['attributes'] ) ),
					'price_html'   => wp_kses_post( $v['price_html'] ?: '' ),
					'in_stock'     => (bool) $v['is_in_stock'],
				);
			}
		}
		return $data;
	}

	/** Cloudflare edge ranges (https://www.cloudflare.com/ips-v4 and /ips-v6, checked Nov 2026). */
	const CLOUDFLARE = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
		'190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
		'104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
	);

	private static function in_cidr( $ip, $cidr ) {
		list( $net, $bits ) = explode( '/', $cidr );
		$a = @inet_pton( $ip );
		$b = @inet_pton( $net );
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $a, 0, $bytes ) !== substr( $b, 0, $bytes ) ) {
			return false;
		}
		$rem = $bits % 8;
		if ( ! $rem ) {
			return true;
		}
		$mask = chr( ( 0xff << ( 8 - $rem ) ) & 0xff );
		return ( $a[ $bytes ] & $mask ) === ( $b[ $bytes ] & $mask );
	}

	/**
	 * Visitor IP for rate limiting. Trusts CF-Connecting-IP only when the request really comes from Cloudflare,
	 * so the header cannot be spoofed by clients that bypass Cloudflare. Filter: pm_client_ip.
	 */
	public static function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = $remote;
		if ( $remote && isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
			$cf = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( filter_var( $cf, FILTER_VALIDATE_IP ) ) {
				foreach ( self::CLOUDFLARE as $range ) {
					if ( self::in_cidr( $remote, $range ) ) {
						$ip = $cf;
						break;
					}
				}
			}
		}
		return (string) apply_filters( 'pm_client_ip', $ip ? $ip : 'unknown', $remote );
	}

	/** Guest/customer tracking: requires order number AND billing email. Rate limited per IP. */
	public static function track( WP_REST_Request $r ) {
		$ip  = self::client_ip();
		$key = 'pm_track_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 10 ) {
			return new WP_Error( 'pm_rate_limited', 'Too many attempts. Please try again in a few minutes.', array( 'status' => 429 ) );
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );

		$id    = absint( $r->get_param( 'order' ) );
		$email = sanitize_email( (string) $r->get_param( 'email' ) );
		$order = $id ? wc_get_order( $id ) : false;
		// Same generic error for unknown order and wrong email (no enumeration).
		if ( ! $order || ! $email || 0 !== strcasecmp( $order->get_billing_email(), $email ) ) {
			return new WP_Error( 'pm_no_match', 'We could not find an order matching those details.', array( 'status' => 404 ) );
		}
		return array(
			'order'    => $order->get_order_number(),
			'status'   => $order->get_status(),
			'html'     => PM_Order_Statuses::render_timeline( $order ),
		);
	}
}
