<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WCRR_Plugin {
	private static $instance = null;

	public const POST_TYPE = 'wcrr_request';
	public const META_ORDER_ID = '_wcrr_order_id';
	public const META_REASON = '_wcrr_reason';
	public const META_CODE = '_wcrr_code';
	public const META_STATUS = '_wcrr_status';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'add_account_endpoint' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_filter( 'woocommerce_account_menu_items', [ $this, 'add_account_menu_item' ] );
		add_action( 'woocommerce_account_returns_endpoint', [ $this, 'render_account_endpoint' ] );
		add_action( 'template_redirect', [ $this, 'handle_form_submission' ] );
		add_action( 'add_meta_boxes', [ $this, 'register_admin_metabox' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_admin_metabox' ] );

		// Admin list table columns
		add_filter( 'manage_edit-' . self::POST_TYPE . '_columns', [ $this, 'admin_columns' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'admin_column_content' ], 10, 2 );

		// Admin editor UX: placeholder title text
		add_filter( 'enter_title_here', [ $this, 'filter_enter_title' ], 10, 2 );
	}

	public function register_post_type() {
		$labels = [
			'name' => __( 'Müşteri İade Talepleri', 'wc-return-requests' ),
			'singular_name' => __( 'İade Talebi', 'wc-return-requests' ),
			'menu_name' => __( 'Müşteri İade Talepleri', 'wc-return-requests' ),
			'all_items' => __( 'Tüm İade Talepleri', 'wc-return-requests' ),
			'add_new' => __( 'Yeni İade Ekle', 'wc-return-requests' ),
			'add_new_item' => __( 'Yeni İade Ekle', 'wc-return-requests' ),
			'edit_item' => __( 'İadeyi Düzenle', 'wc-return-requests' ),
			'new_item' => __( 'Yeni İade', 'wc-return-requests' ),
			'view_item' => __( 'İadeyi Görüntüle', 'wc-return-requests' ),
			'search_items' => __( 'İade Ara', 'wc-return-requests' ),
			'not_found' => __( 'İade bulunamadı', 'wc-return-requests' ),
			'not_found_in_trash' => __( 'Çöpte iade bulunamadı', 'wc-return-requests' ),
		];
		register_post_type( self::POST_TYPE, [
			'labels' => $labels,
			'public' => false,
			'show_ui' => true,
			'show_in_menu' => 'woocommerce',
			'supports' => [ 'title' ],
			'capability_type' => 'shop_order',
			'map_meta_cap' => true,
			'menu_position' => 56,
			'menu_icon' => 'dashicons-archive',
		] );
	}

	public function filter_enter_title( $text, $post ) {
		if ( $post && self::POST_TYPE === $post->post_type ) {
			return __( 'Başlık otomatik atanacak', 'wc-return-requests' );
		}
		return $text;
	}

	public function admin_columns( $columns ) {
		$new = [];
		// Keep checkbox
		if ( isset( $columns['cb'] ) ) { $new['cb'] = $columns['cb']; }
		$new['title'] = __( 'İade Talebi', 'wc-return-requests' );
		$new['wcrr_customer'] = __( 'Müşteri', 'wc-return-requests' );
		$new['wcrr_order'] = __( 'Sipariş', 'wc-return-requests' );
		$new['wcrr_status'] = __( 'Durum', 'wc-return-requests' );
		$new['date'] = $columns['date'] ?? __( 'Tarih', 'wc-return-requests' );
		return $new;
	}

	public function admin_column_content( $column, $post_id ) {
		if ( 'wcrr_customer' === $column ) {
			$order_id = (int) get_post_meta( $post_id, self::META_ORDER_ID, true );
			$order = $order_id ? wc_get_order( $order_id ) : null;
			if ( $order ) {
				echo esc_html( trim( $order->get_formatted_billing_full_name() ?: $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) );
			} else {
				echo esc_html( get_the_author_meta( 'display_name', get_post_field( 'post_author', $post_id ) ) );
			}
			return;
		}
		if ( 'wcrr_order' === $column ) {
			$order_id = (int) get_post_meta( $post_id, self::META_ORDER_ID, true );
			if ( $order_id ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$order_number = $order->get_order_number();
					$url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
					echo '<a href="' . esc_url( $url ) . '">#' . esc_html( $order_number ) . '</a>';
					return;
				}
			}
			echo '—';
			return;
		}
		if ( 'wcrr_status' === $column ) {
			$status = (string) get_post_meta( $post_id, self::META_STATUS, true );
			echo esc_html( $this->human_status( $status ) );
			return;
		}
	}

	public function add_account_endpoint() {
		add_rewrite_endpoint( 'returns', EP_ROOT | EP_PAGES );
	}

	public function add_query_vars( $vars ) {
		$vars[] = 'returns';
		return $vars;
	}

	public function add_account_menu_item( $items ) {
		$items = array_slice( $items, 0, -1, true ) + [ 'returns' => __( 'İade Taleplerim', 'wc-return-requests' ) ] + array_slice( $items, -1, null, true );
		return $items;
	}

	public function render_account_endpoint() {
		if ( ! is_user_logged_in() ) {
			wc_print_notice( __( 'Bu sayfayı görmek için giriş yapmalısınız.', 'wc-return-requests' ), 'error' );
			return;
		}

		$current_user_id = get_current_user_id();
		// If editing a request, show edit form first
		$edit_id = isset( $_GET['wcrr_edit'] ) ? (int) $_GET['wcrr_edit'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $edit_id && get_post_type( $edit_id ) === self::POST_TYPE && (int) get_post_field( 'post_author', $edit_id ) === $current_user_id ) {
			$this->render_edit_form( $edit_id );
		}

		$this->render_create_form( $current_user_id );
		$this->render_requests_table( $current_user_id );
	}

	private function render_create_form( int $user_id ) {
		$orders = wc_get_orders([
			'customer_id' => $user_id,
			'limit' => 50,
			'orderby' => 'date',
			'order' => 'DESC',
		]);

		// Build a list of order IDs that already have a return request by this user
		$existing_requests = get_posts([
			'post_type' => self::POST_TYPE,
			'author' => $user_id,
			'posts_per_page' => -1,
			'fields' => 'ids',
		]);
		$requested_order_ids = [];
		foreach ( $existing_requests as $req_id ) {
			$oid = (int) get_post_meta( $req_id, self::META_ORDER_ID, true );
			if ( $oid ) { $requested_order_ids[] = $oid; }
		}
		?>
		<form method="post" class="wcrr-form" style="margin-bottom:24px;">
			<h3><?php echo esc_html__( 'Yeni İade Talebi Oluştur', 'wc-return-requests' ); ?></h3>
			<p>
				<label for="wcrr_order_id"><strong><?php echo esc_html__( 'Sipariş', 'wc-return-requests' ); ?></strong></label><br/>
				<select name="wcrr_order_id" id="wcrr_order_id" required>
					<option value=""><?php echo esc_html__( 'Sipariş seçin', 'wc-return-requests' ); ?></option>
					<?php foreach ( $orders as $order ) : ?>
						<option value="<?php echo esc_attr( $order->get_id() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?> — <?php echo esc_html( wc_format_datetime( $order->get_date_created() ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="wcrr_reason"><strong><?php echo esc_html__( 'İade Nedeni', 'wc-return-requests' ); ?></strong></label><br/>
				<textarea name="wcrr_reason" id="wcrr_reason" rows="4" required style="width:100%;max-width:600px;"></textarea>
			</p>
			<?php wp_nonce_field( 'wcrr_create', 'wcrr_nonce' ); ?>
			<input type="hidden" id="wcrr_existing_orders" value='<?php echo esc_attr( wp_json_encode( array_values( array_unique( $requested_order_ids ) ) ) ); ?>' />
			<input type="hidden" name="wcrr_action" value="create_return" />
			<button type="submit" class="button button-primary"><?php echo esc_html__( 'İade Talebi Oluştur', 'wc-return-requests' ); ?></button>
		</form>
		<script>
		(function(){
			var form = document.querySelector('.wcrr-form');
			if(!form) return;
			var select = document.getElementById('wcrr_order_id');
			var existingInput = document.getElementById('wcrr_existing_orders');
			var existing = [];
			try { existing = JSON.parse(existingInput.value || '[]'); } catch(e) { existing = []; }
			function hasExisting(orderId){
				orderId = parseInt(orderId, 10);
				return existing.indexOf(orderId) !== -1;
			}
			form.addEventListener('submit', function(e){
				var val = select && select.value ? parseInt(select.value, 10) : 0;
				if(val && hasExisting(val)){
					e.preventDefault();
					window.alert('<?php echo esc_js( __( 'Bu sipariş için zaten bir iade talebiniz var.', 'wc-return-requests' ) ); ?>');
				}
			});
			if(select){
				select.addEventListener('change', function(){
					var val = this.value ? parseInt(this.value, 10) : 0;
					if(val && hasExisting(val)){
						window.alert('<?php echo esc_js( __( 'Bu sipariş için zaten bir iade talebiniz var.', 'wc-return-requests' ) ); ?>');
					}
				});
			}
		})();
		</script>
		<?php
	}

	private function render_requests_table( int $user_id ) {
		$args = [
			'post_type' => self::POST_TYPE,
			'posts_per_page' => 50,
			'author' => $user_id,
			'post_status' => 'publish',
			'orderby' => 'date',
			'order' => 'DESC',
		];
		$requests = get_posts( $args );
		?>
		<h3><?php echo esc_html__( 'İade Taleplerim', 'wc-return-requests' ); ?></h3>
		<table class="shop_table shop_table_responsive my_account_orders">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'İade Kodu', 'wc-return-requests' ); ?></th>
					<th><?php echo esc_html__( 'Sipariş', 'wc-return-requests' ); ?></th>
					<th><?php echo esc_html__( 'Durum', 'wc-return-requests' ); ?></th>
					<th><?php echo esc_html__( 'Tarih', 'wc-return-requests' ); ?></th>
					<th><?php echo esc_html__( 'İşlemler', 'wc-return-requests' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $requests ) ) : ?>
				<tr><td colspan="5"><?php echo esc_html__( 'Henüz iade talebiniz yok.', 'wc-return-requests' ); ?></td></tr>
			<?php else : foreach ( $requests as $req ) :
				$code = get_post_meta( $req->ID, self::META_CODE, true );
				$order_id = (int) get_post_meta( $req->ID, self::META_ORDER_ID, true );
				$status = get_post_meta( $req->ID, self::META_STATUS, true );
		$order_obj = $order_id ? wc_get_order( $order_id ) : null;
		$order_number = $order_obj ? $order_obj->get_order_number() : '-';
				$label = $this->human_status( $status );
			?>
				<tr>
					<td><?php echo esc_html( $code ); ?></td>
					<td>#<?php echo esc_html( $order_number ); ?></td>
					<td><?php echo esc_html( $label ); ?></td>
					<td><?php echo esc_html( get_the_date( '', $req ) ); ?></td>
					<td>
						<?php if ( 'pending' === $status ) : ?>
							<a class="button" href="<?php echo esc_url( add_query_arg( [ 'wcrr_edit' => $req->ID ], wc_get_account_endpoint_url( 'returns' ) ) ); ?>"><?php echo esc_html__( 'Düzenle', 'wc-return-requests' ); ?></a>
						<?php else : ?>
							<span>—</span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_edit_form( int $post_id ) {
		$reason = (string) get_post_meta( $post_id, self::META_REASON, true );
		$status = (string) get_post_meta( $post_id, self::META_STATUS, true );
		if ( 'pending' !== $status ) {
			return;
		}
		?>
		<form method="post" class="wcrr-form" style="margin-bottom:24px;">
			<h3><?php echo esc_html__( 'İade Talebini Düzenle', 'wc-return-requests' ); ?></h3>
			<p>
				<label for="wcrr_reason_edit"><strong><?php echo esc_html__( 'İade Nedeni', 'wc-return-requests' ); ?></strong></label><br/>
				<textarea name="wcrr_reason" id="wcrr_reason_edit" rows="4" required style="width:100%;max-width:600px;"><?php echo esc_textarea( $reason ); ?></textarea>
			</p>
			<?php wp_nonce_field( 'wcrr_update', 'wcrr_nonce' ); ?>
			<input type="hidden" name="wcrr_action" value="update_return" />
			<input type="hidden" name="wcrr_post_id" value="<?php echo esc_attr( $post_id ); ?>" />
			<button type="submit" class="button button-primary"><?php echo esc_html__( 'Kaydet', 'wc-return-requests' ); ?></button>
			<a class="button" href="<?php echo esc_url( wc_get_account_endpoint_url( 'returns' ) ); ?>"><?php echo esc_html__( 'İptal', 'wc-return-requests' ); ?></a>
		</form>
		<?php
	}

	private function human_status( string $status ) : string {
		switch ( $status ) {
			case 'accepted':
				return __( 'Kabul Edildi', 'wc-return-requests' );
			case 'rejected':
				return __( 'Kabul Edilmedi', 'wc-return-requests' );
			case 'pending':
			default:
				return __( 'İnceleniyor', 'wc-return-requests' );
		}
	}

	public function handle_form_submission() {
		if ( ! is_user_logged_in() ) { return; }
		if ( empty( $_POST['wcrr_action'] ) ) { return; }
		$action = sanitize_text_field( wp_unslash( $_POST['wcrr_action'] ) );
		if ( ! isset( $_POST['wcrr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcrr_nonce'] ) ), 'wcrr_create' ) ) {
			if ( 'create_return' === $action ) {
				wc_add_notice( __( 'Güvenlik doğrulaması başarısız.', 'wc-return-requests' ), 'error' );
				wp_safe_redirect( wc_get_account_endpoint_url( 'returns' ) );
				exit;
			}
		}

		$user_id = get_current_user_id();
		if ( 'create_return' === $action ) {
			$order_id = isset( $_POST['wcrr_order_id'] ) ? (int) $_POST['wcrr_order_id'] : 0;
			$reason = isset( $_POST['wcrr_reason'] ) ? wp_kses_post( wp_unslash( $_POST['wcrr_reason'] ) ) : '';

		if ( ! $order_id || '' === $reason ) {
			wc_add_notice( __( 'Lütfen tüm alanları doldurun.', 'wc-return-requests' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'returns' ) );
			exit;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || (int) $order->get_user_id() !== $user_id ) {
			wc_add_notice( __( 'Bu sipariş size ait değil.', 'wc-return-requests' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'returns' ) );
			exit;
		}

		// Enforce one return request per order per user
		$existing = get_posts( [
			'post_type' => self::POST_TYPE,
			'author' => $user_id,
			'posts_per_page' => 1,
			'fields' => 'ids',
			'meta_query' => [
				[
					'key' => self::META_ORDER_ID,
					'value' => $order_id,
					'compare' => '=',
				],
			],
		] );
		if ( ! empty( $existing ) ) {
			wc_add_notice( __( 'Bu sipariş için zaten bir iade talebiniz var.', 'wc-return-requests' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'returns' ) );
			exit;
		}

			$code = $this->generate_code();
			$post_id = wp_insert_post( [
			'post_type' => self::POST_TYPE,
			'post_title' => sprintf( __( 'İade Talebi #%s', 'wc-return-requests' ), $code ),
			'post_status' => 'publish',
			'post_author' => $user_id,
		] );

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			wc_add_notice( __( 'İade talebi oluşturulamadı.', 'wc-return-requests' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'returns' ) );
			exit;
		}

			update_post_meta( $post_id, self::META_ORDER_ID, $order_id );
			update_post_meta( $post_id, self::META_REASON, $reason );
			update_post_meta( $post_id, self::META_CODE, $code );
			update_post_meta( $post_id, self::META_STATUS, 'pending' );

			$this->notify_admin( $post_id, $order_id, $code, $reason );
			wc_add_notice( __( 'İade talebiniz oluşturuldu.', 'wc-return-requests' ) );
			wp_safe_redirect( wc_get_account_endpoint_url( 'returns' ) );
			exit;
		}

		// Update existing request by owner when pending
		if ( 'update_return' === $action ) {
			if ( ! isset( $_POST['wcrr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcrr_nonce'] ) ), 'wcrr_update' ) ) {
				wc_add_notice( __( 'Güvenlik doğrulaması başarısız.', 'wc-return-requests' ), 'error' );
				wp_safe_redirect( wc_get_account_endpoint_url( 'returns' ) );
				exit;
			}
			$post_id = isset( $_POST['wcrr_post_id'] ) ? (int) $_POST['wcrr_post_id'] : 0;
			$reason = isset( $_POST['wcrr_reason'] ) ? wp_kses_post( wp_unslash( $_POST['wcrr_reason'] ) ) : '';
			if ( ! $post_id || '' === $reason ) {
				wc_add_notice( __( 'Lütfen tüm alanları doldurun.', 'wc-return-requests' ), 'error' );
				wp_safe_redirect( wc_get_account_endpoint_url( 'returns' ) );
				exit;
			}
			if ( get_post_type( $post_id ) !== self::POST_TYPE || (int) get_post_field( 'post_author', $post_id ) !== $user_id ) {
				wc_add_notice( __( 'Bu talebi düzenleme yetkiniz yok.', 'wc-return-requests' ), 'error' );
				wp_safe_redirect( wc_get_account_endpoint_url( 'returns' ) );
				exit;
			}
			$status = (string) get_post_meta( $post_id, self::META_STATUS, true );
			if ( 'pending' !== $status ) {
				wc_add_notice( __( 'Bu talep artık düzenlenemez.', 'wc-return-requests' ), 'error' );
				wp_safe_redirect( wc_get_account_endpoint_url( 'returns' ) );
				exit;
			}
			update_post_meta( $post_id, self::META_REASON, $reason );
			wc_add_notice( __( 'İade talebiniz güncellendi.', 'wc-return-requests' ) );
			wp_safe_redirect( wc_get_account_endpoint_url( 'returns' ) );
			exit;
		}
	}

	private function generate_code() : string {
		// 12-digit numeric code
		$digits = '';
		for ( $i = 0; $i < 12; $i++ ) {
			$digits .= wp_rand( 0, 9 );
		}
		return $digits;
	}

	private function notify_admin( int $post_id, int $order_id, string $code, string $reason ) : void {
		$to = get_option( 'admin_email' );
		$subject = sprintf( __( 'Yeni İade Talebi: %s', 'wc-return-requests' ), $code );
		$order = wc_get_order( $order_id );
		$order_link = $order ? admin_url( 'post.php?post=' . $order_id . '&action=edit' ) : '';
		$items_html = $order ? $this->format_order_items_html( $order ) : '';
		$totals_html = $order ? wc_price( $order->get_total() ) : '';
		$body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222;">'
			. '<h2 style="margin:0 0 12px;">' . esc_html__( 'Yeni bir iade talebi oluşturuldu.', 'wc-return-requests' ) . '</h2>'
			. '<p><strong>' . esc_html__( 'İade Kodu', 'wc-return-requests' ) . ':</strong> ' . esc_html( $code ) . '</p>'
			. ( $order ? ( '<p><strong>' . esc_html__( 'Sipariş', 'wc-return-requests' ) . ':</strong> #' . esc_html( $order->get_order_number() ) . '</p>' ) : '' )
			. '<p><strong>' . esc_html__( 'Neden', 'wc-return-requests' ) . ':</strong><br>' . nl2br( esc_html( wp_strip_all_tags( $reason ) ) ) . '</p>'
			. '<h3 style="margin:16px 0 8px;">' . esc_html__( 'Sipariş Ürünleri', 'wc-return-requests' ) . '</h3>'
			. $items_html
			. ( $totals_html ? ( '<p><strong>' . esc_html__( 'Sipariş Tutarı', 'wc-return-requests' ) . ':</strong> ' . $totals_html . '</p>' ) : '' )
			. ( $order_link ? ( '<p><a href="' . esc_url( $order_link ) . '">' . esc_html__( 'Siparişi görüntüle', 'wc-return-requests' ) . '</a></p>' ) : '' )
			. '</div>';
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		wp_mail( $to, $subject, $body, $headers );
	}

	private function notify_customer_status_change( int $post_id, int $order_id, string $code, string $status ) : void {
		$order = wc_get_order( $order_id );
		$user_email = $order ? $order->get_billing_email() : get_the_author_meta( 'user_email', (int) get_post_field( 'post_author', $post_id ) );
		if ( ! $user_email ) { return; }
		$status_label = $this->human_status( $status );
		$subject = sprintf( __( 'İade Talebiniz Güncellendi: %s', 'wc-return-requests' ), $code );
		$items_html = $order ? $this->format_order_items_html( $order ) : '';
		$body = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#222;">'
			. '<h2 style="margin:0 0 12px;">' . esc_html__( 'İade talebinizin durumu güncellendi.', 'wc-return-requests' ) . '</h2>'
			. '<p><strong>' . esc_html__( 'İade Kodu', 'wc-return-requests' ) . ':</strong> ' . esc_html( $code ) . '</p>'
			. '<p><strong>' . esc_html__( 'Yeni Durum', 'wc-return-requests' ) . ':</strong> ' . esc_html( $status_label ) . '</p>'
			. '<p><strong>' . esc_html__( 'Sipariş', 'wc-return-requests' ) . ':</strong> #' . esc_html( $order ? $order->get_order_number() : '-' ) . '</p>'
			. ( $items_html ? ( '<h3 style="margin:16px 0 8px;">' . esc_html__( 'Sipariş Ürünleri', 'wc-return-requests' ) . '</h3>' . $items_html ) : '' )
			. '</div>';
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		wp_mail( $user_email, $subject, $body, $headers );
	}

	private function format_order_items_html( WC_Order $order ) : string {
		$rows = '';
		foreach ( $order->get_items() as $item ) {
			$quantity = $item->get_quantity();
			$name = $item->get_name();
			$subtotal = wc_price( $item->get_subtotal() );
			$rows .= '<li>' . esc_html( $name ) . ' × ' . esc_html( (string) $quantity ) . ' — ' . $subtotal . '</li>';
		}
		return '<ul style="margin:0 0 12px 18px;padding:0;">' . $rows . '</ul>';
	}

	public function register_admin_metabox() {
		add_meta_box( 'wcrr_details', __( 'İade Talebi Detayları', 'wc-return-requests' ), [ $this, 'metabox_html' ], self::POST_TYPE, 'normal', 'high' );
	}

	public function metabox_html( $post ) {
		$order_id = (int) get_post_meta( $post->ID, self::META_ORDER_ID, true );
		$reason = (string) get_post_meta( $post->ID, self::META_REASON, true );
		$code = (string) get_post_meta( $post->ID, self::META_CODE, true );
		$status = (string) get_post_meta( $post->ID, self::META_STATUS, true );
		wp_nonce_field( 'wcrr_admin_save', 'wcrr_admin_nonce' );
		?>
		<p><strong><?php echo esc_html__( 'İade Kodu:', 'wc-return-requests' ); ?></strong> <?php echo esc_html( $code ? $code : '—' ); ?></p>
		<p>
			<label for="wcrr_order_number"><strong><?php echo esc_html__( 'Sipariş Numarası', 'wc-return-requests' ); ?></strong></label><br/>
			<input type="text" name="wcrr_order_number" id="wcrr_order_number" value="<?php echo esc_attr( $order_id ? wc_get_order( $order_id )->get_order_number() : '' ); ?>" placeholder="#1234" style="width:200px;" />
		</p>
		<p>
			<label for="wcrr_status"><strong><?php echo esc_html__( 'Durum', 'wc-return-requests' ); ?></strong></label><br/>
			<select name="wcrr_status" id="wcrr_status">
				<option value="pending" <?php selected( $status, 'pending' ); ?>><?php echo esc_html__( 'İnceleniyor', 'wc-return-requests' ); ?></option>
				<option value="accepted" <?php selected( $status, 'accepted' ); ?>><?php echo esc_html__( 'Kabul Edildi', 'wc-return-requests' ); ?></option>
				<option value="rejected" <?php selected( $status, 'rejected' ); ?>><?php echo esc_html__( 'Kabul Edilmedi', 'wc-return-requests' ); ?></option>
			</select>
		</p>
		<p>
			<label for="wcrr_reason"><strong><?php echo esc_html__( 'İade Nedeni', 'wc-return-requests' ); ?></strong></label><br/>
			<textarea name="wcrr_reason" id="wcrr_reason" rows="5" style="width:100%;max-width:600px;"><?php echo esc_textarea( $reason ); ?></textarea>
		</p>
		<?php
	}

	public function save_admin_metabox( $post_id ) {
		if ( ! isset( $_POST['wcrr_admin_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcrr_admin_nonce'] ) ), 'wcrr_admin_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) { return; }
		if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }

		$previous_status = (string) get_post_meta( $post_id, self::META_STATUS, true );
		$had_status = $previous_status !== '';

		// Accept order number and map to order/customer
		if ( isset( $_POST['wcrr_order_number'] ) ) {
			$order_number_raw = sanitize_text_field( wp_unslash( $_POST['wcrr_order_number'] ) );
			$order_id_new = (int) preg_replace( '/\D+/', '', $order_number_raw );
			if ( $order_id_new > 0 ) {
				$order_obj = wc_get_order( $order_id_new );
				if ( $order_obj ) {
					update_post_meta( $post_id, self::META_ORDER_ID, $order_id_new );
					$author_id = (int) $order_obj->get_user_id();
					if ( $author_id > 0 ) {
						// Prevent recursion by temporarily removing this save handler
						remove_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_admin_metabox' ] );
						wp_update_post( [ 'ID' => $post_id, 'post_author' => $author_id ] );
						add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_admin_metabox' ] );
					}
				}
			}
		}
		if ( isset( $_POST['wcrr_status'] ) ) {
			$valid = [ 'pending', 'accepted', 'rejected' ];
			$status = sanitize_text_field( wp_unslash( $_POST['wcrr_status'] ) );
			if ( in_array( $status, $valid, true ) ) {
				update_post_meta( $post_id, self::META_STATUS, $status );
				if ( $had_status && $status !== $previous_status ) {
					$order_id = (int) get_post_meta( $post_id, self::META_ORDER_ID, true );
					$code = (string) get_post_meta( $post_id, self::META_CODE, true );
					$this->notify_customer_status_change( $post_id, $order_id, $code, $status );
				}
			}
		}

		if ( isset( $_POST['wcrr_reason'] ) ) {
			update_post_meta( $post_id, self::META_REASON, wp_kses_post( wp_unslash( $_POST['wcrr_reason'] ) ) );
		}

		// Ensure code exists and normalize title automatically on save
		$code = (string) get_post_meta( $post_id, self::META_CODE, true );
		if ( '' === $code ) {
			$code = $this->generate_code();
			update_post_meta( $post_id, self::META_CODE, $code );
		}
		// Update title without triggering recursive save
		remove_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_admin_metabox' ] );
		wp_update_post( [ 'ID' => $post_id, 'post_title' => sprintf( __( 'İade Talebi #%s', 'wc-return-requests' ), get_post_meta( $post_id, self::META_CODE, true ) ) ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_admin_metabox' ] );
	}
}



