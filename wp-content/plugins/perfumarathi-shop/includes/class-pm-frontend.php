<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shortcodes:
 *   [pm_shop brand="perfumarathi" category="" collection=""]  filterable product grid
 *   [pm_home_shop]                                           homepage shopping sections
 *   [pm_cart_icon]                                           shopping bag icon + live counter
 *   [pm_order_tracking]                                      guest order tracking form
 */
class PM_Frontend {

	public static function init() {
		add_shortcode( 'pm_shop', array( __CLASS__, 'sc_shop' ) );
		add_shortcode( 'pm_home_shop', array( __CLASS__, 'sc_home' ) );
		add_shortcode( 'pm_cart_icon', array( __CLASS__, 'sc_cart_icon' ) );
		add_shortcode( 'pm_order_tracking', array( __CLASS__, 'sc_tracking' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'drawer' ) );
		add_action( 'init', array( __CLASS__, 'register_subscriber_cpt' ) );
		add_action( 'admin_post_nopriv_pm_newsletter', array( __CLASS__, 'newsletter' ) );
		add_action( 'admin_post_pm_newsletter', array( __CLASS__, 'newsletter' ) );
	}

	public static function tracking_url() {
		$id = (int) get_option( 'pm_tracking_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/' );
	}

	public static function assets() {
		// Version = file time, so browsers and Cloudflare fetch the new file after every update.
		$ver = function ( $f ) { $t = @filemtime( PM_SHOP_DIR . 'assets/' . $f ); return $t ? PM_SHOP_VERSION . '.' . $t : PM_SHOP_VERSION; };
		wp_register_style( 'pm-shop', PM_SHOP_URL . 'assets/pm-shop.css', array(), $ver( 'pm-shop.css' ) );
		wp_register_script( 'pm-shop', PM_SHOP_URL . 'assets/pm-shop.js', array(), $ver( 'pm-shop.js' ), true );
		// Loaded sitewide so the bag counter and drawer work on every page.
		wp_enqueue_style( 'pm-shop' );
		wp_enqueue_script( 'pm-shop' );
		wp_localize_script( 'pm-shop', 'PMShop', array(
			'rest'     => esc_url_raw( rest_url( 'pm/v1/' ) ),
			'store'    => esc_url_raw( rest_url( 'wc/store/v1/' ) ),
			'checkout' => esc_url_raw( wc_get_checkout_url() ),
			'cartUrl'  => esc_url_raw( wc_get_cart_url() ),
			'shopUrl'  => esc_url_raw( wc_get_page_permalink( 'shop' ) ),
			'currency' => get_woocommerce_currency(),
			'loggedIn' => is_user_logged_in(),
			'wa'       => '971528209716',
			'userId'   => get_current_user_id(),
			'wpNonce'  => wp_create_nonce( 'wp_rest' ),
			'terms'    => array(
				'perfumarathi' => ( $t = get_term_link( 'perfumarathi', 'pm_brand' ) ) && ! is_wp_error( $t ) ? esc_url_raw( $t ) : '',
				'fixnote'      => ( $t = get_term_link( 'fixnote', 'pm_brand' ) ) && ! is_wp_error( $t ) ? esc_url_raw( $t ) : '',
			),
		) );
	}

	/* ---------- Shortcodes ---------- */

