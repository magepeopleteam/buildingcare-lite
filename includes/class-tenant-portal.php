<?php
/**
 * Front-end tenant portal: a self-contained, app-like page at /tenant/ where a
 * logged-in tenant can view their flat's dues, bills, payment history and
 * profile. Renders independently of the active theme for a clean PWA feel.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tenant portal route and views.
 */
class Tenant_Portal {

	private const QUERY        = 'bc_portal';
	private const FLUSH_OPTION = 'bcl_portal_rewrite_version';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite' ), 10 );
		add_action( 'init', array( $this, 'maybe_flush' ), 11 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render' ) );
	}

	/**
	 * Register the /tenant/ pretty route.
	 */
	public function add_rewrite(): void {
		add_rewrite_rule( '^tenant/?$', 'index.php?' . self::QUERY . '=1', 'top' );
	}

	/**
	 * Self-heal the rewrite rules: flush once per plugin version on the first
	 * request (admin or front-end) so the /tenant/ route works automatically
	 * without the admin ever touching Settings → Permalinks. When permalinks
	 * are "plain", the query-string URL (see url()) is used instead, so the
	 * portal always works regardless of the site's permalink configuration.
	 */
	public function maybe_flush(): void {
		if ( get_option( self::FLUSH_OPTION ) === BCL_VERSION ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::FLUSH_OPTION, BCL_VERSION );
	}

	/**
	 * Register the portal query var.
	 *
	 * @param string[] $vars Query vars.
	 * @return string[]
	 */
	public function query_vars( array $vars ): array {
		$vars[] = self::QUERY;
		return $vars;
	}

	/**
	 * Portal URL (pretty when permalinks are on, query string otherwise).
	 */
	public static function url(): string {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/tenant/' );
		}

