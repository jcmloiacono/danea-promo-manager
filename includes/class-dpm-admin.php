<?php
defined( 'ABSPATH' ) || exit;

/**
 * Interfaccia di amministrazione (in italiano).
 */
class DPM_Admin {

	const CAP  = 'manage_woocommerce';
	const SLUG = 'danea-promo';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );

		add_action( 'admin_post_dpm_add', array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_dpm_update', array( __CLASS__, 'handle_update' ) );
		add_action( 'admin_post_dpm_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_dpm_import', array( __CLASS__, 'handle_import' ) );

		add_action( 'wp_ajax_dpm_sync_start', array( __CLASS__, 'ajax_sync_start' ) );
		add_action( 'wp_ajax_dpm_sync_batch', array( __CLASS__, 'ajax_sync_batch' ) );
	}

	public static function menu() {
		add_menu_page(
			__( 'Promo Danea', 'danea-promo' ),
			__( 'Promo Danea', 'danea-promo' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-tag',
			56
		);
	}

	public static function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}

		$wc_url = WC()->plugin_url();
		wp_register_style( 'dpm-select2', $wc_url . '/assets/css/select2.css', array(), DPM_VERSION );
		wp_register_script( 'dpm-selectwoo', $wc_url . '/assets/js/selectWoo/selectWoo.full.min.js', array( 'jquery' ), '1.0.6', true );

		wp_enqueue_style( 'dpm-select2' );
		wp_enqueue_style( 'dpm-admin', DPM_URL . 'assets/admin.css', array(), DPM_VERSION );
		wp_enqueue_script( 'dpm-admin', DPM_URL . 'assets/admin.js', array( 'jquery', 'dpm-selectwoo' ), DPM_VERSION, true );

		wp_localize_script(
			'dpm-admin',
			'dpmData',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'dpm_ajax' ),
				'i18n'    => array(
					'placeholder' => __( 'Cerca e seleziona una categoria...', 'danea-promo' ),
					'copied'      => __( 'Copiato!', 'danea-promo' ),
					'copy'        => __( 'Copia', 'danea-promo' ),
					'confirmDel'  => __( 'Eliminare questa promo? I prodotti gia assegnati non verranno modificati finche non sincronizzi.', 'danea-promo' ),
					'searching'   => __( 'Ricerca dei prodotti da controllare...', 'danea-promo' ),
					'nothing'     => __( 'Nessun prodotto da sincronizzare.', 'danea-promo' ),
					'progress'    => __( 'Prodotti elaborati: %1$s di %2$s (modificati: %3$s)', 'danea-promo' ),
					'done'        => __( 'Sincronizzazione completata. Prodotti controllati: %1$s. Prodotti modificati: %2$s.', 'danea-promo' ),
					'error'       => __( 'Si e verificato un errore. Riprova.', 'danea-promo' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Azioni (admin-post)
	 * ---------------------------------------------------------------- */

	private static function guard() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Non hai i permessi per eseguire questa azione.', 'danea-promo' ) );
		}
		check_admin_referer( 'dpm_action' );
	}

	private static function back( $msg ) {
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'dpm_msg' => $msg ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_add() {
		self::guard();
		$cat_id   = isset( $_POST['cat_id'] ) ? absint( $_POST['cat_id'] ) : 0;
		$discount = isset( $_POST['discount'] ) ? sanitize_text_field( wp_unslash( $_POST['discount'] ) ) : '';
		$aliases  = isset( $_POST['aliases'] ) ? sanitize_text_field( wp_unslash( $_POST['aliases'] ) ) : '';

		if ( ! $cat_id || '' === $discount ) {
			self::back( 'missing' );
		}
		self::back( DPM_Promos::add( $cat_id, $discount, $aliases ) );
	}

	public static function handle_update() {
		self::guard();
		$cat_id   = isset( $_POST['cat_id'] ) ? absint( $_POST['cat_id'] ) : 0;
		$discount = isset( $_POST['discount'] ) ? sanitize_text_field( wp_unslash( $_POST['discount'] ) ) : '0';
		DPM_Promos::update_discount( $cat_id, $discount );
		self::back( 'updated' );
	}

	public static function handle_delete() {
		self::guard();
		$cat_id = isset( $_POST['cat_id'] ) ? absint( $_POST['cat_id'] ) : 0;
		DPM_Promos::delete( $cat_id );
		self::back( 'deleted' );
	}

	public static function handle_import() {
		self::guard();
		$count = DPM_Promos::import_legacy();
		self::back( $count ? 'imported' : 'import_none' );
	}

	/* ------------------------------------------------------------------
	 * AJAX: sincronizzazione a blocchi
	 * ---------------------------------------------------------------- */

	private static function ajax_guard() {
		check_ajax_referer( 'dpm_ajax', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
	}

	public static function ajax_sync_start() {
		self::ajax_guard();
		$ids = DPM_Sync::collect_ids();
		set_transient( 'dpm_ids_' . get_current_user_id(), $ids, HOUR_IN_SECONDS );
		wp_send_json_success( array( 'total' => count( $ids ) ) );
	}

	public static function ajax_sync_batch() {
		self::ajax_guard();

		$key = 'dpm_ids_' . get_current_user_id();
		$ids = get_transient( $key );
		if ( ! is_array( $ids ) ) {
			wp_send_json_error( array( 'message' => 'expired' ) );
		}

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$size   = isset( $_POST['size'] ) ? min( 100, max( 1, absint( $_POST['size'] ) ) ) : 40;
		$slice  = array_slice( $ids, $offset, $size );

		$changed = 0;
		foreach ( $slice as $id ) {
			if ( DPM_Sync::sync_product( $id ) ) {
				$changed++;
			}
		}

		$processed = $offset + count( $slice );
		$done      = $processed >= count( $ids );
		if ( $done ) {
			delete_transient( $key );
		}

		wp_send_json_success(
			array(
				'processed' => $processed,
				'changed'   => $changed,
				'done'      => $done,
			)
		);
	}

	/* ------------------------------------------------------------------
	 * Pagina
	 * ---------------------------------------------------------------- */

	private static function category_label( $term ) {
		$parts = array( $term->name );
		foreach ( get_ancestors( $term->term_id, 'product_cat' ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, 'product_cat' );
			if ( $ancestor && ! is_wp_error( $ancestor ) ) {
				array_unshift( $parts, $ancestor->name );
			}
		}
		return implode( ' > ', $parts );
	}

	private static function notice() {
		if ( empty( $_GET['dpm_msg'] ) ) {
			return;
		}
		$msg      = sanitize_key( wp_unslash( $_GET['dpm_msg'] ) );
		$messages = array(
			'added'       => array( 'success', __( 'Promo aggiunta. Copia il codice e inseriscilo in Danea.', 'danea-promo' ) ),
			'updated'     => array( 'success', __( 'Sconto aggiornato. Premi "Sincronizza ora" per applicarlo ai prodotti.', 'danea-promo' ) ),
			'deleted'     => array( 'success', __( 'Promo eliminata. Premi "Sincronizza ora" per aggiornare i prodotti.', 'danea-promo' ) ),
			'imported'    => array( 'success', __( 'Mappatura precedente importata. Imposta ora lo sconto di ogni promo (attualmente 0%, cioe solo categoria).', 'danea-promo' ) ),
			'import_none' => array( 'warning', __( 'Nessuna promo importata: le categorie non esistono o sono gia presenti.', 'danea-promo' ) ),
			'exists'      => array( 'warning', __( 'Questa categoria ha gia una promo.', 'danea-promo' ) ),
			'missing'     => array( 'error', __( 'Seleziona una categoria e indica lo sconto.', 'danea-promo' ) ),
			'invalid'     => array( 'error', __( 'Categoria non valida.', 'danea-promo' ) ),
		);
		if ( isset( $messages[ $msg ] ) ) {
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $messages[ $msg ][0] ),
				esc_html( $messages[ $msg ][1] )
			);
		}
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$promos = DPM_Promos::all();
		$terms  = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'exclude'    => array_keys( $promos ),
			)
		);
		if ( is_wp_error( $terms ) ) {
			$terms = array();
		}
		usort(
			$terms,
			function ( $a, $b ) {
				return strcasecmp( DPM_Admin::category_label( $a ), DPM_Admin::category_label( $b ) );
			}
		);
		?>
		<div class="wrap dpm-wrap">
			<h1><?php esc_html_e( 'Promo Danea', 'danea-promo' ); ?></h1>
			<?php self::notice(); ?>

			<p class="description">
				<?php esc_html_e( 'Scegli una categoria promo e imposta lo sconto. Il plugin genera un codice: inseriscilo nel campo di Danea che viene inviato a WooCommerce come "meta_product_link". Quando Danea sincronizza i prodotti, questi vengono inseriti nella categoria corretta e il prezzo scontato viene calcolato automaticamente.', 'danea-promo' ); ?>
			</p>

			<div class="dpm-card">
				<h2><?php esc_html_e( 'Aggiungi una promo', 'danea-promo' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dpm_add">
					<?php wp_nonce_field( 'dpm_action' ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th><label for="dpm-cat"><?php esc_html_e( 'Categoria', 'danea-promo' ); ?></label></th>
							<td>
								<select id="dpm-cat" name="cat_id" required>
									<option value=""></option>
									<?php foreach ( $terms as $term ) : ?>
										<option value="<?php echo esc_attr( $term->term_id ); ?>" data-code="<?php echo esc_attr( DPM_Promos::normalize( $term->name ) ); ?>">
											<?php echo esc_html( self::category_label( $term ) ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Digita per filtrare le categorie.', 'danea-promo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="dpm-discount"><?php esc_html_e( 'Qual e lo sconto di questa promo? (%)', 'danea-promo' ); ?></label></th>
							<td>
								<input type="number" id="dpm-discount" name="discount" min="0" max="100" step="0.01" placeholder="20" required class="small-text"> %
							</td>
						</tr>
						<tr>
							<th><label for="dpm-code-preview"><?php esc_html_e( 'Codice per Danea', 'danea-promo' ); ?></label></th>
							<td>
								<input type="text" id="dpm-code-preview" class="regular-text" readonly>
								<p class="description"><?php esc_html_e( 'Anteprima. Il codice definitivo compare nella tabella dopo il salvataggio.', 'danea-promo' ); ?></p>
							</td>
						</tr>
						<tr>
							<th><label for="dpm-aliases"><?php esc_html_e( 'Alias (facoltativo)', 'danea-promo' ); ?></label></th>
							<td>
								<input type="text" id="dpm-aliases" name="aliases" class="regular-text" placeholder="Promo Estate, Promo State">
								<p class="description"><?php esc_html_e( 'Altri valori, separati da virgola, che devono essere riconosciuti come questa promo.', 'danea-promo' ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Aggiungi promo', 'danea-promo' ), 'primary', 'submit', false ); ?>
				</form>
			</div>

			<div class="dpm-card">
				<h2><?php esc_html_e( 'Promo configurate', 'danea-promo' ); ?></h2>
				<?php if ( empty( $promos ) ) : ?>
					<p><?php esc_html_e( 'Nessuna promo configurata.', 'danea-promo' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="dpm_import">
						<?php wp_nonce_field( 'dpm_action' ); ?>
						<?php submit_button( __( 'Importa la mappatura del vecchio script', 'danea-promo' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php else : ?>
					<table class="widefat striped dpm-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Categoria', 'danea-promo' ); ?></th>
								<th><?php esc_html_e( 'Sconto', 'danea-promo' ); ?></th>
								<th><?php esc_html_e( 'Codice per Danea', 'danea-promo' ); ?></th>
								<th><?php esc_html_e( 'Alias', 'danea-promo' ); ?></th>
								<th><?php esc_html_e( 'Azioni', 'danea-promo' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $promos as $cat_id => $promo ) : ?>
							<?php
							$term = get_term( (int) $cat_id, 'product_cat' );
							$name = ( $term && ! is_wp_error( $term ) ) ? self::category_label( $term ) : sprintf( /* translators: %d: term id */ __( '(categoria non trovata, ID %d)', 'danea-promo' ), $cat_id );
							?>
							<tr>
								<td>
									<strong><?php echo esc_html( $name ); ?></strong><br>
									<span class="description">
										<?php
										printf(
											/* translators: 1: category name, 2: code */
											esc_html__( 'Categoria: %1$s - codice per Danea: %2$s', 'danea-promo' ),
											esc_html( $term && ! is_wp_error( $term ) ? $term->name : $cat_id ),
											'<code>' . esc_html( $promo['code'] ) . '</code>'
										);
										?>
									</span>
								</td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dpm-inline">
										<input type="hidden" name="action" value="dpm_update">
										<input type="hidden" name="cat_id" value="<?php echo esc_attr( $cat_id ); ?>">
										<?php wp_nonce_field( 'dpm_action' ); ?>
										<input type="number" name="discount" min="0" max="100" step="0.01" value="<?php echo esc_attr( $promo['discount'] ); ?>" class="small-text"> %
										<button type="submit" class="button button-small"><?php esc_html_e( 'Salva', 'danea-promo' ); ?></button>
									</form>
								</td>
								<td>
									<code class="dpm-code"><?php echo esc_html( $promo['code'] ); ?></code>
									<button type="button" class="button button-small dpm-copy" data-code="<?php echo esc_attr( $promo['code'] ); ?>"><?php esc_html_e( 'Copia', 'danea-promo' ); ?></button>
								</td>
								<td><?php echo esc_html( $promo['aliases'] ); ?></td>
								<td>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dpm-inline dpm-delete-form">
										<input type="hidden" name="action" value="dpm_delete">
										<input type="hidden" name="cat_id" value="<?php echo esc_attr( $cat_id ); ?>">
										<?php wp_nonce_field( 'dpm_action' ); ?>
										<button type="submit" class="button button-small button-link-delete"><?php esc_html_e( 'Elimina', 'danea-promo' ); ?></button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="dpm-card">
				<h2><?php esc_html_e( 'Sincronizzazione', 'danea-promo' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Controlla i prodotti con codice promo, quelli gia in una categoria promo e quelli con sconto applicato dal plugin. Assegna o rimuove categoria e prezzo scontato secondo il codice attuale di ogni prodotto. Utile dopo aver cambiato uno sconto o eliminato una promo.', 'danea-promo' ); ?>
				</p>
				<p><button type="button" class="button button-primary" id="dpm-sync-btn"><?php esc_html_e( 'Sincronizza ora', 'danea-promo' ); ?></button></p>
				<div id="dpm-progress" style="display:none;">
					<div class="dpm-bar"><div class="dpm-bar-fill"></div></div>
					<p class="dpm-status"></p>
				</div>
			</div>
		</div>
		<?php
	}
}