	public static function sc_shop( $atts ) {
		$a = shortcode_atts( array( 'brand' => '', 'category' => '', 'collection' => '', 'per_page' => 12 ), $atts, 'pm_shop' );
		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
		ob_start();
		?>
		<section class="pm-shop" data-brand="<?php echo esc_attr( sanitize_title( $a['brand'] ) ); ?>" data-category="<?php echo esc_attr( sanitize_title( $a['category'] ) ); ?>" data-collection="<?php echo esc_attr( sanitize_key( $a['collection'] ) ); ?>" data-per-page="<?php echo esc_attr( absint( $a['per_page'] ) ); ?>">
			<form class="pm-toolbar" role="search" onsubmit="return false">
				<label class="screen-reader-text" for="pm-search">Search perfumes</label>
				<input id="pm-search" type="search" class="pm-search" placeholder="Search perfumes&hellip;">
				<?php if ( ! is_wp_error( $cats ) && $cats ) : ?>
				<label class="screen-reader-text" for="pm-cat">Category</label>
				<select id="pm-cat" class="pm-cat">
					<option value="">All categories</option>
					<?php foreach ( $cats as $c ) : ?>
						<option value="<?php echo esc_attr( $c->slug ); ?>" <?php selected( $a['category'], $c->slug ); ?>><?php echo esc_html( $c->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php endif; ?>
				<label class="screen-reader-text" for="pm-sort">Sort by</label>
				<select id="pm-sort" class="pm-sort">
					<option value="latest">Latest arrivals</option>
					<option value="popularity">Popularity</option>
					<option value="price_asc">Price: low to high</option>
					<option value="price_desc">Price: high to low</option>
					<option value="rating">Top rated</option>
				</select>
			</form>
			<div class="pm-grid" aria-live="polite"></div>
			<div class="pm-more-wrap"><button type="button" class="pm-btn pm-btn--ghost pm-more" hidden>Load more</button></div>
		</section>
		<?php
		return ob_get_clean();
	}

	public static function sc_cart_icon() {
		return '<button type="button" class="pm-bag" aria-label="Open shopping bag" data-pm-open-cart>'
			. '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 7h12l1 13H5L6 7Z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>'
			. '<span class="pm-bag__count" data-pm-count>0</span></button>';
	}

	public static function sc_tracking() {
		ob_start();
		?>
		<form class="pm-track" novalidate>
			<h2>Track your order</h2>
			<label>Order number<input type="text" name="order" inputmode="numeric" required autocomplete="off"></label>
			<label>Billing email<input type="email" name="email" required autocomplete="email"></label>
			<button type="submit" class="pm-btn">Track order</button>
			<div class="pm-track__result" role="status" aria-live="polite"></div>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function sc_home() {
		$sections = array(
			array( 'Featured Perfumes', array( 'collection' => 'featured' ) ),
			array( 'Best Sellers', array( 'collection' => 'best_selling' ) ),
			array( 'New Arrivals', array( 'orderby' => 'latest' ) ),
			array( 'Special Offers', array( 'collection' => 'on_sale' ) ),
			array( "Men's Collection", array( 'category' => 'mens-perfumes' ) ),
			array( "Women's Collection", array( 'category' => 'womens-perfumes' ) ),
			array( 'Unisex Collection', array( 'category' => 'unisex-perfumes' ) ),
			array( 'Premium Fragrance Collection', array( 'category' => 'luxury-collection' ) ),
		);
		ob_start();
		?>
		<div class="pm-home">
			<?php foreach ( $sections as $s ) : ?>
			<section class="pm-home__section" data-pm-rail='<?php echo esc_attr( wp_json_encode( $s[1] ) ); ?>'>
				<h2 class="pm-home__title"><?php echo esc_html( $s[0] ); ?></h2>
				<div class="pm-grid pm-grid--rail"></div>
			</section>
			<?php endforeach; ?>
			<?php echo self::testimonials(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
			<section class="pm-home__section pm-insta" aria-label="Instagram">
				<h2 class="pm-home__title">On Instagram</h2>
				<?php
				// Paste an Instagram feed plugin shortcode into the option "pm_instagram_shortcode" (e.g. Smash Balloon).
				$sc = get_option( 'pm_instagram_shortcode' );
				echo $sc ? do_shortcode( wp_kses_post( $sc ) ) : '<p><a class="pm-btn pm-btn--ghost" href="https://www.instagram.com/perfumarathi/" target="_blank" rel="noopener noreferrer">Follow @perfumarathi</a></p>';
				?>
			</section>
			<?php echo self::newsletter_form(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside. ?>
		</div>
		<?php
		return ob_get_clean();
	}

	private static function brand_link( $slug ) {
		$t = get_term_link( $slug, 'pm_brand' );
		return is_wp_error( $t ) ? wc_get_page_permalink( 'shop' ) : $t;
	}

	/** Real approved 5/4-star product reviews only; renders nothing if none exist. */
	private static function testimonials() {
		$comments = get_comments( array( 'type' => 'review', 'status' => 'approve', 'number' => 3, 'meta_key' => 'rating', 'meta_value' => array( 4, 5 ), 'meta_compare' => 'IN' ) );
		if ( ! $comments ) {
			return '';
		}
		$h = '<section class="pm-home__section pm-testimonials" aria-label="Customer testimonials"><h2 class="pm-home__title">What Customers Say</h2><div class="pm-testimonials__row">';
		foreach ( $comments as $c ) {
			$h .= '<blockquote><p>' . esc_html( wp_trim_words( $c->comment_content, 35 ) ) . '</p><cite>' . esc_html( $c->comment_author ) . '</cite></blockquote>';
		}
		return $h . '</div></section>';
	}

	/* ---------- Newsletter ---------- */

	public static function register_subscriber_cpt() {
		register_post_type( 'pm_subscriber', array(
			'labels'       => array( 'name' => 'Subscribers', 'singular_name' => 'Subscriber' ),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => 'woocommerce',
			'supports'     => array( 'title' ),
			'capability_type' => 'post',
			'map_meta_cap' => true,
		) );
	}

	private static function newsletter_form() {
		ob_start();
		?>
		<section class="pm-home__section pm-newsletter" aria-label="Newsletter">
			<h2 class="pm-home__title">Join Our Newsletter</h2>
			<?php if ( isset( $_GET['pm_sub'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<p role="status"><?php echo 'ok' === $_GET['pm_sub'] ? 'Thank you for subscribing!' : 'Please enter a valid email address.'; // phpcs:ignore ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pm_newsletter">
				<?php wp_nonce_field( 'pm_newsletter', 'pm_nl_nonce' ); ?>
				<input type="text" name="website" tabindex="-1" autocomplete="off" class="pm-hp" aria-hidden="true">
				<label class="screen-reader-text" for="pm-nl-email">Email address</label>
				<input id="pm-nl-email" type="email" name="email" placeholder="Your email" required>
				<button class="pm-btn" type="submit">Subscribe</button>
			</form>
		</section>
		<?php
		return ob_get_clean();
	}

	public static function newsletter() {
		$back = wp_get_referer() ?: home_url( '/' );
		$back = remove_query_arg( 'pm_sub', $back );
		if ( ! isset( $_POST['pm_nl_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pm_nl_nonce'] ) ), 'pm_newsletter' ) || ! empty( $_POST['website'] ) ) {
			wp_safe_redirect( add_query_arg( 'pm_sub', 'err', $back ) );
			exit;
		}
		$ip  = PM_REST::client_ip();
		$key = 'pm_nl_' . md5( $ip );
		if ( (int) get_transient( $key ) >= 5 ) {
			wp_safe_redirect( add_query_arg( 'pm_sub', 'err', $back ) );
			exit;
		}
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( 'pm_sub', 'err', $back ) );
			exit;
		}
		$exists = get_posts( array( 'post_type' => 'pm_subscriber', 'title' => $email, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) );
		if ( ! $exists ) {
			wp_insert_post( array( 'post_type' => 'pm_subscriber', 'post_title' => $email, 'post_status' => 'publish' ) );
		}
		wp_safe_redirect( add_query_arg( 'pm_sub', 'ok', $back ) );
		exit;
	}

	/* ---------- Cart drawer + quick view container ---------- */

	public static function drawer() {
		// Sitewide bag so the live counter exists on every page; hidden by JS if a header [pm_cart_icon] exists.
		// Disable with: wp option update pm_floating_bag no
		if ( 'no' !== get_option( 'pm_floating_bag', 'yes' ) ) {
			echo '<div class="pm-bag-float">' . self::sc_cart_icon() . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- static markup.
		}
		?>
		<div class="pm-overlay" data-pm-close hidden></div>
		<aside class="pm-drawer" id="pm-drawer" role="dialog" aria-modal="true" aria-label="Shopping bag" aria-hidden="true">
			<header class="pm-drawer__head"><h2>Your Bag</h2><button type="button" class="pm-x" data-pm-close aria-label="Close shopping bag">&times;</button></header>
			<div class="pm-drawer__body"></div>
			<footer class="pm-drawer__foot"></footer>
		</aside>
		<div class="pm-modal" id="pm-modal" role="dialog" aria-modal="true" aria-label="Quick view" aria-hidden="true"></div>
		<div class="pm-toasts" role="status" aria-live="polite"></div>
		<?php
	}
}
