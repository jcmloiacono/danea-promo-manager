<?php
/**
 * Plugin Name: Promo Danea per WooCommerce
 * Description: Assegna automaticamente i prodotti sincronizzati da Danea Easyfatt alle categorie promo e calcola il prezzo scontato in base a un codice.
 * Version: 1.1.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: danea-promo
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'DPM_VERSION', '1.1.0' );
define( 'DPM_FILE', __FILE__ );
define( 'DPM_DIR', plugin_dir_path( __FILE__ ) );
define( 'DPM_URL', plugin_dir_url( __FILE__ ) );

/**
 * Funzioni pubbliche per gli snippet di raggruppamento (shop e ricerca).
 * Sono sicure da chiamare anche se il plugin non e attivo (usare function_exists).
 */
function dpm_is_promo_product( $product_id ) {
	return class_exists( 'DPM_Promos' ) && DPM_Promos::is_promo_product( $product_id );
}

function dpm_promo_product_ids() {
	return class_exists( 'DPM_Promos' ) ? array_keys( DPM_Promos::product_ids() ) : array();
}

// Compatibilita con HPOS di WooCommerce.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DPM_FILE, true );
	}
} );

add_action( 'plugins_loaded', function () {
	load_plugin_textdomain( 'danea-promo', false, dirname( plugin_basename( DPM_FILE ) ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Promo Danea richiede WooCommerce attivo.', 'danea-promo' ) . '</p></div>';
		} );
		return;
	}

	require_once DPM_DIR . 'includes/class-dpm-promos.php';
	require_once DPM_DIR . 'includes/class-dpm-sync.php';
	require_once DPM_DIR . 'includes/class-dpm-admin.php';

	DPM_Sync::init();

	if ( is_admin() ) {
		DPM_Admin::init();
	}
} );
