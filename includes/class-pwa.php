<?php
/**
 * Progressive Web App support: web manifest, service worker, install prompt.
 *
 * The manifest and service worker are served from query-string endpoints on
 * the site root (e.g. /?bcl_pwa=sw) so they work regardless of permalink
 * settings, and the service worker scope covers the wp-admin dashboard.
 *
 * @package BuildingCareLite
 */

declare(strict_types=1);

namespace BuildingCareLite;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes the BuildingCare dashboard installable as an app.
 */
class PWA {

	private const QUERY = 'bcl_pwa';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_serve' ) );
		add_action( 'admin_head', array( $this, 'print_head_tags' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Whether the current admin screen is the BuildingCare dashboard.
	 */
	private function is_dashboard_screen( string $hook = '' ): bool {
		if ( $hook ) {
			return str_contains( $hook, 'bcl-dashboard' );
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && str_contains( (string) $screen->id, 'bcl-dashboard' );
	}

	/**
	 * URL of the manifest endpoint.
	 */
	public static function manifest_url(): string {
		return home_url( '/?' . self::QUERY . '=manifest' );
	}

	/**
	 * URL of the service worker endpoint.
	 */
	public static function sw_url(): string {
		return home_url( '/?' . self::QUERY . '=sw' );
	}

	/**
	 * Service worker scope (the directory of the script URL).
	 */
	public static function scope(): string {
		$path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		return $path ? $path : '/';
	}

	/**
	 * Serve the manifest / service worker / offline page when requested.
	 */
	public function maybe_serve(): void {
		$what = isset( $_GET[ self::QUERY ] ) ? sanitize_key( wp_unslash( $_GET[ self::QUERY ] ) ) : '';
		if ( ! $what ) {
			return;
		}

		switch ( $what ) {
			case 'manifest':
				$this->serve_manifest();
				break;
			case 'sw':
				$this->serve_service_worker();
				break;
			case 'offline':
				$this->serve_offline();
				break;
		}
	}

	/**
	 * Output the web app manifest.
	 */
	private function serve_manifest(): void {
		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );

		$icons_url   = BCL_PLUGIN_URL . 'assets/icons/';
		$is_tenant   = isset( $_GET['app'] ) && 'tenant' === sanitize_key( wp_unslash( $_GET['app'] ) );

		if ( $is_tenant ) {
			$start_url = Tenant_Portal::url();
			$scope     = self::scope();
			$name      = __( 'BuildingCare Tenant', 'buildingcare-lite' );
		} else {
			$start_url = admin_url( 'admin.php?page=bcl-dashboard' );
			$scope     = (string) wp_parse_url( admin_url( '/' ), PHP_URL_PATH );
			$scope     = $scope ? $scope : '/';
			$name      = __( 'BuildingCare', 'buildingcare-lite' );
		}

		$manifest = array(
			'name'             => $name,
			'short_name'       => $name,
			'description'      => __( 'Manage buildings, flats, residents, bills and expenses.', 'buildingcare-lite' ),
			'start_url'        => $start_url,
			'scope'            => $scope,
			'display'          => 'standalone',
			'orientation'      => 'portrait-primary',
			'background_color' => '#f0f0f1',
			'theme_color'      => '#2271b1',
			'lang'             => get_bloginfo( 'language' ),
			'dir'              => is_rtl() ? 'rtl' : 'ltr',
			'icons'            => array(
				array(
					'src'   => $icons_url . 'icon-192.png',
					'sizes' => '192x192',
					'type'  => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'   => $icons_url . 'icon-512.png',
					'sizes' => '512x512',
					'type'  => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'     => $icons_url . 'icon-maskable-512.png',
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'maskable',
				),
			),
		);

		echo wp_json_encode( $manifest );
		exit;
	}

	/**
	 * Output the service worker script.
	 */
	private function serve_service_worker(): void {
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: ' . self::scope() );
		header( 'Cache-Control: no-cache' );

		$version     = defined( 'BCL_VERSION' ) ? BCL_VERSION : '1.0.0';
		$cache       = 'bcl-pwa-' . $version;
		$assets_url  = BCL_PLUGIN_URL . 'assets/';
		$offline_url = home_url( '/?' . self::QUERY . '=offline' );
		$asset_path  = (string) wp_parse_url( $assets_url, PHP_URL_PATH );

		$precache = wp_json_encode(
			array(
				$offline_url,
				$assets_url . 'css/admin.css',
				$assets_url . 'js/admin.js',
				$assets_url . 'js/pwa.js',
				$assets_url . 'icons/icon-192.png',
				$assets_url . 'icons/icon-512.png',
			)
		);

		?>
const BCL_CACHE = <?php echo wp_json_encode( $cache ); ?>;
const BCL_OFFLINE = <?php echo wp_json_encode( $offline_url ); ?>;
const BCL_ASSET_PATH = <?php echo wp_json_encode( $asset_path ); ?>;
const BCL_PRECACHE = <?php echo $precache; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>;

self.addEventListener('install', (event) => {
	self.skipWaiting();
	event.waitUntil(
		caches.open(BCL_CACHE).then((cache) => cache.addAll(BCL_PRECACHE).catch(() => {}))
	);
});

self.addEventListener('activate', (event) => {
	event.waitUntil(
		caches.keys().then((keys) => Promise.all(
			keys.filter((k) => k !== BCL_CACHE).map((k) => caches.delete(k))
		)).then(() => self.clients.claim())
	);
});

self.addEventListener('fetch', (event) => {
	const req = event.request;
	if (req.method !== 'GET') {
		return;
	}

	const url = new URL(req.url);
	if (url.origin !== self.location.origin) {
		return;
	}

	// Cache-first for our static plugin assets.
	if (BCL_ASSET_PATH && url.pathname.indexOf(BCL_ASSET_PATH) === 0) {
		event.respondWith(
			caches.open(BCL_CACHE).then((cache) =>
				cache.match(req).then((cached) =>
					cached || fetch(req).then((resp) => {
						if (resp && resp.status === 200) {
							cache.put(req, resp.clone());
						}
						return resp;
					})
				)
			)
		);
		return;
	}

	// Network-first for page navigations; fall back to an offline page.
	// Authenticated HTML is never cached.
	if (req.mode === 'navigate') {
		event.respondWith(
			fetch(req).catch(() => caches.match(BCL_OFFLINE))
		);
	}
});
		<?php
		exit;
	}

	/**
	 * Output a minimal offline fallback page.
	 */
	private function serve_offline(): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		$icon  = esc_url( BCL_PLUGIN_URL . 'assets/icons/icon-192.png' );
		$retry = esc_url( admin_url( 'admin.php?page=bcl-dashboard' ) );
		$title = esc_html__( 'You are offline', 'buildingcare-lite' );
		$body  = esc_html__( 'BuildingCare needs an internet connection to load your data. Please reconnect and try again.', 'buildingcare-lite' );
		$retry_label = esc_html__( 'Try again', 'buildingcare-lite' );

		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>' . $title . '</title>'
			. '<style>body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;background:#f0f0f1;color:#1d2327;display:flex;min-height:100vh;align-items:center;justify-content:center;text-align:center}'
			. '.bcl-off{max-width:340px;padding:32px}.bcl-off img{width:84px;height:84px;border-radius:18px;margin-bottom:18px}'
			. '.bcl-off h1{font-size:20px;margin:0 0 10px}.bcl-off p{color:#646970;line-height:1.5;margin:0 0 20px}'
			. '.bcl-off a{display:inline-block;background:#2271b1;color:#fff;text-decoration:none;padding:11px 22px;border-radius:8px;font-weight:600}</style></head>'
			. '<body><div class="bcl-off"><img src="' . $icon . '" alt=""><h1>' . $title . '</h1><p>' . $body . '</p>'
			. '<a href="' . $retry . '">' . $retry_label . '</a></div></body></html>';
		exit;
	}

	/**
	 * Print manifest link and app meta tags on the dashboard screen.
	 */
	public function print_head_tags(): void {
		if ( ! $this->is_dashboard_screen() ) {
			return;
		}

		$apple_icon = esc_url( BCL_PLUGIN_URL . 'assets/icons/icon-192.png' );
		?>
		<link rel="manifest" href="<?php echo esc_url( self::manifest_url() ); ?>">
		<meta name="theme-color" content="#2271b1">
		<meta name="mobile-web-app-capable" content="yes">
		<meta name="apple-mobile-web-app-capable" content="yes">
		<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
		<meta name="apple-mobile-web-app-title" content="BuildingCare">
		<link rel="apple-touch-icon" href="<?php echo $apple_icon; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>">
		<?php
	}

	/**
	 * Enqueue the PWA script on the dashboard screen.
	 */
	public function enqueue( string $hook ): void {
		if ( ! $this->is_dashboard_screen( $hook ) ) {
			return;
		}

		wp_enqueue_script(
			'bcl-pwa',
			BCL_PLUGIN_URL . 'assets/js/pwa.js',
			array(),
			(string) ( file_exists( BCL_PLUGIN_DIR . 'assets/js/pwa.js' ) ? filemtime( BCL_PLUGIN_DIR . 'assets/js/pwa.js' ) : BCL_VERSION ),
			true
		);

		wp_localize_script(
			'bcl-pwa',
			'bclPwa',
			array(
				'swUrl' => self::sw_url(),
				'scope' => self::scope(),
			)
		);
	}
}