		return home_url( '/?' . self::QUERY . '=1' );
	}

	/**
	 * Render the portal when requested.
	 */
	public function maybe_render(): void {
		$is_portal = (int) get_query_var( self::QUERY );
		if ( ! $is_portal && isset( $_GET[ self::QUERY ] ) ) {
			$is_portal = 1;
		}

		if ( ! $is_portal ) {
			return;
		}

		nocache_headers();

		// Logout.
		if ( isset( $_GET['action'] ) && 'logout' === sanitize_key( wp_unslash( $_GET['action'] ) ) ) {
			check_admin_referer( 'bcl_portal_logout' );
			wp_logout();
			wp_safe_redirect( self::url() );
			exit;
		}

		// Login submission.
		$error = '';
		if ( isset( $_POST['bcl_portal_login'] ) ) {
			$error = $this->handle_login();
		}

		if ( ! is_user_logged_in() ) {
			$this->render_login( $error );
			exit;
		}

		$user        = wp_get_current_user();
		$resident_id = Tenant_Accounts::resident_for_user( (int) $user->ID );

		if ( $resident_id <= 0 ) {
			$this->render_no_link( $user );
			exit;
		}

		// Profile update.
		if ( isset( $_POST['bcl_portal_profile'] ) ) {
			check_admin_referer( 'bcl_portal_profile' );
			update_post_meta( $resident_id, 'bc_mobile', sanitize_text_field( wp_unslash( $_POST['bc_mobile'] ?? '' ) ) );
			update_post_meta( $resident_id, 'bc_emergency_contact', sanitize_text_field( wp_unslash( $_POST['bc_emergency_contact'] ?? '' ) ) );
			wp_safe_redirect( add_query_arg( 'saved', 'profile', self::url() ) );
			exit;
		}

		// Single receipt view.
		if ( isset( $_GET['receipt'] ) ) {
			$bill_id = absint( $_GET['receipt'] );
			if ( $this->bill_belongs_to_resident( $bill_id, $resident_id ) ) {
				$this->render_receipt( $bill_id );
				exit;
			}
		}

		$this->render_dashboard( $user, $resident_id );
		exit;
	}

	/**
	 * Attempt login, returning an error message on failure.
	 */
	private function handle_login(): string {
		check_admin_referer( 'bcl_portal_login' );

		$creds = array(
			'user_login'    => sanitize_text_field( wp_unslash( $_POST['log'] ?? '' ) ),
			'user_password' => (string) ( $_POST['pwd'] ?? '' ),
			'remember'      => ! empty( $_POST['rememberme'] ),
		);

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			return __( 'Invalid username or password.', 'buildingcare-lite' );
		}

		wp_safe_redirect( self::url() );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Data (scoped strictly to the logged-in tenant's resident record).
	 * ------------------------------------------------------------------ */

	/**
	 * Whether a bill belongs to the resident.
	 */
	private function bill_belongs_to_resident( int $bill_id, int $resident_id ): bool {
		return $bill_id > 0
			&& 'bc_bill' === get_post_type( $bill_id )
			&& (int) bcl_get_meta_float( $bill_id, 'bc_resident_id' ) === $resident_id;
	}

	/**
	 * Get the resident's bills, newest first.
	 *
	 * @return \WP_Post[]
	 */
	private function get_bills( int $resident_id ): array {
		return get_posts(
			array(
				'post_type'      => 'bc_bill',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'meta_key'       => 'bc_billing_month',
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => 'bc_resident_id',
						'value' => $resident_id,
					),
				),
			)
		);
	}

	/**
	 * Get the resident's payment history, newest first.
	 *
	 * @return array<int, array<string, string|float>>
	 */
	private function get_payments( int $resident_id ): array {
		$payments = get_posts(
			array(
				'post_type'      => 'bc_payment',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'meta_key'       => 'bc_payment_date',
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => 'bc_resident_id',
						'value' => $resident_id,
					),
				),
			)
		);

		$rows = array();
		foreach ( $payments as $payment ) {
			$rows[] = array(
				'amount' => bcl_get_meta_float( (int) $payment->ID, 'bc_amount' ),
				'method' => bcl_get_meta_string( (int) $payment->ID, 'bc_payment_method' ),
				'date'   => bcl_get_meta_string( (int) $payment->ID, 'bc_payment_date' ),
			);
		}

		return $rows;
	}

	/**
	 * Summary figures for the overview.
	 *
	 * @param \WP_Post[] $bills Resident bills.
	 * @return array<string, mixed>
	 */
	private function summarize( array $bills ): array {
		$outstanding = 0.0;
		$paid_total  = 0.0;
		$latest      = null;

		foreach ( $bills as $bill ) {
			$id           = (int) $bill->ID;
			$outstanding += bcl_get_meta_float( $id, 'bc_remaining_due' );
			$paid_total  += bcl_get_meta_float( $id, 'bc_amount_paid' );
			if ( null === $latest ) {
				$latest = $bill;
			}
		}

		return array(
			'outstanding' => round( $outstanding, 2 ),
			'paid_total'  => round( $paid_total, 2 ),
			'latest'      => $latest,
			'count'       => count( $bills ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Views.
	 * ------------------------------------------------------------------ */

	/**
	 * Shared page head + open body.
	 */
	private function head( string $title ): void {
		$manifest = home_url( '/?bcl_pwa=manifest&app=tenant' );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?php echo esc_html( $title ); ?></title>
	<meta name="theme-color" content="#2271b1">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-title" content="BuildingCare">
	<link rel="manifest" href="<?php echo esc_url( $manifest ); ?>">
	<link rel="apple-touch-icon" href="<?php echo esc_url( BCL_PLUGIN_URL . 'assets/icons/icon-192.png' ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( BCL_PLUGIN_URL . 'assets/css/portal.css?v=' . BCL_VERSION ); ?>">
</head>
<body class="bcl-portal">
		<?php
	}

	/**
	 * Shared page footer (SW registration) + close body.
	 */
	private function footer(): void {
		$sw    = wp_json_encode( PWA::sw_url() );
		$scope = wp_json_encode( PWA::scope() );
		?>
	<script>
		if ('serviceWorker' in navigator) {
			window.addEventListener('load', function () {
				navigator.serviceWorker.register(<?php echo $sw; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>, { scope: <?php echo $scope; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> }).catch(function () {});
			});
		}
		function bclActivateTab(target) {
			document.querySelectorAll('.bcl-p-tab').forEach(function (t) {
				t.classList.toggle('is-active', t.getAttribute('data-target') === target);
			});
			document.querySelectorAll('.bcl-p-section').forEach(function (s) {
				s.classList.toggle('is-active', s.id === target);
			});
		}
		document.addEventListener('click', function (e) {
			var tab = e.target.closest && e.target.closest('.bcl-p-tab');
			if (!tab) { return; }
			e.preventDefault();
			bclActivateTab(tab.getAttribute('data-target'));
			window.scrollTo({ top: 0, behavior: 'smooth' });
		});
		if (window.location.search.indexOf('saved=profile') !== -1) {
			bclActivateTab('bcl-p-profile');
		}
	</script>
</body>
</html>
		<?php
	}

	/**
	 * Brand header.
	 */
	private function brand(): void {
		?>
		<header class="bcl-p-header">
			<img class="bcl-p-logo" src="<?php echo esc_url( BCL_PLUGIN_URL . 'assets/icons/icon-192.png' ); ?>" alt="">
			<span class="bcl-p-brand"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></span>
		</header>
		<?php
	}

	/**
	 * Login screen.
	 */
	private function render_login( string $error ): void {
		$this->head( __( 'Tenant Login', 'buildingcare-lite' ) );
		$this->brand();
		?>
		<main class="bcl-p-main bcl-p-auth">
			<div class="bcl-p-card bcl-p-login">
				<h1><?php esc_html_e( 'Tenant Login', 'buildingcare-lite' ); ?></h1>
				<p class="bcl-p-muted"><?php esc_html_e( 'Sign in to view your bills, dues and payment history.', 'buildingcare-lite' ); ?></p>

				<?php if ( $error ) : ?>
					<div class="bcl-p-error"><?php echo esc_html( $error ); ?></div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( self::url() ); ?>">
					<?php wp_nonce_field( 'bcl_portal_login' ); ?>
					<label><?php esc_html_e( 'Username or Email', 'buildingcare-lite' ); ?>
						<input type="text" name="log" autocomplete="username" required>
					</label>
					<label><?php esc_html_e( 'Password', 'buildingcare-lite' ); ?>
						<input type="password" name="pwd" autocomplete="current-password" required>
					</label>
					<label class="bcl-p-check">
						<input type="checkbox" name="rememberme" value="1"> <?php esc_html_e( 'Remember me', 'buildingcare-lite' ); ?>
					</label>
					<button type="submit" name="bcl_portal_login" value="1" class="bcl-p-btn"><?php esc_html_e( 'Log In', 'buildingcare-lite' ); ?></button>
				</form>
				<a class="bcl-p-link" href="<?php echo esc_url( wp_lostpassword_url( self::url() ) ); ?>"><?php esc_html_e( 'Forgot password?', 'buildingcare-lite' ); ?></a>
			</div>
		</main>
		<?php
		$this->footer();
	}

	/**
	 * Logged-in but no resident record linked.
	 */
	private function render_no_link( \WP_User $user ): void {
		$this->head( __( 'Tenant Portal', 'buildingcare-lite' ) );
		$this->brand();
		?>
		<main class="bcl-p-main bcl-p-auth">
			<div class="bcl-p-card">
				<h1><?php esc_html_e( 'No records found', 'buildingcare-lite' ); ?></h1>
				<p class="bcl-p-muted"><?php esc_html_e( 'Your account is not linked to a flat yet. Please contact the building management.', 'buildingcare-lite' ); ?></p>
				<?php if ( user_can( $user, 'bc_view_reports' ) ) : ?>
					<a class="bcl-p-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=bcl-dashboard' ) ); ?>"><?php esc_html_e( 'Go to Admin Dashboard', 'buildingcare-lite' ); ?></a>
				<?php endif; ?>
				<a class="bcl-p-link" href="<?php echo esc_url( $this->logout_url() ); ?>"><?php esc_html_e( 'Log out', 'buildingcare-lite' ); ?></a>
			</div>
		</main>
		<?php
		$this->footer();
	}

	/**
	 * Logout URL with nonce.
	 */
	private function logout_url(): string {
		return wp_nonce_url( add_query_arg( 'action', 'logout', self::url() ), 'bcl_portal_logout' );
	}

	/**
	 * The tenant dashboard.
	 */
	private function render_dashboard( \WP_User $user, int $resident_id ): void {
		$flat_id   = (int) bcl_get_meta_float( $resident_id, 'bc_assigned_flat_id' );
		$flat_no   = $flat_id ? bcl_get_flat_number( $flat_id ) : '';
		$building  = $flat_id ? (int) bcl_get_meta_float( $flat_id, 'bc_building_id' ) : 0;
		$bills     = $this->get_bills( $resident_id );
		$payments  = $this->get_payments( $resident_id );
		$summary   = $this->summarize( $bills );
		$statuses  = bcl_payment_statuses();
		$methods   = bcl_payment_methods();

		$this->head( __( 'Tenant Portal', 'buildingcare-lite' ) );
		?>
		<header class="bcl-p-header">
			<div class="bcl-p-header-left">
				<img class="bcl-p-logo" src="<?php echo esc_url( BCL_PLUGIN_URL . 'assets/icons/icon-192.png' ); ?>" alt="">
				<div>
					<div class="bcl-p-brand"><?php echo esc_html( get_the_title( $resident_id ) ); ?></div>
					<div class="bcl-p-sub">
						<?php
						if ( $flat_no ) {
							echo esc_html( sprintf( /* translators: %s flat */ __( 'Flat %s', 'buildingcare-lite' ), $flat_no ) );
						}
						if ( $building ) {
							echo ' · ' . esc_html( get_the_title( $building ) );
						}
						?>
					</div>
				</div>
			</div>
			<a class="bcl-p-logout" href="<?php echo esc_url( $this->logout_url() ); ?>" title="<?php esc_attr_e( 'Log out', 'buildingcare-lite' ); ?>">
				<?php esc_html_e( 'Log out', 'buildingcare-lite' ); ?>
			</a>
		</header>

		<main class="bcl-p-main">
			<nav class="bcl-p-tabs">
				<button class="bcl-p-tab is-active" data-target="bcl-p-overview"><?php esc_html_e( 'Overview', 'buildingcare-lite' ); ?></button>
				<button class="bcl-p-tab" data-target="bcl-p-bills"><?php esc_html_e( 'Bills', 'buildingcare-lite' ); ?></button>
				<button class="bcl-p-tab" data-target="bcl-p-payments"><?php esc_html_e( 'Payments', 'buildingcare-lite' ); ?></button>
				<button class="bcl-p-tab" data-target="bcl-p-profile"><?php esc_html_e( 'Profile', 'buildingcare-lite' ); ?></button>
			</nav>

			<section id="bcl-p-overview" class="bcl-p-section is-active">
				<div class="bcl-p-cards">
					<div class="bcl-p-stat bcl-p-stat--due">
						<span><?php esc_html_e( 'Outstanding Dues', 'buildingcare-lite' ); ?></span>
						<strong><?php echo esc_html( bcl_format_amount( (float) $summary['outstanding'] ) ); ?></strong>
					</div>
					<div class="bcl-p-stat">
						<span><?php esc_html_e( 'Total Paid', 'buildingcare-lite' ); ?></span>
						<strong><?php echo esc_html( bcl_format_amount( (float) $summary['paid_total'] ) ); ?></strong>
					</div>
					<div class="bcl-p-stat">
						<span><?php esc_html_e( 'Total Bills', 'buildingcare-lite' ); ?></span>
						<strong><?php echo esc_html( (string) $summary['count'] ); ?></strong>
					</div>
				</div>

				<?php if ( $summary['latest'] instanceof \WP_Post ) : ?>
					<?php $lid = (int) $summary['latest']->ID; ?>
					<div class="bcl-p-card">
						<h2><?php esc_html_e( 'Latest Bill', 'buildingcare-lite' ); ?></h2>
						<div class="bcl-p-kv"><span><?php esc_html_e( 'Month', 'buildingcare-lite' ); ?></span><b><?php echo esc_html( bcl_format_billing_month( bcl_get_meta_string( $lid, 'bc_billing_month' ) ) ); ?></b></div>
						<div class="bcl-p-kv"><span><?php esc_html_e( 'Total', 'buildingcare-lite' ); ?></span><b><?php echo esc_html( bcl_format_amount( bcl_get_meta_float( $lid, 'bc_total_payable_amount' ) ) ); ?></b></div>
						<div class="bcl-p-kv"><span><?php esc_html_e( 'Due', 'buildingcare-lite' ); ?></span><b><?php echo esc_html( bcl_format_amount( bcl_get_meta_float( $lid, 'bc_remaining_due' ) ) ); ?></b></div>
						<div class="bcl-p-kv"><span><?php esc_html_e( 'Status', 'buildingcare-lite' ); ?></span><b><?php echo esc_html( $statuses[ bcl_get_meta_string( $lid, 'bc_payment_status' ) ] ?? '—' ); ?></b></div>
						<a class="bcl-p-link" href="<?php echo esc_url( add_query_arg( 'receipt', $lid, self::url() ) ); ?>"><?php esc_html_e( 'View receipt', 'buildingcare-lite' ); ?></a>
					</div>
				<?php endif; ?>
			</section>

			<section id="bcl-p-bills" class="bcl-p-section">
				<?php if ( empty( $bills ) ) : ?>
					<p class="bcl-p-empty"><?php esc_html_e( 'No bills yet.', 'buildingcare-lite' ); ?></p>
				<?php else : ?>
					<?php foreach ( $bills as $bill ) : $bid = (int) $bill->ID; $st = bcl_get_meta_string( $bid, 'bc_payment_status' ); ?>
						<div class="bcl-p-row">
							<div class="bcl-p-row-main">
								<b><?php echo esc_html( bcl_format_billing_month( bcl_get_meta_string( $bid, 'bc_billing_month' ) ) ); ?></b>
								<span class="bcl-p-badge bcl-p-badge--<?php echo esc_attr( $st ); ?>"><?php echo esc_html( $statuses[ $st ] ?? $st ); ?></span>
							</div>
							<div class="bcl-p-row-meta">
								<span><?php esc_html_e( 'Total', 'buildingcare-lite' ); ?>: <?php echo esc_html( bcl_format_amount( bcl_get_meta_float( $bid, 'bc_total_payable_amount' ) ) ); ?></span>
								<span><?php esc_html_e( 'Due', 'buildingcare-lite' ); ?>: <?php echo esc_html( bcl_format_amount( bcl_get_meta_float( $bid, 'bc_remaining_due' ) ) ); ?></span>
							</div>
							<a class="bcl-p-link" href="<?php echo esc_url( add_query_arg( 'receipt', $bid, self::url() ) ); ?>"><?php esc_html_e( 'Receipt', 'buildingcare-lite' ); ?></a>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</section>

			<section id="bcl-p-payments" class="bcl-p-section">
				<?php if ( empty( $payments ) ) : ?>
					<p class="bcl-p-empty"><?php esc_html_e( 'No payments recorded yet.', 'buildingcare-lite' ); ?></p>
				<?php else : ?>
					<?php foreach ( $payments as $pay ) : ?>
						<div class="bcl-p-row">
							<div class="bcl-p-row-main">
								<b><?php echo esc_html( bcl_format_amount( (float) $pay['amount'] ) ); ?></b>
								<span class="bcl-p-muted"><?php echo esc_html( $methods[ (string) $pay['method'] ] ?? (string) $pay['method'] ); ?></span>
							</div>
							<div class="bcl-p-row-meta"><span><?php echo esc_html( (string) $pay['date'] ); ?></span></div>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</section>

			<section id="bcl-p-profile" class="bcl-p-section">
				<?php if ( isset( $_GET['saved'] ) && 'profile' === sanitize_key( wp_unslash( $_GET['saved'] ) ) ) : ?>
					<div class="bcl-p-saved"><?php esc_html_e( 'Profile updated.', 'buildingcare-lite' ); ?></div>
				<?php endif; ?>

				<div class="bcl-p-card">
					<div class="bcl-p-kv"><span><?php esc_html_e( 'Name', 'buildingcare-lite' ); ?></span><b><?php echo esc_html( get_the_title( $resident_id ) ); ?></b></div>
					<div class="bcl-p-kv"><span><?php esc_html_e( 'Email', 'buildingcare-lite' ); ?></span><b><?php echo esc_html( $user->user_email ); ?></b></div>
					<div class="bcl-p-kv"><span><?php esc_html_e( 'Flat', 'buildingcare-lite' ); ?></span><b><?php echo esc_html( $flat_no ?: '—' ); ?></b></div>
					<div class="bcl-p-kv"><span><?php esc_html_e( 'Move-in', 'buildingcare-lite' ); ?></span><b><?php echo esc_html( bcl_get_meta_string( $resident_id, 'bc_move_in_date' ) ?: '—' ); ?></b></div>
				</div>

				<div class="bcl-p-card">
					<h2><?php esc_html_e( 'Edit Contact Details', 'buildingcare-lite' ); ?></h2>
					<form method="post" action="<?php echo esc_url( self::url() ); ?>" class="bcl-p-form">
						<?php wp_nonce_field( 'bcl_portal_profile' ); ?>
						<label><?php esc_html_e( 'Mobile', 'buildingcare-lite' ); ?>
							<input type="text" name="bc_mobile" value="<?php echo esc_attr( bcl_get_meta_string( $resident_id, 'bc_mobile' ) ); ?>">
						</label>
						<label><?php esc_html_e( 'Emergency Contact', 'buildingcare-lite' ); ?>
							<input type="text" name="bc_emergency_contact" value="<?php echo esc_attr( bcl_get_meta_string( $resident_id, 'bc_emergency_contact' ) ); ?>">
						</label>
						<button type="submit" name="bcl_portal_profile" value="1" class="bcl-p-btn"><?php esc_html_e( 'Save Changes', 'buildingcare-lite' ); ?></button>
					</form>
				</div>

				<a class="bcl-p-btn bcl-p-btn--ghost" href="<?php echo esc_url( $this->logout_url() ); ?>"><?php esc_html_e( 'Log out', 'buildingcare-lite' ); ?></a>
			</section>
		</main>
		<?php
		$this->footer();
	}

	/**
	 * Printable receipt for a single bill.
	 */
	private function render_receipt( int $bill_id ): void {
		$statuses = bcl_payment_statuses();
		$flat_id  = (int) bcl_get_meta_float( $bill_id, 'bc_flat_id' );
		$payments = Payments::for_bill( $bill_id );

		$this->head( __( 'Receipt', 'buildingcare-lite' ) );
		?>
		<main class="bcl-p-main">
			<div class="bcl-p-card bcl-p-receipt">
				<div class="bcl-p-receipt-head">
					<img class="bcl-p-logo" src="<?php echo esc_url( BCL_PLUGIN_URL . 'assets/icons/icon-192.png' ); ?>" alt="">
					<div>
						<div class="bcl-p-brand"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></div>
						<div class="bcl-p-sub"><?php echo esc_html( bcl_format_billing_month( bcl_get_meta_string( $bill_id, 'bc_billing_month' ) ) ); ?></div>
					</div>
				</div>

				<div class="bcl-p-kv"><span><?php esc_html_e( 'Flat', 'buildingcare-lite' ); ?></span><b><?php echo esc_html( bcl_get_flat_number( $flat_id ) ?: '—' ); ?></b></div>
				<div class="bcl-p-kv"><span><?php esc_html_e( 'Service Charge', 'buildingcare-lite' ); ?></span><b><?php echo esc_html( bcl_format_amount( bcl_get_meta_float( $bill_id, 'bc_service_charge_amount' ) ) ); ?></b></div>
				<div class="bcl-p-kv"><span><?php esc_html_e( 'Previous Due', 'buildingcare-lite' ); ?></span><b><?php echo esc_html( bcl_format_amount( bcl_get_meta_float( $bill_id, 'bc_previous_due_amount' ) ) ); ?></b></div>
				<div class="bcl-p-kv"><span><?php esc_html_e( 'Late Fee', 'buildingcare-lite' ); ?></span><b><?php echo esc_html( bcl_format_amount( bcl_get_meta_float( $bill_id, 'bc_late_fee_amount' ) ) ); ?></b></div>
				<div class="bcl-p-kv bcl-p-kv--total"><span><?php esc_html_e( 'Total Payable', 'buildingcare-lite' ); ?></span><b><?php echo esc_html( bcl_format_amount( bcl_get_meta_float( $bill_id, 'bc_total_payable_amount' ) ) ); ?></b></div>
				<div class="bcl-p-kv"><span><?php esc_html_e( 'Paid', 'buildingcare-lite' ); ?></span><b><?php echo esc_html( bcl_format_amount( bcl_get_meta_float( $bill_id, 'bc_amount_paid' ) ) ); ?></b></div>
				<div class="bcl-p-kv"><span><?php esc_html_e( 'Remaining', 'buildingcare-lite' ); ?></span><b><?php echo esc_html( bcl_format_amount( bcl_get_meta_float( $bill_id, 'bc_remaining_due' ) ) ); ?></b></div>
				<div class="bcl-p-kv"><span><?php esc_html_e( 'Status', 'buildingcare-lite' ); ?></span><b><?php echo esc_html( $statuses[ bcl_get_meta_string( $bill_id, 'bc_payment_status' ) ] ?? '—' ); ?></b></div>

				<?php if ( ! empty( $payments ) ) : ?>
					<h3><?php esc_html_e( 'Payments', 'buildingcare-lite' ); ?></h3>
					<?php foreach ( $payments as $pay ) : ?>
						<div class="bcl-p-kv"><span><?php echo esc_html( (string) $pay['date'] ); ?></span><b><?php echo esc_html( bcl_format_amount( (float) $pay['amount'] ) ); ?></b></div>
					<?php endforeach; ?>
				<?php endif; ?>

				<div class="bcl-p-receipt-actions">
					<button class="bcl-p-btn" onclick="window.print()"><?php esc_html_e( 'Print / Save PDF', 'buildingcare-lite' ); ?></button>
					<a class="bcl-p-link" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Back', 'buildingcare-lite' ); ?></a>
				</div>
			</div>
		</main>
		<?php
		$this->footer();
	}
}
