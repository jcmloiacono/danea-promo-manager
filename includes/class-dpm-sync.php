<?php
defined( 'ABSPATH' ) || exit;

/**
 * Motore di sincronizzazione: categorie promo e prezzo scontato.
 */
class DPM_Sync {

	const META_LINK = 'meta_product_link';
	const META_FLAG = '_dpm_applied';

	private static $queue = array();
	private static $busy  = false;

	public static function init() {
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'process_queue' ) );
	}

	/**
	 * Quando Danea modifica il codice promo o il prezzo di listino,
	 * il prodotto viene messo in coda. Viene elaborato a fine richiesta,
	 * cosi tutti i meta del prodotto sono gia aggiornati.
	 */
	public static function on_meta( $meta_id, $post_id, $meta_key ) {
		if ( self::$busy ) {
			return;
		}
		if ( self::META_LINK !== $meta_key && '_regular_price' !== $meta_key ) {
			return;
		}
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		self::$queue[ (int) $post_id ] = true;
	}

	public static function process_queue() {
		if ( empty( self::$queue ) || ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$ids         = array_keys( self::$queue );
		self::$queue = array();
		foreach ( $ids as $id ) {
			self::sync_product( $id );
		}
	}

	/**
	 * Sincronizza un singolo prodotto. Ritorna true se e stato modificato qualcosa.
	 */
	public static function sync_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || $product->is_type( array( 'variable', 'variation' ) ) ) {
			return false;
		}

		self::$busy = true;
		$changed    = false;

		try {
			$promo_cat_ids = DPM_Promos::cat_ids();
			$value         = get_post_meta( $product_id, self::META_LINK, true );
			$promo         = DPM_Promos::get_by_value( is_scalar( $value ) ? $value : '' );

			// Categorie.
			$current      = array_map( 'intval', $product->get_category_ids() );
			$was_in_promo = (bool) array_intersect( $current, $promo_cat_ids );
			$new          = array_diff( $current, $promo_cat_ids );
			if ( $promo ) {
				$new[] = (int) $promo['cat_id'];
			}
			$new = array_values( array_unique( array_map( 'intval', $new ) ) );

			$a = $current;
			$b = $new;
			sort( $a );
			sort( $b );
			if ( $a !== $b ) {
				$product->set_category_ids( $new );
				$changed = true;
			}

			// Prezzo scontato.
			$flag         = get_post_meta( $product_id, self::META_FLAG, true );
			$regular      = (float) $product->get_regular_price();
			$current_sale = $product->get_sale_price();

			if ( $promo && $promo['discount'] > 0 && $regular > 0 ) {
				$sale = wc_format_decimal( round( $regular * ( 1 - $promo['discount'] / 100 ), 2 ), 2 );
				if ( '' === $current_sale || abs( (float) $current_sale - (float) $sale ) > 0.001 ) {
					$product->set_sale_price( $sale );
					$changed = true;
				}
				// Lo sconto promo non deve avere date programmate.
				if ( $product->get_date_on_sale_from( 'edit' ) || $product->get_date_on_sale_to( 'edit' ) ) {
					$product->set_date_on_sale_from( null );
					$product->set_date_on_sale_to( null );
					$changed = true;
				}
				$flag_needed = true;
			} elseif ( $flag || ( ! $promo && $was_in_promo ) ) {
				if ( '' !== $current_sale ) {
					$product->set_sale_price( '' );
					$changed = true;
				}
				$flag_needed = false;
			} else {
				$flag_needed = (bool) $flag;
			}

			if ( $changed ) {
				$product->save();
			}

			if ( $flag_needed && ! $flag ) {
				update_post_meta( $product_id, self::META_FLAG, '1' );
			} elseif ( ! $flag_needed && $flag ) {
				delete_post_meta( $product_id, self::META_FLAG );
			}
		} catch ( \Exception $e ) {
			$changed = false;
		}

		self::$busy = false;
		return $changed;
	}

	/**
	 * Elenco degli ID dei prodotti da controllare:
	 * quelli con codice promo, quelli gia in una categoria promo
	 * e quelli con sconto applicato dal plugin.
	 */
	public static function collect_ids() {
		$base = array(
			'post_type'     => 'product',
			'post_status'   => 'any',
			'fields'        => 'ids',
			'nopaging'      => true,
			'no_found_rows' => true,
		);

		$ids = get_posts( $base + array( 'meta_key' => self::META_LINK ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$ids = array_merge( $ids, get_posts( $base + array( 'meta_key' => self::META_FLAG ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery

		$cat_ids = DPM_Promos::cat_ids();
		if ( $cat_ids ) {
			$ids = array_merge(
				$ids,
				get_posts(
					$base + array(
						'tax_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
							array(
								'taxonomy' => 'product_cat',
								'field'    => 'term_id',
								'terms'    => $cat_ids,
								'operator' => 'IN',
							),
						),
					)
				)
			);
		}

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}
}
