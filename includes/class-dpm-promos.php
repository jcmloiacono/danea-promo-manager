<?php
defined( 'ABSPATH' ) || exit;

/**
 * Archivio delle promo: categoria, sconto, codice per Danea e alias.
 */
class DPM_Promos {

	const OPTION = 'dpm_promos';

	private static $map = null;

	public static function all() {
		$promos = get_option( self::OPTION, array() );
		return is_array( $promos ) ? $promos : array();
	}

	public static function save( $promos ) {
		update_option( self::OPTION, $promos, false );
		self::$map = null;
	}

	/** Normalizza un valore: "Promo Sicilia" e "promo-sicilia" diventano uguali. */
	public static function normalize( $value ) {
		return sanitize_title( trim( (string) $value ) );
	}

	public static function sanitize_discount( $value ) {
		$value = (float) str_replace( ',', '.', (string) $value );
		return max( 0, min( 100, round( $value, 2 ) ) );
	}

	public static function generate_code( $cat_id ) {
		$term = get_term( (int) $cat_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}
		return self::normalize( $term->name );
	}

	private static function used_codes( $promos, $except_cat_id = 0 ) {
		$used = array();
		foreach ( $promos as $cat_id => $promo ) {
			if ( (int) $cat_id === (int) $except_cat_id ) {
				continue;
			}
			$used[] = self::normalize( $promo['code'] );
			foreach ( self::split_aliases( isset( $promo['aliases'] ) ? $promo['aliases'] : '' ) as $alias ) {
				$used[] = $alias;
			}
		}
		return $used;
	}

	private static function split_aliases( $aliases ) {
		$out = array();
		foreach ( explode( ',', (string) $aliases ) as $alias ) {
			$alias = self::normalize( $alias );
			if ( '' !== $alias ) {
				$out[] = $alias;
			}
		}
		return $out;
	}

	/** Aggiunge una promo. Ritorna: added | exists | invalid */
	public static function add( $cat_id, $discount, $aliases = '' ) {
		$cat_id = (int) $cat_id;
		$promos = self::all();

		if ( isset( $promos[ $cat_id ] ) ) {
			return 'exists';
		}

		$code = self::generate_code( $cat_id );
		if ( '' === $code ) {
			return 'invalid';
		}
		if ( in_array( $code, self::used_codes( $promos ), true ) ) {
			$code .= '-' . $cat_id;
		}

		$promos[ $cat_id ] = array(
			'cat_id'   => $cat_id,
			'discount' => self::sanitize_discount( $discount ),
			'code'     => $code,
			'aliases'  => sanitize_text_field( $aliases ),
		);
		self::save( $promos );
		return 'added';
	}

	public static function update_discount( $cat_id, $discount ) {
		$promos = self::all();
		$cat_id = (int) $cat_id;
		if ( ! isset( $promos[ $cat_id ] ) ) {
			return false;
		}
		$promos[ $cat_id ]['discount'] = self::sanitize_discount( $discount );
		self::save( $promos );
		return true;
	}

	public static function delete( $cat_id ) {
		$promos = self::all();
		unset( $promos[ (int) $cat_id ] );
		self::save( $promos );
	}

	/** Importa la mappatura del vecchio script. Ritorna il numero di promo create. */
	public static function import_legacy() {
		$legacy = array(
			4901 => 'Promo Estate, Promo State',
			4906 => 'Promo Inverno',
			4968 => 'Promo Sicilia',
			4908 => 'Promo Faidate, Promo Fai da te',
		);
		$count = 0;
		foreach ( $legacy as $cat_id => $aliases ) {
			if ( 'added' === self::add( $cat_id, 0, $aliases ) ) {
				$count++;
			}
		}
		return $count;
	}

	/** IDs di tutte le categorie gestite dal plugin. */
	public static function cat_ids() {
		return array_map( 'intval', array_keys( self::all() ) );
	}

	private static function map() {
		if ( null === self::$map ) {
			self::$map = array();
			foreach ( self::all() as $promo ) {
				$keys = array_merge( array( self::normalize( $promo['code'] ) ), self::split_aliases( isset( $promo['aliases'] ) ? $promo['aliases'] : '' ) );
				foreach ( $keys as $key ) {
					if ( '' !== $key && ! isset( self::$map[ $key ] ) ) {
						self::$map[ $key ] = $promo;
					}
				}
			}
		}
		return self::$map;
	}

	private static $promo_ids = null;

	/**
	 * IDs dei prodotti il cui meta_product_link corrisponde a una promo.
	 * Risultato in cache per la durata della richiesta. Formato: [ id => true ].
	 */
	public static function product_ids() {
		if ( null === self::$promo_ids ) {
			global $wpdb;
			self::$promo_ids = array();
			if ( self::all() ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''",
						'meta_product_link'
					)
				);
				foreach ( $rows as $row ) {
					if ( self::get_by_value( $row->meta_value ) ) {
						self::$promo_ids[ (int) $row->post_id ] = true;
					}
				}
			}
		}
		return self::$promo_ids;
	}

	public static function is_promo_product( $product_id ) {
		$ids = self::product_ids();
		return isset( $ids[ (int) $product_id ] );
	}

	/** Trova la promo corrispondente al valore inviato da Danea. */
	public static function get_by_value( $value ) {
		$key = self::normalize( $value );
		if ( '' === $key ) {
			return null;
		}
		$map = self::map();
		return isset( $map[ $key ] ) ? $map[ $key ] : null;
	}
}
