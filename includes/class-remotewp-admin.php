<?php
/**
 * RemoteWP Admin
 *
 * Provides a modern admin dashboard with tabs for:
 * - Dashboard (token, connection tester, quick stats)
 * - Activity Log (filterable audit log viewer)
 * - Settings (permissions, rate limiting, IP whitelist)
 *
 * @package RemoteWP
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RemoteWP_Admin {

	/**
	 * @var RemoteWP_Auth
	 */
	private $auth;

	/**
	 * @var RemoteWP_Permissions
	 */
	private $permissions;

	/**
	 * @var RemoteWP_Logger
	 */
	private $logger;

	/**
	 * @var RemoteWP_License
	 */
	private $license;

	/**
	 * Constructor.
	 *
	 * @param RemoteWP_Auth        $auth        Auth handler.
	 * @param RemoteWP_Permissions $permissions Permissions handler.
	 * @param RemoteWP_Logger      $logger      Logger.
	 * @param RemoteWP_License     $license     License handler.
	 */
	public function __construct( RemoteWP_Auth $auth, RemoteWP_Permissions $permissions, RemoteWP_Logger $logger, RemoteWP_License $license ) {
		$this->auth        = $auth;
		$this->permissions = $permissions;
		$this->logger      = $logger;
		$this->license     = $license;

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_remotewp_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_remotewp_cleanup_backups', array( $this, 'handle_cleanup_backups' ) );
		add_action( 'admin_post_remotewp_unban_ip', array( $this, 'handle_unban_ip' ) );
		add_action( 'admin_post_remotewp_unban_all', array( $this, 'handle_unban_all' ) );
		add_action( 'admin_post_remotewp_regenerate_token', array( $this, 'handle_regenerate_token' ) );
		add_action( 'admin_post_remotewp_activate_license', array( $this, 'handle_activate_license' ) );
		add_action( 'admin_post_remotewp_deactivate_license', array( $this, 'handle_deactivate_license' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'in_admin_header', array( $this, 'suppress_external_notices' ), 999 );

		// Owner Lock (Full and Pro builds)
		if ( ( defined( 'REMOTEWP_IS_FULL' ) && REMOTEWP_IS_FULL ) || ( defined( 'REMOTEWP_IS_PRO' ) && REMOTEWP_IS_PRO ) ) {
			add_action( 'admin_post_remotewp_unlock', array( $this, 'handle_unlock' ) );
			add_action( 'admin_post_remotewp_lock_session', array( $this, 'handle_lock_session' ) );
			add_action( 'admin_post_remotewp_set_master_password', array( $this, 'handle_set_master_password' ) );
		}
	}

	/**
	 * Add admin menu page.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'RemoteWP', 'remotewp' ),
			__( 'RemoteWP', 'remotewp' ),
			'manage_options',
			'remotewp',
			array( $this, 'render_page' ),
			'dashicons-cloud',
			80
		);
	}

	/**
	 * Enqueue admin CSS and JS.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_remotewp' !== $hook ) {
			return;
		}

		$css_file = REMOTEWP_PLUGIN_DIR . 'admin/css/admin.css';
		$js_file  = REMOTEWP_PLUGIN_DIR . 'admin/js/admin.js';
		$css_ver  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : REMOTEWP_VERSION;
		$js_ver   = file_exists( $js_file ) ? (string) filemtime( $js_file ) : REMOTEWP_VERSION;

		wp_enqueue_style(
			'remotewp-admin',
			REMOTEWP_PLUGIN_URL . 'admin/css/admin.css',
			array(),
			$css_ver
		);

		// Inline CSS fallback — ensures styling even if external file fails to load
		if ( file_exists( $css_file ) ) {
			wp_add_inline_style( 'remotewp-admin', file_get_contents( $css_file ) );
		}

		wp_enqueue_script(
			'remotewp-admin',
			REMOTEWP_PLUGIN_URL . 'admin/js/admin.js',
			array(),
			$js_ver,
			true
		);

		wp_localize_script( 'remotewp-admin', 'remotewpAdmin', array(
			'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
			'restUrl'   => rest_url( REMOTEWP_API_NAMESPACE . '/' ),
			'nonce'     => wp_create_nonce( 'remotewp_admin' ),
			'i18n'      => array(
				'copied'        => __( 'Copied!', 'remotewp' ),
				'copyFailed'    => __( 'Copy failed', 'remotewp' ),
				'testSuccess'   => __( 'Connection successful!', 'remotewp' ),
				'testFailed'    => __( 'Connection failed', 'remotewp' ),
				'confirmRegen'  => __( 'Are you sure? Regenerating the token will disconnect all connected agents until the new token is configured.', 'remotewp' ),
			),
		) );
	}

	/**
	 * Suppress third-party admin notices on the RemoteWP screen.
	 *
	 * This keeps the plugin UI clean and prevents external promotional banners
	 * from being rendered inside or around the RemoteWP admin page.
	 */
	public function suppress_external_notices() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_remotewp' !== $screen->id ) {
			return;
		}

		// Remove global notices from other plugins/themes on this screen only.
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Owner Lock: if master password is set and session is NOT unlocked, show lock screen
		if ( $this->is_owner_locked() ) {
			$this->render_lock_screen();
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
		$token      = $this->auth->get_token();
		$settings   = $this->get_settings();

		?>
		<div class="wrap rwp-admin remotewp-wrap">
			<!-- Premium Header -->
			<div class="rwp-new-header">
				<div class="rwp-header-left">
					<div class="rwp-header-logo">
						<img src="<?php echo esc_url( REMOTEWP_PLUGIN_URL . 'assets/logo-remotewp.png' ); ?>" alt="RemoteWP Logo" style="height: 52px; width: auto; object-fit: contain; display: block;">
					</div>
					<div class="rwp-header-meta">
						<h1 class="rwp-header-title">
							RemoteWP
							<span class="rwp-version-badge">v<?php echo esc_html( REMOTEWP_VERSION ); ?></span>
						</h1>
						<p class="rwp-header-subtitle"><?php esc_html_e( 'AI-Ready WordPress Bridge', 'remotewp' ); ?></p>
					</div>
					<div class="rwp-header-badges">
						<span class="rwp-badge-status-dot connected"><?php esc_html_e( 'Connected', 'remotewp' ); ?></span>
						<?php
						$tier = $this->license->get_tier();
						$tier_label = $this->license->get_tier_label( $tier );
						$badge_class = apply_filters( 'remotewp_admin_badge_class', 'free' === $tier ? 'free' : 'pro' );
						$badge_label = apply_filters( 'remotewp_admin_badge_label', sprintf( __( '%s Mode', 'remotewp' ), $tier_label ) );
						?>
						<span class="rwp-badge-mode <?php echo esc_attr( $badge_class ); ?>">
							<?php echo esc_html( $badge_label ); ?>
						</span>
					</div>
				</div>
				<div class="rwp-header-right">
					<?php if ( ( ( defined( 'REMOTEWP_IS_FULL' ) && REMOTEWP_IS_FULL ) || ( defined( 'REMOTEWP_IS_PRO' ) && REMOTEWP_IS_PRO ) ) && $this->has_master_password() ) : ?>
						<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" style="display:inline;">
							<input type="hidden" name="action" value="remotewp_lock_session">
							<?php wp_nonce_field( 'remotewp_lock_session' ); ?>
							<button type="submit" class="rwp-header-lock-btn" title="<?php esc_attr_e( 'Lock RemoteWP', 'remotewp' ); ?>">
								<span class="dashicons dashicons-lock"></span>
							</button>
						</form>
						<span class="rwp-header-divider">|</span>
					<?php endif; ?>
					<a href="https://remotewp.dev" target="_blank" class="rwp-header-link"><?php esc_html_e( 'Documentation', 'remotewp' ); ?></a>
					<span class="rwp-header-divider">|</span>
					<a href="https://remotewp.dev/support" target="_blank" class="rwp-header-link"><?php esc_html_e( 'Support', 'remotewp' ); ?></a>
					<span class="rwp-header-divider">|</span>
					<?php if ( apply_filters( 'remotewp_is_pro_build', false ) ) : ?>
						<span class="rwp-license-badge active"><?php esc_html_e( 'License Active', 'remotewp' ); ?></span>
					<?php else : ?>
						<span class="rwp-license-badge inactive"><?php esc_html_e( 'Free Tier', 'remotewp' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<?php $this->render_notices(); ?>

			<!-- Premium Navigation -->
			<nav class="remotewp-tabs">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=remotewp&tab=dashboard' ) ); ?>"
				   class="remotewp-tab <?php echo 'dashboard' === $active_tab ? 'active' : ''; ?>">
					<?php esc_html_e( 'Overview', 'remotewp' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=remotewp&tab=access' ) ); ?>"
				   class="remotewp-tab <?php echo 'access' === $active_tab ? 'active' : ''; ?>">
					<?php esc_html_e( 'API Access', 'remotewp' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=remotewp&tab=license' ) ); ?>"
				   class="remotewp-tab <?php echo 'license' === $active_tab ? 'active' : ''; ?>">
					<?php esc_html_e( 'License', 'remotewp' ); ?>
					<?php if ( ! apply_filters( 'remotewp_is_pro_build', false ) ) : ?>
						<span class="remotewp-badge remotewp-badge-free" style="margin-left:4px;font-size:10px;">FREE</span>
					<?php endif; ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=remotewp&tab=logs' ) ); ?>"
				   class="remotewp-tab <?php echo 'logs' === $active_tab ? 'active' : ''; ?>">
					<?php esc_html_e( 'Activity Log', 'remotewp' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=remotewp&tab=settings' ) ); ?>"
				   class="remotewp-tab <?php echo 'settings' === $active_tab ? 'active' : ''; ?>">
					<?php esc_html_e( 'Settings', 'remotewp' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=remotewp&tab=docs' ) ); ?>"
				   class="remotewp-tab <?php echo 'docs' === $active_tab ? 'active' : ''; ?>">
					<?php esc_html_e( 'Docs & Support', 'remotewp' ); ?>
				</a>
			</nav>

			<div class="remotewp-content">
				<?php
				switch ( $active_tab ) {
					case 'access':
						$this->render_access_tab( $token );
						break;
					case 'license':
						$this->render_license_tab();
						break;
					case 'logs':
						$this->render_logs_tab();
						break;
					case 'settings':
						$this->render_settings_tab( $settings );
						break;
					case 'docs':
						$this->render_docs_tab();
						break;
					default:
						$this->render_dashboard_tab( $token );
						break;
				}
				?>
			</div>

			<div class="remotewp-footer">
				<p>
					<?php
					printf(
						/* translators: 1: X-HOUSE link */
						esc_html__( 'RemoteWP by %s — The AI-Ready WordPress Bridge', 'remotewp' ),
						'<a href="https://xhouse.ro" target="_blank">X-HOUSE SRL</a>'
					);
					?>
					&nbsp;|&nbsp;
					<a href="https://remotewp.dev" target="_blank"><?php esc_html_e( 'Documentation', 'remotewp' ); ?></a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Dashboard tab (Overview).
	 *
	 * @param string $token Current API token.
	 */
	private function render_dashboard_tab( $token ) {
		$logs           = $this->logger->get_recent( 5 );
		$all_logs       = $this->logger->get_recent( 500 );
		$requests_today = 0;
		$today          = current_time( 'Y-m-d' );
		foreach ( $all_logs as $log ) {
			if ( isset( $log['timestamp'] ) && strpos( $log['timestamp'], $today ) === 0 ) {
				$requests_today++;
			}
		}
		$is_pro = apply_filters( 'remotewp_is_pro_build', false );
		$connect_url  = rest_url( REMOTEWP_API_V2_NAMESPACE . '/connect' );
		$skill_url    = rest_url( REMOTEWP_API_V2_NAMESPACE . '/skill' );
		$masked_token = str_repeat( '•', 8 ) . substr( $token, -4 );
		$full_prompt  = sprintf(
			'Connect to RemoteWP: Call %s with header X-RemoteWP-Token: %s and follow instructions in response.',
			$connect_url,
			$token
		);
		?>
		<!-- Connection Hero / Summary Panel -->
		<div class="rwp-hero-section">
			<div class="rwp-hero-left">
				<h2 class="rwp-hero-title"><?php esc_html_e( 'RemoteWP is connected and ready', 'remotewp' ); ?></h2>
				<p class="rwp-hero-subtitle"><?php esc_html_e( 'Secure AI automation bridge for WordPress, WooCommerce, SEO, file operations and plugin management.', 'remotewp' ); ?></p>
				
				<div class="rwp-hero-actions" style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 16px;">
					<button type="button" class="button button-primary remotewp-btn-copy" data-target="rwp-skill-prompt-full" style="font-size: 15px; font-weight: 700; padding: 10px 24px; height: auto; background: #2563eb; border-color: #1d4ed8; border-radius: 10px; box-shadow: 0 4px 14px rgba(37,99,235,0.35); text-transform: none; display: inline-flex; align-items: center; gap: 6px;">
						<span class="dashicons dashicons-admin-links" style="margin-top: 2px; font-size: 18px;"></span>
						<?php esc_html_e( 'Copy Full Prompt', 'remotewp' ); ?>
					</button>
					<button type="button" class="button button-secondary remotewp-btn-copy" data-target="remotewp-token" style="font-size: 11px; padding: 4px 10px; height: auto; opacity: 0.75; border-radius: 6px;">
						<?php esc_html_e( 'Copy Token Only', 'remotewp' ); ?>
					</button>
					<button type="button" class="button button-secondary" id="rwp-btn-test-connection" style="font-size: 12px; padding: 6px 14px; height: auto; border-radius: 8px;">
						<?php esc_html_e( 'Test Connection', 'remotewp' ); ?>
					</button>
					<input type="hidden" id="remotewp-token" value="<?php echo esc_attr( $token ); ?>">
					<input type="hidden" id="rwp-skill-prompt-full" value="<?php echo esc_attr( $full_prompt ); ?>">
				</div>
				<p style="margin: 12px 0 0; color: #9ca9be; font-size: 12px;">
					💡 <?php esc_html_e( 'Tip: Click "Copy Full Prompt" and paste it directly into Claude, Cursor, ChatGPT or Antigravity to connect in 1 click.', 'remotewp' ); ?>
				</p>
				<div id="rwp-test-result" class="rwp-test-result-hidden" style="margin-top: 12px; max-width: 320px;"></div>
			</div>
			
			<div class="rwp-hero-right">
				<div class="rwp-status-panel">
					<div class="rwp-spanel-row">
						<span class="rwp-spanel-label"><?php esc_html_e( 'Status:', 'remotewp' ); ?></span>
						<span class="rwp-spanel-val connected">
							<span class="rwp-pulse-dot"></span>
							<?php esc_html_e( 'Connected', 'remotewp' ); ?>
						</span>
					</div>
					<div class="rwp-spanel-row">
						<span class="rwp-spanel-label"><?php esc_html_e( 'Latency:', 'remotewp' ); ?></span>
						<span class="rwp-spanel-val">15ms</span>
					</div>
					<div class="rwp-spanel-row">
						<span class="rwp-spanel-label"><?php esc_html_e( 'Requests Today:', 'remotewp' ); ?></span>
						<span class="rwp-spanel-val"><?php echo esc_html( $requests_today ); ?></span>
					</div>
					<div class="rwp-spanel-row">
						<span class="rwp-spanel-label"><?php esc_html_e( 'Security:', 'remotewp' ); ?></span>
						<span class="rwp-spanel-val secure-badge"><?php esc_html_e( 'Token Protected', 'remotewp' ); ?></span>
					</div>
				</div>
			</div>
		</div>

		<!-- Quick Start Guide: first action after the connection status -->
		<div class="remotewp-card rwp-quickstart-card" style="margin-top: 18px; border: 1px solid rgba(37,99,235,0.25); background: #0f172a;">
			<div class="remotewp-card-header">
				<h2 style="display: flex; align-items: center; gap: 8px;">
					🎯 <?php esc_html_e( 'Quick Start', 'remotewp' ); ?>
				</h2>
				<p class="rwp-card-header-subtitle"><?php esc_html_e( 'Three steps to connect your AI agent and start working safely.', 'remotewp' ); ?></p>
			</div>
			<div class="remotewp-card-body">
				<div class="rwp-steps-grid">
					<div class="rwp-step-item">
						<div class="rwp-step-num-wrap"><span class="rwp-step-number">1</span></div>
						<div class="rwp-step-content">
							<h4 class="rwp-step-title">🔑 <?php esc_html_e( 'Activate your license', 'remotewp' ); ?></h4>
							<p class="rwp-step-desc"><a href="<?php echo esc_url( admin_url( 'admin.php?page=remotewp&tab=license' ) ); ?>"><?php esc_html_e( 'Open the License tab', 'remotewp' ); ?></a> <?php esc_html_e( 'and activate your Free or Pro key.', 'remotewp' ); ?></p>
						</div>
					</div>
					<div class="rwp-step-item">
						<div class="rwp-step-num-wrap"><span class="rwp-step-number">2</span></div>
						<div class="rwp-step-content">
							<h4 class="rwp-step-title">⚡ <?php esc_html_e( 'Copy the connection prompt', 'remotewp' ); ?></h4>
							<p class="rwp-step-desc"><?php esc_html_e( 'Use the blue button above, then paste the prompt into your AI agent.', 'remotewp' ); ?></p>
						</div>
					</div>
					<div class="rwp-step-item">
						<div class="rwp-step-num-wrap"><span class="rwp-step-number">3</span></div>
						<div class="rwp-step-content">
							<h4 class="rwp-step-title">🤖 <?php esc_html_e( 'Start your first task', 'remotewp' ); ?></h4>
							<p class="rwp-step-desc"><?php esc_html_e( 'Ask the agent to inspect the site before making changes.', 'remotewp' ); ?></p>
						</div>
					</div>
				</div>
				<div class="rwp-howto-video">
					<div class="rwp-howto-video-heading">▶ <?php esc_html_e( 'How to connect RemoteWP', 'remotewp' ); ?></div>
					<div class="rwp-howto-video-frame">
						<iframe src="https://www.youtube-nocookie.com/embed/98sJw7tmmWQ" title="How to connect RemoteWP" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
					</div>
				</div>
				<div class="rwp-quickstart-actions">
					<button type="button" class="button button-primary remotewp-btn-copy" data-target="rwp-skill-prompt-full">⚡ <?php esc_html_e( 'Copy Full Prompt', 'remotewp' ); ?></button>
					<a class="rwp-howto-link" href="https://youtu.be/98sJw7tmmWQ?si=sWLaPwHxR0H7f1Z8" target="_blank" rel="noopener noreferrer">▶ <?php esc_html_e( 'How to: Watch the setup video', 'remotewp' ); ?> <span>(youtu.be/98sJw7tmmWQ)</span></a>
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=remotewp&tab=docs' ) ); ?>"><?php esc_html_e( 'Documentation & capabilities', 'remotewp' ); ?></a>
				</div>
			</div>
		</div>

		<!-- Trust Strip -->
		<div class="rwp-trust-strip">
			<div class="rwp-trust-item">
				<span class="dashicons dashicons-lock"></span>
				<span><?php esc_html_e( 'Token-based authentication', 'remotewp' ); ?></span>
			</div>
			<div class="rwp-trust-item">
				<span class="dashicons dashicons-shield"></span>
				<span><?php esc_html_e( 'Permission-aware operations', 'remotewp' ); ?></span>
			</div>
			<div class="rwp-trust-item">
				<span class="dashicons dashicons-rest-api"></span>
				<span><?php esc_html_e( 'WordPress REST API compatible', 'remotewp' ); ?></span>
			</div>
			<div class="rwp-trust-item">
				<span class="dashicons dashicons-visibility"></span>
				<span><?php esc_html_e( 'Activity logging enabled', 'remotewp' ); ?></span>
			</div>
		</div>

		<!-- Status Overview Cards -->
		<div class="rwp-status-cards">
			<!-- Card 1: Connection -->
			<div class="rwp-status-card">
				<div class="rwp-scard-top">
					<span class="rwp-scard-title"><?php esc_html_e( 'CONNECTION', 'remotewp' ); ?></span>
					<span class="rwp-status-indicator active"></span>
				</div>
				<div class="rwp-scard-value connected"><?php esc_html_e( 'Connected', 'remotewp' ); ?></div>
				<div class="rwp-scard-desc"><?php esc_html_e( 'API bridge is active', 'remotewp' ); ?></div>
				<span class="dashicons dashicons-admin-links rwp-scard-bg-icon"></span>
			</div>

			<!-- Card 2: Permission -->
			<div class="rwp-status-card">
				<div class="rwp-scard-top">
					<span class="rwp-scard-title"><?php esc_html_e( 'PERMISSION', 'remotewp' ); ?></span>
				</div>
				<div class="rwp-scard-value"><?php esc_html_e( 'Server managed', 'remotewp' ); ?></div>
				<div class="rwp-scard-desc"><?php esc_html_e( 'Level of file access', 'remotewp' ); ?></div>
				<span class="dashicons dashicons-shield rwp-scard-bg-icon"></span>
			</div>

			<!-- Card 3: Rate Limit -->
			<div class="rwp-status-card">
				<div class="rwp-scard-top">
					<span class="rwp-scard-title"><?php esc_html_e( 'RATE LIMIT', 'remotewp' ); ?></span>
				</div>
				<div class="rwp-scard-value"><?php echo esc_html( get_option( 'remotewp_rate_limit', 60 ) ); ?>/min</div>
				<div class="rwp-scard-desc"><?php esc_html_e( 'Requests permitted', 'remotewp' ); ?></div>
				<span class="dashicons dashicons-performance rwp-scard-bg-icon"></span>
			</div>

			<!-- Card 4: API Version -->
			<div class="rwp-status-card">
				<div class="rwp-scard-top">
					<span class="rwp-scard-title"><?php esc_html_e( 'API VERSION', 'remotewp' ); ?></span>
				</div>
				<div class="rwp-scard-value"><?php esc_html_e( 'v2', 'remotewp' ); ?></div>
				<div class="rwp-scard-desc"><?php esc_html_e( 'Current REST API version', 'remotewp' ); ?></div>
				<span class="dashicons dashicons-code-standards rwp-scard-bg-icon"></span>
			</div>
		</div>

		<!-- AI Agent Skill Pack (PROMINENT AT TOP) -->
		<div class="remotewp-card" id="rwp-skill-pack-card" style="margin-top: 28px; border: 1px solid rgba(255,122,26,0.3); background: linear-gradient(170deg, rgba(21, 31, 50, 0.98), rgba(15, 23, 38, 0.98));">
			<div class="remotewp-card-header" style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;">
				<div>
					<h2 style="display: flex; align-items: center; gap: 8px;">
						🚀 <?php esc_html_e( 'AI Agent Skill Pack', 'remotewp' ); ?>
					</h2>
					<p class="rwp-card-header-subtitle"><?php esc_html_e( 'Connect your AI coding agent with a single prompt.', 'remotewp' ); ?></p>
				</div>
				<span style="background: rgba(255,122,26,0.15); color: #ff7a1a; border: 1px solid rgba(255,122,26,0.3); font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 20px; text-transform: uppercase; letter-spacing: 1px;">
					<?php esc_html_e( '1-Click Connection', 'remotewp' ); ?>
				</span>
			</div>
			<div class="remotewp-card-body">
				<p style="color: #9ca9be; font-size: 14px; margin: 0 0 20px; line-height: 1.6;">
					<?php esc_html_e( 'Paste the prompt below into your AI agent (Claude, Cursor, ChatGPT, Copilot, etc.) to give it full RemoteWP capabilities.', 'remotewp' ); ?>
				</p>

				<div class="rwp-skill-prompt-box" style="background: #0d1320; border: 1px solid rgba(255,122,26,0.25); border-radius: 12px; padding: 24px; margin-bottom: 20px; position: relative; box-shadow: 0 8px 24px rgba(0,0,0,0.3);">
					<p style="margin: 0 0 6px; color: #5a657a; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 2px;"><?php esc_html_e( 'Agent Prompt', 'remotewp' ); ?></p>
					<p style="margin: 0 0 16px; color: #c4cfdf; font-size: 13px; line-height: 1.7; font-family: 'Courier New', monospace; word-break: break-all;">
						<strong>Connect to RemoteWP:</strong> Call <span style="color: #ff7a1a;"><?php echo esc_html( $connect_url ); ?></span> with header X-RemoteWP-Token: <span style="color: #22c58f;"><?php echo esc_html( $masked_token ); ?></span> and follow instructions in response.
					</p>
					<button type="button" class="button button-primary remotewp-btn-copy" data-target="rwp-skill-prompt-full" style="font-size: 14px; padding: 6px 18px; height: auto;">
						⚡ <?php esc_html_e( 'Copy Full Prompt', 'remotewp' ); ?>
					</button>
				</div>

				<div class="rwp-skill-actions" style="display: flex; gap: 12px; flex-wrap: wrap;">
					<a href="<?php echo esc_url( $skill_url ); ?>" target="_blank" rel="noopener" class="button button-secondary">
						<?php esc_html_e( 'Open central SKILL.md', 'remotewp' ); ?>
					</a>
					<a href="https://github.com/WordPress/agent-skills" target="_blank" class="button button-secondary">
						<?php esc_html_e( 'WordPress Agent Skills', 'remotewp' ); ?>
					</a>
					<a href="<?php echo esc_url( $skill_url ); ?>" target="_blank" class="button button-secondary">
						<?php esc_html_e( 'Preview Skill Endpoint', 'remotewp' ); ?>
					</a>
				</div>
			</div>
		</div>

		<!-- Automation Features -->
		<div class="rwp-capabilities-section rwp-overview-secondary" style="margin-top: 28px;">
			<div class="rwp-section-header">
				<h3 class="rwp-section-title"><?php esc_html_e( 'Automation Features', 'remotewp' ); ?></h3>
				<p class="rwp-section-subtitle"><?php esc_html_e( 'Core AI-powered actions available through the RemoteWP API.', 'remotewp' ); ?></p>
			</div>

			<div class="rwp-cap-grid">
				<!-- Card 1 -->
				<div class="rwp-cap-card">
					<div class="rwp-cap-card-header">
						<span class="rwp-cap-icon-wrap"><span class="dashicons dashicons-welcome-write-blog"></span></span>
						<span class="rwp-cap-badge">~2 sec</span>
					</div>
					<div class="rwp-cap-card-body">
						<h4 class="rwp-cap-title"><?php esc_html_e( 'AI Article Publishing', 'remotewp' ); ?></h4>
						<p class="rwp-cap-desc"><?php esc_html_e( 'Create and publish SEO-ready articles directly into WordPress, including HTML structure, titles, categories and meta descriptions.', 'remotewp' ); ?></p>
					</div>
				</div>

				<!-- Card 2 -->
				<div class="rwp-cap-card">
					<div class="rwp-cap-card-header">
						<span class="rwp-cap-icon-wrap"><span class="dashicons dashicons-cart"></span></span>
						<span class="rwp-cap-badge">~3 sec</span>
					</div>
					<div class="rwp-cap-card-body">
						<h4 class="rwp-cap-title"><?php esc_html_e( 'WooCommerce Optimization', 'remotewp' ); ?></h4>
						<p class="rwp-cap-desc"><?php esc_html_e( 'Update product descriptions, prices, stock, images and attributes for WooCommerce products through controlled AI requests.', 'remotewp' ); ?></p>
					</div>
				</div>

				<!-- Card 3 -->
				<div class="rwp-cap-card">
					<div class="rwp-cap-card-header">
						<span class="rwp-cap-icon-wrap"><span class="dashicons dashicons-performance"></span></span>
						<span class="rwp-cap-badge">~1 sec</span>
					</div>
					<div class="rwp-cap-card-body">
						<h4 class="rwp-cap-title"><?php esc_html_e( 'SEO Automation', 'remotewp' ); ?></h4>
						<p class="rwp-cap-desc"><?php esc_html_e( 'Generate meta titles, meta descriptions, heading structures and Schema.org JSON-LD for existing pages.', 'remotewp' ); ?></p>
					</div>
				</div>

				<!-- Card 4 -->
				<div class="rwp-cap-card">
					<div class="rwp-cap-card-header">
						<span class="rwp-cap-icon-wrap"><span class="dashicons dashicons-admin-appearance"></span></span>
						<span class="rwp-cap-badge">~2 sec</span>
					</div>
					<div class="rwp-cap-card-body">
						<h4 class="rwp-cap-title"><?php esc_html_e( 'CSS & Layout Fixes', 'remotewp' ); ?></h4>
						<p class="rwp-cap-desc"><?php esc_html_e( 'Apply controlled CSS changes, fix visual issues and adjust layouts directly through the secure API.', 'remotewp' ); ?></p>
					</div>
				</div>
			</div>
		</div>

		<!-- Trust & Control -->
		<div class="rwp-capabilities-section rwp-overview-secondary" style="margin-top: 36px;">
			<div class="rwp-section-header">
				<h3 class="rwp-section-title"><?php esc_html_e( 'Trust & Control', 'remotewp' ); ?></h3>
				<p class="rwp-section-subtitle"><?php esc_html_e( 'Security, permissions and monitoring features designed for controlled AI automation.', 'remotewp' ); ?></p>
			</div>

			<div class="rwp-cap-grid">
				<!-- Card 5: Secure API Access -->
				<div class="rwp-cap-card">
					<div class="rwp-cap-card-header">
						<span class="rwp-cap-icon-wrap"><span class="dashicons dashicons-shield"></span></span>
						<span class="rwp-cap-badge"><?php esc_html_e( 'Protected', 'remotewp' ); ?></span>
					</div>
					<div class="rwp-cap-card-body">
						<h4 class="rwp-cap-title"><?php esc_html_e( 'Secure API Access', 'remotewp' ); ?></h4>
						<p class="rwp-cap-desc"><?php esc_html_e( 'Protect every request with token-based authentication, controlled permissions and secure WordPress REST API communication.', 'remotewp' ); ?></p>
					</div>
				</div>

				<!-- Card 6: Permission Management -->
				<div class="rwp-cap-card">
					<div class="rwp-cap-card-header">
						<span class="rwp-cap-icon-wrap"><span class="dashicons dashicons-admin-generic"></span></span>
						<span class="rwp-cap-badge"><?php esc_html_e( 'Controlled', 'remotewp' ); ?></span>
					</div>
					<div class="rwp-cap-card-body">
						<h4 class="rwp-cap-title"><?php esc_html_e( 'Permission Management', 'remotewp' ); ?></h4>
						<p class="rwp-cap-desc"><?php esc_html_e( 'Control what AI agents can access, limit sensitive actions and manage API permissions from a centralized admin panel.', 'remotewp' ); ?></p>
					</div>
				</div>

				<!-- Card 7: Activity Monitoring -->
				<div class="rwp-cap-card">
					<div class="rwp-cap-card-header">
						<span class="rwp-cap-icon-wrap"><span class="dashicons dashicons-chart-area"></span></span>
						<span class="rwp-cap-badge"><?php esc_html_e( 'Auditable', 'remotewp' ); ?></span>
					</div>
					<div class="rwp-cap-card-body">
						<h4 class="rwp-cap-title"><?php esc_html_e( 'Activity Monitoring', 'remotewp' ); ?></h4>
						<p class="rwp-cap-desc"><?php esc_html_e( 'Track API requests, endpoints, response status, IP addresses and execution time to keep every automation transparent.', 'remotewp' ); ?></p>
					</div>
				</div>

				<!-- Card 8: Smart Workflow Execution -->
				<div class="rwp-cap-card">
					<div class="rwp-cap-card-header">
						<span class="rwp-cap-icon-wrap"><span class="dashicons dashicons-networking"></span></span>
						<span class="rwp-cap-badge"><?php esc_html_e( 'Automated', 'remotewp' ); ?></span>
					</div>
					<div class="rwp-cap-card-body">
						<h4 class="rwp-cap-title"><?php esc_html_e( 'Smart Workflow Execution', 'remotewp' ); ?></h4>
						<p class="rwp-cap-desc"><?php esc_html_e( 'Run structured AI workflows for publishing, optimization, maintenance and content updates with predictable API behavior.', 'remotewp' ); ?></p>
					</div>
				</div>
			</div>
		</div>

		<!-- Recent Activity -->
		<div class="remotewp-card remotewp-card-wide" style="margin-top: 36px;">
			<div class="remotewp-card-header">
				<h2><?php esc_html_e( 'Recent Activity', 'remotewp' ); ?></h2>
				<p class="rwp-card-header-subtitle"><?php esc_html_e( 'Latest API requests executed through RemoteWP.', 'remotewp' ); ?></p>
			</div>
			<div class="remotewp-card-body" style="padding: 0;">
				<?php if ( empty( $logs ) ) : ?>
					<div class="rwp-empty-state" style="padding: 48px 24px;">
						<div class="rwp-empty-icon"><span class="dashicons dashicons-clock"></span></div>
						<h3 class="rwp-empty-title"><?php esc_html_e( 'No API activity recorded yet.', 'remotewp' ); ?></h3>
						<p class="remotewp-empty-desc"><?php esc_html_e( 'Once your AI agent starts using RemoteWP, requests will appear here.', 'remotewp' ); ?></p>
					</div>
				<?php else : ?>
					<div class="rwp-table-responsive">
						<table class="remotewp-log-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Time', 'remotewp' ); ?></th>
									<th><?php esc_html_e( 'IP', 'remotewp' ); ?></th>
									<th><?php esc_html_e( 'Endpoint', 'remotewp' ); ?></th>
									<th><?php esc_html_e( 'Method', 'remotewp' ); ?></th>
									<th><?php esc_html_e( 'Status', 'remotewp' ); ?></th>
									<th><?php esc_html_e( 'Duration', 'remotewp' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $logs as $log ) : 
									$full_path = $log['path'] ?? '-';
									$short_path = $full_path;
									if ( strlen( $full_path ) > 32 ) {
										$short_path = substr( $full_path, 0, 12 ) . '...' . substr( $full_path, -15 );
									}
									$method = strtoupper( $log['action'] ?? '' );
									$status = $log['status'] ?? 'success';
								?>
									<tr class="remotewp-log-row remotewp-log-<?php echo esc_attr( $status ); ?>">
										<td><span class="rwp-log-time"><?php echo esc_html( $log['timestamp'] ?? '' ); ?></span></td>
										<td><span class="rwp-log-ip"><?php echo esc_html( $log['ip'] ?? '' ); ?></span></td>
										<td>
											<span class="rwp-log-endpoint" title="<?php echo esc_attr( $full_path ); ?>">
												<code><?php echo esc_html( $short_path ); ?></code>
											</span>
										</td>
										<td>
											<span class="rwp-method-badge rwp-method-<?php echo esc_attr( strtolower( $method ) ); ?>">
												<?php echo esc_html( $method ); ?>
											</span>
										</td>
										<td>
											<span class="rwp-status-indicator-badge rwp-status-<?php echo esc_attr( $status ); ?>">
												<span class="rwp-status-dot"></span>
												<?php echo esc_html( ucfirst( $status ) ); ?>
											</span>
										</td>
										<td><span class="rwp-log-duration"><?php echo esc_html( isset( $log['duration'] ) ? $log['duration'] . 'ms' : '15ms' ); ?></span></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the API Access tab.
	 *
	 * @param string $token Current API token.
	 */
	private function render_access_tab( $token ) {
		$api_url = rest_url( REMOTEWP_API_NAMESPACE . '/' );
		$is_pro  = apply_filters( 'remotewp_is_pro_build', false );
		?>
		<!-- API Access Card -->
		<div class="remotewp-card rwp-access-card">
			<div class="remotewp-card-header">
				<h2><?php esc_html_e( 'API Access', 'remotewp' ); ?></h2>
			</div>
			<div class="remotewp-card-body">
				<p class="rwp-access-subtitle"><?php esc_html_e( 'Your WordPress site is connected and ready to receive secure AI-powered requests.', 'remotewp' ); ?></p>
				
				<label class="rwp-token-label"><?php esc_html_e( 'API Token', 'remotewp' ); ?></label>
				<div class="remotewp-token-field rwp-secure-token-field">
					<input type="password" id="remotewp-token" readonly value="<?php echo esc_attr( $token ); ?>"
					       class="remotewp-input-mono" onclick="this.select();">
					<button type="button" class="button rwp-btn-secondary" id="remotewp-btn-reveal">
						<span class="rwp-btn-text"><?php esc_html_e( 'Reveal', 'remotewp' ); ?></span>
					</button>
					<button type="button" class="button rwp-btn-secondary remotewp-btn-copy" data-target="remotewp-token">
						<?php esc_html_e( 'Copy', 'remotewp' ); ?>
					</button>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="remotewp-inline-form">
						<input type="hidden" name="action" value="remotewp_regenerate_token">
						<?php wp_nonce_field( 'remotewp_regenerate_token' ); ?>
						<button type="submit" class="button rwp-btn-danger-outline" id="remotewp-regen-btn">
							<?php esc_html_e( 'Regenerate Token', 'remotewp' ); ?>
						</button>
					</form>
				</div>

				<?php
				// Token Expiry Status
				$expiry_info = $this->auth->get_token_expiry_info();
				?>
				<div class="rwp-token-expiry-status" style="margin-top: 12px; padding: 12px 16px; border-radius: 10px; display: flex; align-items: center; gap: 10px; font-size: 13px;
					<?php if ( 'expired' === $expiry_info['status'] ) : ?>
						background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5;
					<?php elseif ( 'warning' === $expiry_info['status'] ) : ?>
						background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.3); color: #fcd34d;
					<?php elseif ( 'active' === $expiry_info['status'] ) : ?>
						background: rgba(34, 197, 94, 0.08); border: 1px solid rgba(34, 197, 94, 0.25); color: #86efac;
					<?php else : ?>
						background: rgba(100, 116, 139, 0.08); border: 1px solid rgba(100, 116, 139, 0.2); color: #94a3b8;
					<?php endif; ?>
				">
					<?php if ( 'expired' === $expiry_info['status'] ) : ?>
						<span style="font-size: 16px;">🔴</span>
						<strong><?php esc_html_e( 'Token Expired', 'remotewp' ); ?></strong> —
						<?php esc_html_e( 'Click "Regenerate Token" to create a new one.', 'remotewp' ); ?>
					<?php elseif ( 'warning' === $expiry_info['status'] ) : ?>
						<span style="font-size: 16px;">⚠️</span>
						<strong><?php esc_html_e( 'Token Expiring Soon', 'remotewp' ); ?></strong> —
						<?php
						$hours = floor( $expiry_info['remaining'] / 3600 );
						$mins  = floor( ( $expiry_info['remaining'] % 3600 ) / 60 );
						printf(
							esc_html__( 'Expires in %1$dh %2$dm. Regenerate soon.', 'remotewp' ),
							$hours,
							$mins
						);
						?>
					<?php elseif ( 'active' === $expiry_info['status'] ) : ?>
						<span style="font-size: 16px;">🟢</span>
						<strong><?php esc_html_e( 'Token Active', 'remotewp' ); ?></strong> —
						<?php
						$hours = floor( $expiry_info['remaining'] / 3600 );
						$mins  = floor( ( $expiry_info['remaining'] % 3600 ) / 60 );
						printf(
							esc_html__( 'Expires in %1$dh %2$dm', 'remotewp' ),
							$hours,
							$mins
						);
						?>
					<?php else : ?>
						<span style="font-size: 16px;">♾️</span>
						<strong><?php esc_html_e( 'Token: Never Expires', 'remotewp' ); ?></strong> —
						<?php esc_html_e( 'Enable token rotation in Settings → Security for better security.', 'remotewp' ); ?>
					<?php endif; ?>
				</div>

				<div class="rwp-test-connection-section">
					<button type="button" class="button button-primary" id="rwp-btn-test-connection">
						<?php esc_html_e( 'Test API Connection', 'remotewp' ); ?>
					</button>
					<div id="rwp-test-result" class="rwp-test-result-hidden"></div>
				</div>

				<!-- Stats row -->
				<div class="rwp-stats rwp-access-stats-grid">
					<div class="rwp-stat">
						<span class="rwp-stat-label"><?php esc_html_e( 'API Base', 'remotewp' ); ?></span>
						<span class="rwp-stat-value rwp-stat-url" id="remotewp-api-url"><?php echo esc_html( $api_url ); ?>
							<button type="button" class="button-link remotewp-btn-copy-small" data-target="remotewp-api-url"><span class="dashicons dashicons-clipboard"></span></button>
						</span>
					</div>
					<div class="rwp-stat">
						<span class="rwp-stat-label"><?php esc_html_e( 'Auth Header', 'remotewp' ); ?></span>
						<span class="rwp-stat-value"><code>X-RemoteWP-Token</code></span>
					</div>
					<div class="rwp-stat">
						<span class="rwp-stat-label"><?php esc_html_e( 'Permission', 'remotewp' ); ?></span>
						<span class="rwp-stat-value">
							<span class="remotewp-badge remotewp-badge-full">
								<?php esc_html_e( 'Server managed', 'remotewp' ); ?>
							</span>
						</span>
					</div>
					<div class="rwp-stat">
						<span class="rwp-stat-label"><?php esc_html_e( 'Rate Limit', 'remotewp' ); ?></span>
						<span class="rwp-stat-value"><?php echo esc_html( get_option( 'remotewp_rate_limit', 60 ) ); ?>/min</span>
					</div>
				</div>
			</div>
		</div>

		<!-- AI Agent Integration Card -->
		<div class="remotewp-card" style="margin-top: 24px;">
			<div class="remotewp-card-header">
				<h2><?php esc_html_e( 'AI Agent Integration', 'remotewp' ); ?></h2>
			</div>
			<div class="remotewp-card-body rwp-integration-body">
				<div class="rwp-integration-content">
					<p class="rwp-integration-text">
						<?php esc_html_e( 'RemoteWP automatically provides a complete integration guide for AI agents such as ChatGPT, Claude, Cursor or other compatible systems. The guide includes available endpoints, authentication method and usage examples.', 'remotewp' ); ?>
					</p>
					
					<!-- 3 visual steps -->
					<div class="rwp-steps-grid">
						<div class="rwp-step-item">
							<div class="rwp-step-number">1</div>
							<div class="rwp-step-title"><?php esc_html_e( 'Copy your API token', 'remotewp' ); ?></div>
							<div class="rwp-step-desc"><?php esc_html_e( 'Retrieve your secure token from the API Access card above.', 'remotewp' ); ?></div>
						</div>
						<div class="rwp-step-item">
							<div class="rwp-step-number">2</div>
							<div class="rwp-step-title"><?php esc_html_e( 'Add it to your AI agent', 'remotewp' ); ?></div>
							<div class="rwp-step-desc"><?php esc_html_e( 'Configure the agent to pass this token in the X-RemoteWP-Token header.', 'remotewp' ); ?></div>
						</div>
						<div class="rwp-step-item">
							<div class="rwp-step-number">3</div>
							<div class="rwp-step-title"><?php esc_html_e( 'Run your first API request', 'remotewp' ); ?></div>
							<div class="rwp-step-desc"><?php esc_html_e( 'The agent will read endpoints and begin operations automatically.', 'remotewp' ); ?></div>
						</div>
					</div>
				</div>
				<div class="rwp-integration-actions" style="margin-top: 20px;">
					<?php $skill_endpoint = rest_url( REMOTEWP_API_V2_NAMESPACE . '/skill' ); ?>
					<a href="<?php echo esc_url( $skill_endpoint ); ?>" target="_blank" class="button button-primary rwp-btn-md">
						<?php esc_html_e( 'View Skill Endpoint', 'remotewp' ); ?>
					</a>
					<button type="button" class="button button-secondary rwp-btn-md remotewp-btn-copy" data-target="rwp-skill-endpoint-url">
						<?php esc_html_e( 'Copy Skill Endpoint', 'remotewp' ); ?>
					</button>
					<span id="rwp-skill-endpoint-url" style="display:none;"><?php echo esc_url( $skill_endpoint ); ?></span>
				</div>
			</div>
		</div>

		<!-- API Reference Card -->
		<div class="remotewp-card" style="margin-top: 24px;">
			<div class="remotewp-card-header">
				<h2><?php esc_html_e( 'API Reference', 'remotewp' ); ?></h2>
			</div>
			<div class="remotewp-card-body" style="padding:0;">
				<table class="rwp-endpoint-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Method', 'remotewp' ); ?></th>
							<th><?php esc_html_e( 'Endpoint', 'remotewp' ); ?></th>
							<th><?php esc_html_e( 'Description', 'remotewp' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$endpoints = apply_filters( 'remotewp_endpoint_list', array(
							array( 'GET',  '/status',          __( 'Server status', 'remotewp' ),     true ),
							array( 'GET',  '/list',            __( 'List directory', 'remotewp' ),    true ),
							array( 'GET',  '/read',            __( 'Read file content', 'remotewp' ), true ),
							array( 'GET',  '/wp/info',         __( 'Site information', 'remotewp' ),  true ),
							array( 'POST', '/write',           __( 'Write file', 'remotewp' ),        false ),
							array( 'POST', '/delete',          __( 'Delete file', 'remotewp' ),       false ),
							array( 'POST', '/rename',          __( 'Rename file', 'remotewp' ),       false ),
							array( 'POST', '/mkdir',           __( 'Create directory', 'remotewp' ),  false ),
							array( 'GET',  '/search',          __( 'Search files', 'remotewp' ),      false ),
							array( 'GET',  '/wp/plugins',      __( 'Plugin list', 'remotewp' ),       false ),
							array( 'POST', '/wp/plugin/toggle', __( 'Toggle plugin', 'remotewp' ),    false ),
						) );
						foreach ( $endpoints as $ep ) :
							$method_class = 'GET' === $ep[0] ? 'rwp-method-get' : 'rwp-method-post';
							$locked       = ! $ep[3];
							?>
							<tr class="<?php echo $locked ? 'rwp-endpoint-locked' : ''; ?>">
								<td><span class="rwp-method-badge <?php echo esc_attr( $method_class ); ?>"><?php echo esc_html( $ep[0] ); ?></span></td>
								<td><code style="word-break: break-all;"><?php echo esc_html( $ep[1] ); ?></code></td>
								<td>
									<?php echo esc_html( $ep[2] ); ?>
									<?php if ( $locked ) : ?>
										<span class="rwp-pro-tag">PRO</span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( apply_filters( 'remotewp_show_upgrade_cta', true ) ) : ?>
					<div class="rwp-upgrade-banner">
						<span><?php esc_html_e( 'Unlock all endpoints with a Pro license', 'remotewp' ); ?></span>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=remotewp&tab=license' ) ); ?>" class="rwp-upgrade-link"><?php esc_html_e( 'Upgrade', 'remotewp' ); ?> →</a>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Activity Log tab.
	 */
	private function render_logs_tab() {
		$filter = isset( $_GET['filter'] ) ? sanitize_key( $_GET['filter'] ) : '';
		$logs   = $this->logger->get_recent( 100, $filter );
		?>
		<div class="remotewp-card remotewp-card-full">
			<div class="remotewp-card-header">
				<h2><?php esc_html_e( 'Activity Log', 'remotewp' ); ?></h2>
				<div class="remotewp-log-filters">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=remotewp&tab=logs' ) ); ?>"
					   class="button <?php echo empty( $filter ) ? 'button-primary' : ''; ?>">
						<?php esc_html_e( 'All', 'remotewp' ); ?>
					</a>
					<?php
					$actions = array( 'READ', 'WRITE', 'DELETE', 'LIST', 'SEARCH', 'AUTH_FAIL', 'WP_INFO', 'WP_CACHE_CLEAR' );
					foreach ( $actions as $action ) :
						?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=remotewp&tab=logs&filter=' . strtolower( $action ) ) ); ?>"
						   class="button <?php echo strtolower( $action ) === $filter ? 'button-primary' : ''; ?>">
							<?php echo esc_html( $action ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="remotewp-card-body">
				<?php if ( empty( $logs ) ) : ?>
					<p class="remotewp-empty"><?php esc_html_e( 'No log entries found.', 'remotewp' ); ?></p>
				<?php else : ?>
					<table class="remotewp-log-table remotewp-log-table-full">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Timestamp', 'remotewp' ); ?></th>
								<th><?php esc_html_e( 'Action', 'remotewp' ); ?></th>
								<th><?php esc_html_e( 'Path', 'remotewp' ); ?></th>
								<th><?php esc_html_e( 'Details', 'remotewp' ); ?></th>
								<th><?php esc_html_e( 'IP', 'remotewp' ); ?></th>
								<th><?php esc_html_e( 'Status', 'remotewp' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $logs as $log ) : ?>
								<tr class="remotewp-log-<?php echo esc_attr( $log['status'] ?? 'success' ); ?>">
									<td><code><?php echo esc_html( $log['timestamp'] ?? '' ); ?></code></td>
									<td><span class="remotewp-action-badge"><?php echo esc_html( $log['action'] ?? '' ); ?></span></td>
									<td><code><?php echo esc_html( $log['path'] ?? '-' ); ?></code></td>
									<td><?php echo esc_html( $log['details'] ?? '-' ); ?></td>
									<td><?php echo esc_html( $log['ip'] ?? '' ); ?></td>
									<td>
										<span class="remotewp-status-<?php echo esc_attr( $log['status'] ?? 'success' ); ?>">
											<?php echo esc_html( $log['status'] ?? 'success' ); ?>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Settings tab.
	 *
	 * @param array $settings Current settings.
	 */
	private function render_settings_tab( $settings ) {
		?>
		<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
			<input type="hidden" name="action" value="remotewp_save_settings">
			<?php wp_nonce_field( 'remotewp_save_settings' ); ?>

			<div class="remotewp-grid">
				<!-- Central policy notice: no local entitlement controls -->
				<div class="remotewp-card">
					<div class="remotewp-card-header">
						<h2><?php esc_html_e( 'RemoteWP Server Policy', 'remotewp' ); ?></h2>
					</div>
					<div class="remotewp-card-body">
						<p><?php esc_html_e( 'Licența, trial-ul, nivelul Pro/Lifetime și capabilitățile sunt gestionate central de RemoteWP. Nu există setări locale de Permission Level sau Path Restrictions care să blocheze agentul.', 'remotewp' ); ?></p>
						<p class="description"><?php esc_html_e( 'Pentru operațiuni sensibile se creează backup și se cere aprobarea operatorului; protecțiile WordPress și fișierele interne rămân protejate tehnic.', 'remotewp' ); ?></p>
					</div>
				</div>

				<!-- Security Card -->
				<div class="remotewp-card">
					<div class="remotewp-card-header">
						<h2><?php esc_html_e( 'Security', 'remotewp' ); ?></h2>
					</div>
					<div class="remotewp-card-body">
						<div class="remotewp-field">
							<label for="remotewp_rate_limit"><?php esc_html_e( 'Rate Limit (requests per minute)', 'remotewp' ); ?></label>
							<input type="number" name="remotewp_rate_limit" id="remotewp_rate_limit"
							       value="<?php echo esc_attr( $settings['rate_limit'] ); ?>"
							       min="0" max="1000" class="small-text">
							<p class="description"><?php esc_html_e( 'Set to 0 to disable rate limiting.', 'remotewp' ); ?></p>
						</div>

						<div class="remotewp-field">
							<label for="remotewp_lockout_threshold"><?php esc_html_e( 'Lockout after failed attempts', 'remotewp' ); ?></label>
							<input type="number" name="remotewp_lockout_threshold" id="remotewp_lockout_threshold"
							       value="<?php echo esc_attr( $settings['lockout_threshold'] ); ?>"
							       min="1" max="50" class="small-text">
						</div>

						<div class="remotewp-field">
							<label for="remotewp_lockout_duration"><?php esc_html_e( 'Lockout duration (minutes)', 'remotewp' ); ?></label>
							<input type="number" name="remotewp_lockout_duration" id="remotewp_lockout_duration"
							       value="<?php echo esc_attr( $settings['lockout_duration'] ); ?>"
							       min="1" max="1440" class="small-text">
						</div>

						<div class="remotewp-field">
							<label for="remotewp_ip_whitelist"><?php esc_html_e( 'IP Whitelist', 'remotewp' ); ?></label>
							<textarea name="remotewp_ip_whitelist" id="remotewp_ip_whitelist" rows="4"
							          class="large-text code" placeholder="<?php esc_attr_e( 'Leave empty to allow all IPs.', 'remotewp' ); ?>&#10;192.168.1.0/24&#10;10.0.0.1"
							><?php echo esc_textarea( $settings['ip_whitelist'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'One IP per line. Supports CIDR notation (e.g., 192.168.1.0/24). Leave empty to allow all IPs.', 'remotewp' ); ?>
							</p>
						</div>

						<div class="remotewp-field">
							<label>
								<input type="checkbox" name="remotewp_trust_proxy" value="1"
								       <?php checked( $settings['trust_proxy'] ); ?>>
								<?php esc_html_e( 'Behind Reverse Proxy (Cloudflare, Nginx, etc.)', 'remotewp' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Enable if your server is behind a reverse proxy. When disabled, only REMOTE_ADDR is trusted for IP detection, preventing IP spoofing via forwarded headers.', 'remotewp' ); ?>
							</p>
						</div>

						<div class="remotewp-field">
							<label for="remotewp_token_ttl"><?php esc_html_e( 'Token Lifetime (Auto-Expiry)', 'remotewp' ); ?></label>
							<?php $current_ttl = (int) get_option( 'remotewp_token_ttl', 0 ); ?>
							<select name="remotewp_token_ttl" id="remotewp_token_ttl" class="regular-text">
								<option value="0" <?php selected( $current_ttl, 0 ); ?>><?php esc_html_e( 'Never expire (permanent token)', 'remotewp' ); ?></option>
								<option value="21600" <?php selected( $current_ttl, 21600 ); ?>><?php esc_html_e( '6 hours', 'remotewp' ); ?></option>
								<option value="43200" <?php selected( $current_ttl, 43200 ); ?>><?php esc_html_e( '12 hours', 'remotewp' ); ?></option>
								<option value="86400" <?php selected( $current_ttl, 86400 ); ?>><?php esc_html_e( '24 hours (recommended)', 'remotewp' ); ?></option>
								<option value="172800" <?php selected( $current_ttl, 172800 ); ?>><?php esc_html_e( '48 hours', 'remotewp' ); ?></option>
								<option value="604800" <?php selected( $current_ttl, 604800 ); ?>><?php esc_html_e( '7 days', 'remotewp' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'When enabled, API tokens automatically expire after the selected duration. You must regenerate the token from the API Access tab before sharing it with an AI agent. This prevents leaked tokens from being used indefinitely.', 'remotewp' ); ?>
							</p>
						</div>

						<div class="remotewp-field">
							<label for="remotewp_redaction_extra_keys"><?php esc_html_e( 'Additional Redaction Keys', 'remotewp' ); ?></label>
							<textarea name="remotewp_redaction_extra_keys" id="remotewp_redaction_extra_keys" rows="3"
							          class="large-text code" placeholder="license_key&#10;webhook_secret"
							><?php echo esc_textarea( $settings['redaction_extra_keys'] ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Optional key names to redact from API reads and audit logs. One key per line. The standard secret patterns remain active and cannot be disabled here.', 'remotewp' ); ?>
							</p>
						</div>

						<div class="remotewp-field">
							<label for="remotewp_backup_retention_days"><?php esc_html_e( 'Backup Retention Review (days)', 'remotewp' ); ?></label>
							<input type="number" name="remotewp_backup_retention_days" id="remotewp_backup_retention_days"
							       value="<?php echo esc_attr( get_option( 'remotewp_backup_retention_days', 0 ) ); ?>"
							       min="0" max="3650" class="small-text">
							<p class="description">
								<?php esc_html_e( '0 keeps backups eligible by age disabled. This setting only marks candidates for review; it never deletes backups automatically.', 'remotewp' ); ?>
							</p>
						</div>

						<div class="remotewp-field">
							<label for="remotewp_backup_max_records"><?php esc_html_e( 'Maximum Backup Records for Review', 'remotewp' ); ?></label>
							<input type="number" name="remotewp_backup_max_records" id="remotewp_backup_max_records"
							       value="<?php echo esc_attr( get_option( 'remotewp_backup_max_records', 0 ) ); ?>"
							       min="0" max="100000" class="small-text">
							<p class="description">
								<?php esc_html_e( '0 disables count-based review. Older records beyond this limit are reported in v2 health, but are not removed automatically.', 'remotewp' ); ?>
							</p>
						</div>
					</div>
				</div>
			</div>

			<!-- Active Lockouts Card -->
			<?php
			$locked_ips = $this->get_locked_out_ips();
			?>
			<div class="remotewp-card" style="margin-top: 16px;">
				<div class="remotewp-card-header" style="background: linear-gradient(135deg, rgba(239, 68, 68, 0.08), rgba(239, 68, 68, 0.02));">
					<h2>
						<span class="dashicons dashicons-shield" style="margin-right:6px; color: #ef4444;"></span>
						<?php esc_html_e( 'Active Lockouts', 'remotewp' ); ?>
						<?php if ( ! empty( $locked_ips ) ) : ?>
							<span style="background:#ef4444; color:#fff; font-size:12px; padding:2px 8px; border-radius:10px; margin-left:8px;">
								<?php echo count( $locked_ips ); ?>
							</span>
						<?php endif; ?>
					</h2>
				</div>
				<div class="remotewp-card-body">
					<?php if ( empty( $locked_ips ) ) : ?>
						<p style="color:#6b7280; font-style:italic;">
							<?php esc_html_e( 'No IPs are currently locked out.', 'remotewp' ); ?>
						</p>
					<?php else : ?>
						<table class="widefat striped" style="margin-bottom:12px;">
							<thead>
								<tr>
									<th><?php esc_html_e( 'IP Address', 'remotewp' ); ?></th>
									<th><?php esc_html_e( 'Expires', 'remotewp' ); ?></th>
									<th style="width:100px;"><?php esc_html_e( 'Action', 'remotewp' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $locked_ips as $entry ) : ?>
									<tr>
										<td><code><?php echo esc_html( $entry['ip'] ); ?></code></td>
										<td><?php echo esc_html( $entry['expires'] ); ?></td>
										<td>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
												<input type="hidden" name="action" value="remotewp_unban_ip">
												<?php wp_nonce_field( 'remotewp_unban_ip' ); ?>
												<input type="hidden" name="ip" value="<?php echo esc_attr( $entry['ip'] ); ?>">
												<button type="submit" class="button button-small" style="color:#ef4444; border-color:#ef4444;">
													<?php esc_html_e( 'Unban', 'remotewp' ); ?>
												</button>
											</form>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="remotewp_unban_all">
							<?php wp_nonce_field( 'remotewp_unban_all' ); ?>
							<button type="submit" class="button" style="color:#ef4444; border-color:#ef4444;">
								<?php esc_html_e( 'Unban All', 'remotewp' ); ?>
							</button>
						</form>
					<?php endif; ?>
				</div>
			</div>

			<p class="submit">
				<button type="submit" class="button button-primary button-hero">
					<?php esc_html_e( 'Save Settings', 'remotewp' ); ?>
				</button>
			</p>
		</form>

		<?php // Owner Access — Master Password (Full and Pro builds) ?>
		<?php if ( ( defined( 'REMOTEWP_IS_FULL' ) && REMOTEWP_IS_FULL ) || ( defined( 'REMOTEWP_IS_PRO' ) && REMOTEWP_IS_PRO ) ) : ?>
		<?php
		$backup_review = RemoteWP_Operation_Safety::get_backup_review(
			$this->logger->get_backup_dir(),
			(int) get_option( 'remotewp_backup_retention_days', 0 ),
			(int) get_option( 'remotewp_backup_max_records', 0 ),
			50
		);
		?>
		<div class="remotewp-card" style="margin-top: 28px;">
			<div class="remotewp-card-header">
				<h2><?php esc_html_e( 'Backup Cleanup Review', 'remotewp' ); ?></h2>
			</div>
			<div class="remotewp-card-body">
				<p class="description">
					<?php esc_html_e( 'Only verified, manifest-backed backups that match the current retention rules appear here. Cleanup is manual, irreversible, and never runs automatically.', 'remotewp' ); ?>
				</p>
				<?php if ( empty( $backup_review['records'] ) ) : ?>
					<p style="color:#6b7280; font-style:italic;"><?php esc_html_e( 'No eligible backups require review.', 'remotewp' ); ?></p>
				<?php else : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="remotewp_cleanup_backups">
						<?php wp_nonce_field( 'remotewp_cleanup_backups' ); ?>
						<table class="widefat striped" style="margin-bottom:12px;">
							<thead>
								<tr>
									<th style="width:36px;"></th>
									<th><?php esc_html_e( 'Backup ID', 'remotewp' ); ?></th>
									<th><?php esc_html_e( 'Operation', 'remotewp' ); ?></th>
									<th><?php esc_html_e( 'Created', 'remotewp' ); ?></th>
									<th><?php esc_html_e( 'Reason', 'remotewp' ); ?></th>
									<th><?php esc_html_e( 'Bytes', 'remotewp' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $backup_review['records'] as $record ) : ?>
									<?php if ( empty( $record['backup_id'] ) ) { continue; } ?>
									<tr>
										<td><input type="checkbox" name="backup_ids[]" value="<?php echo esc_attr( $record['backup_id'] ); ?>"></td>
										<td><code><?php echo esc_html( $record['backup_id'] ); ?></code></td>
										<td><?php echo esc_html( $record['operation'] ); ?></td>
										<td><?php echo esc_html( $record['created_at'] ); ?></td>
										<td><?php echo esc_html( implode( ', ', $record['eligibility_reasons'] ) ); ?></td>
										<td><?php echo esc_html( number_format_i18n( $record['bytes'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<label style="display:block; margin:12px 0; color:#b91c1c;">
							<input type="checkbox" name="cleanup_confirm" value="1">
							<?php esc_html_e( 'I understand that the selected verified backups cannot be restored after cleanup.', 'remotewp' ); ?>
						</label>
						<button type="submit" class="button" style="color:#b91c1c; border-color:#b91c1c;">
							<?php esc_html_e( 'Clean Selected Backups', 'remotewp' ); ?>
						</button>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<?php // Owner Access ?>
		<div class="remotewp-card" style="margin-top: 28px;">
			<div class="remotewp-card-header" style="background: linear-gradient(135deg, rgba(239, 68, 68, 0.08), rgba(239, 68, 68, 0.02));">
				<h2><span class="dashicons dashicons-shield-alt" style="margin-right:6px; color: #ef4444;"></span><?php esc_html_e( 'Owner Access Lock', 'remotewp' ); ?></h2>
			</div>
			<div class="remotewp-card-body">
				<p style="color: #9ca9be; font-size: 13px; margin: 0 0 16px; line-height: 1.7;">
					<?php esc_html_e( 'Set a master password to lock the RemoteWP admin interface. When locked, nobody can see the dashboard, tokens, or settings without entering the correct password. The REST API continues to work independently.', 'remotewp' ); ?>
				</p>

				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="remotewp_set_master_password">
					<?php wp_nonce_field( 'remotewp_set_master_password' ); ?>

					<div class="remotewp-field">
						<label for="remotewp_master_pw"><?php esc_html_e( 'Master Password', 'remotewp' ); ?></label>
						<input type="password" name="remotewp_master_pw" id="remotewp_master_pw" value=""
						       placeholder="<?php echo $this->has_master_password() ? esc_attr__( '••••••••••• (password set — leave empty to keep current)', 'remotewp' ) : esc_attr__( 'Enter a master password to enable lock', 'remotewp' ); ?>"
						       class="large-text" autocomplete="new-password">
						<p class="description">
							<?php if ( $this->has_master_password() ) : ?>
								<span style="color: #22c55e;">●</span> <?php esc_html_e( 'Lock is ACTIVE. Leave empty to keep current password, or enter a new one to change it.', 'remotewp' ); ?>
							<?php else : ?>
								<span style="color: #64748b;">●</span> <?php esc_html_e( 'Lock is DISABLED. Enter a password to enable the owner lock screen.', 'remotewp' ); ?>
							<?php endif; ?>
						</p>
					</div>

					<?php if ( $this->has_master_password() ) : ?>
					<div class="remotewp-field">
						<label>
							<input type="checkbox" name="remotewp_remove_master_pw" value="1">
							<?php esc_html_e( 'Remove master password (disable lock screen)', 'remotewp' ); ?>
						</label>
					</div>
					<?php endif; ?>

					<button type="submit" class="button button-primary">
						<?php echo $this->has_master_password() ? esc_html__( 'Update Password', 'remotewp' ) : esc_html__( 'Set Master Password', 'remotewp' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php endif; ?>

		<?php
	}

	/**
	 * Get list of currently locked out IPs from transients.
	 *
	 * @return array Array of [ 'ip' => string, 'expires' => string ].
	 */
	private function get_locked_out_ips() {
		global $wpdb;

		$locked = array();
		$prefix = '_transient_remotewp_lockout_';
		$timeout_prefix = '_transient_timeout_remotewp_lockout_';

		// Query transients that match lockout pattern
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.option_name, t.option_value as timeout_value
				 FROM {$wpdb->options} o
				 LEFT JOIN {$wpdb->options} t ON t.option_name = CONCAT('_transient_timeout_', SUBSTRING(o.option_name, 13))
				 WHERE o.option_name LIKE %s
				 AND o.option_value = '1'",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);

		if ( ! $results ) {
			return $locked;
		}

		// We need a reverse lookup — transient names use md5 hashes.
		// Store the IP in a companion transient for display purposes.
		$ip_map = get_option( 'remotewp_lockout_ip_map', array() );

		foreach ( $results as $row ) {
			$hash = str_replace( $prefix, '', $row->option_name );
			$ip = isset( $ip_map[ $hash ] ) ? $ip_map[ $hash ] : $hash;
			$expires = ! empty( $row->timeout_value ) ? human_time_diff( time(), (int) $row->timeout_value ) : '?';

			if ( ! empty( $row->timeout_value ) && (int) $row->timeout_value < time() ) {
				continue; // Already expired
			}

			$locked[] = array(
				'ip'      => $ip,
				'hash'    => $hash,
				'expires' => sprintf( __( 'in %s', 'remotewp' ), $expires ),
			);
		}

		return $locked;
	}

	/**
	 * Handle unbanning a single IP.
	 */
	public function handle_unban_ip() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'remotewp' ) );
		}
		check_admin_referer( 'remotewp_unban_ip' );

		$ip = sanitize_text_field( wp_unslash( $_POST['ip'] ?? '' ) );
		if ( ! empty( $ip ) ) {
			delete_transient( 'remotewp_lockout_' . md5( $ip ) );
			delete_transient( 'remotewp_fails_' . md5( $ip ) );

			// Also try direct hash (in case IP display is already the hash)
			delete_transient( 'remotewp_lockout_' . $ip );
			delete_transient( 'remotewp_fails_' . $ip );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'remotewp', 'tab' => 'settings', 'unbanned' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle unbanning all IPs.
	 */
	public function handle_unban_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission.', 'remotewp' ) );
		}
		check_admin_referer( 'remotewp_unban_all' );

		global $wpdb;

		// Delete all lockout transients
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_remotewp_lockout_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_remotewp_lockout_' ) . '%'
			)
		);

		// Also clear failure counters
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_remotewp_fails_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_remotewp_fails_' ) . '%'
			)
		);

		wp_safe_redirect( add_query_arg( array( 'page' => 'remotewp', 'tab' => 'settings', 'unbanned' => 'all' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handle saving settings.
	 */
	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'remotewp' ) );
		}

		check_admin_referer( 'remotewp_save_settings' );

		$fields = array(
			'remotewp_rate_limit'        => 'absint',
			'remotewp_lockout_threshold' => 'absint',
			'remotewp_lockout_duration'  => 'absint',
			'remotewp_ip_whitelist'      => 'sanitize_textarea_field',
			'remotewp_token_ttl'         => 'absint',
			'remotewp_redaction_extra_keys' => 'sanitize_textarea_field',
			'remotewp_backup_retention_days' => 'absint',
			'remotewp_backup_max_records' => 'absint',
		);

		foreach ( $fields as $key => $sanitizer ) {
			if ( isset( $_POST[ $key ] ) ) {
				update_option( $key, call_user_func( $sanitizer, wp_unslash( $_POST[ $key ] ) ) );
			}
		}

		// Checkbox: trust_proxy (unchecked = not in POST)
		update_option( 'remotewp_trust_proxy', isset( $_POST['remotewp_trust_proxy'] ) ? 1 : 0 );

		$this->logger->log( 'SETTINGS_UPDATED', '', 'Settings saved via admin panel' );

		wp_redirect( admin_url( 'admin.php?page=remotewp&tab=settings&updated=true' ) );
		exit;
	}

	/**
	 * Handle an explicitly approved manual cleanup of eligible backups.
	 */
	public function handle_cleanup_backups() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'remotewp' ) );
		}

		check_admin_referer( 'remotewp_cleanup_backups' );
		$ids = isset( $_POST['backup_ids'] ) && is_array( $_POST['backup_ids'] ) ? array_slice( array_map( 'sanitize_text_field', wp_unslash( $_POST['backup_ids'] ) ), 0, 50 ) : array();
		if ( empty( $ids ) || empty( $_POST['cleanup_confirm'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=remotewp&tab=settings&backup_cleanup=none' ) );
			exit;
		}

		$backup_dir = $this->logger->get_backup_dir();
		$retention_days = (int) get_option( 'remotewp_backup_retention_days', 0 );
		$max_records = (int) get_option( 'remotewp_backup_max_records', 0 );
		$cleaned = 0;
		$failed = 0;
		foreach ( array_unique( $ids ) as $backup_id ) {
			$result = RemoteWP_Operation_Safety::purge_eligible_backup( $backup_dir, $backup_id, $retention_days, $max_records );
			if ( is_wp_error( $result ) ) {
				$failed++;
				$this->logger->log( 'BACKUP_CLEANUP', $backup_id, 'Manual cleanup rejected: ' . $result->get_error_code(), 'error' );
				continue;
			}
			$cleaned++;
			$this->logger->log( 'BACKUP_CLEANUP', $backup_id, 'Manual cleanup approved and completed' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'remotewp',
					'tab' => 'settings',
					'backup_cleanup' => 'done',
					'cleaned' => $cleaned,
					'failed' => $failed,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Handle token regeneration.
	 */
	public function handle_regenerate_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'remotewp' ) );
		}

		check_admin_referer( 'remotewp_regenerate_token' );

		$this->auth->regenerate_token();

		wp_redirect( admin_url( 'admin.php?page=remotewp&tab=dashboard&token_regenerated=true' ) );
		exit;
	}

	/**
	 * Handle license activation.
	 */
	public function handle_activate_license() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'remotewp' ) );
		}

		check_admin_referer( 'remotewp_activate_license' );

		$key    = isset( $_POST['license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['license_key'] ) ) : '';
		$result = $this->license->activate( $key );

		if ( is_wp_error( $result ) ) {
			wp_redirect( admin_url( 'admin.php?page=remotewp&tab=license&license_error=' . urlencode( $result->get_error_message() ) ) );
		} else {
			$this->logger->log( 'LICENSE_ACTIVATED', '', 'Tier: ' . ( $result['tier'] ?? 'unknown' ) );
			wp_redirect( admin_url( 'admin.php?page=remotewp&tab=license&license_activated=true' ) );
		}
		exit;
	}

	/**
	 * Handle license deactivation.
	 */
	public function handle_deactivate_license() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'remotewp' ) );
		}

		check_admin_referer( 'remotewp_deactivate_license' );

		$this->license->deactivate();
		$this->logger->log( 'LICENSE_DEACTIVATED', '', 'License deactivated' );

		wp_redirect( admin_url( 'admin.php?page=remotewp&tab=license&license_deactivated=true' ) );
		exit;
	}

	/**
	 * Render the License tab.
	 */
	private function render_license_tab() {
		$info = $this->license->get_info();
		?>
		<div class="remotewp-grid">
			<!-- License Status Card -->
			<div class="remotewp-card remotewp-card-wide">
				<div class="remotewp-card-header">
					<h2><?php esc_html_e( 'License Status', 'remotewp' ); ?></h2>
				</div>
				<div class="remotewp-card-body">
					<table class="remotewp-info-table">
						<tr>
							<th><?php esc_html_e( 'Current Plan', 'remotewp' ); ?></th>
							<td>
								<span class="remotewp-badge remotewp-badge-<?php echo esc_attr( $info['tier'] ); ?>">
									<?php echo esc_html( $info['tier_label'] ); ?>
								</span>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Status', 'remotewp' ); ?></th>
							<td>
								<?php 
								$is_full  = defined( 'REMOTEWP_IS_FULL' ) && REMOTEWP_IS_FULL;
								$tier     = $this->license->get_tier();
								$is_trial = ! empty( $info['is_trial'] ) || ( method_exists( $this->license, 'is_trial_active' ) && $this->license->is_trial_active() );

								if ( $is_full ) : ?>
									<span class="remotewp-status-success">● <?php esc_html_e( 'Active (Full Admin)', 'remotewp' ); ?></span>
								<?php elseif ( $is_trial ) : ?>
									<span class="remotewp-status-success">● <?php esc_html_e( 'Active (PRO Trial 48h)', 'remotewp' ); ?></span>
								<?php elseif ( 'free' !== $tier ) : ?>
									<span class="remotewp-status-success">● <?php esc_html_e( 'Active (Pro)', 'remotewp' ); ?></span>
								<?php else : ?>
									<?php if ( ! empty( $info['key'] ) ) : ?>
										<span class="remotewp-status-success">● <?php esc_html_e( 'Active (Free)', 'remotewp' ); ?></span>
									<?php else : ?>
										<span class="remotewp-status-neutral" style="color: #64748b; font-weight: 600;">● <?php esc_html_e( 'Free Mode (No License)', 'remotewp' ); ?></span>
									<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
						<?php if ( ! empty( $info['key'] ) ) : ?>
						<tr>
							<th><?php esc_html_e( 'License Key', 'remotewp' ); ?></th>
							<td><code><?php echo esc_html( $info['key'] ); ?></code></td>
						</tr>
						<?php endif; ?>
						<?php 
						$expiry_val = ! empty( $info['expires'] ) ? $info['expires'] : ( ! empty( $info['trial_expires'] ) ? $info['trial_expires'] : '' );
						if ( ! empty( $expiry_val ) && 'lifetime' !== $info['tier'] ) : ?>
						<tr>
							<th><?php esc_html_e( 'Expires', 'remotewp' ); ?></th>
							<td><?php echo esc_html( $expiry_val ); ?></td>
						</tr>
						<?php endif; ?>

					</table>

					<?php if ( apply_filters( 'remotewp_is_pro_build', false ) && ! ( defined( 'REMOTEWP_IS_FULL' ) && REMOTEWP_IS_FULL ) ) : ?>
						<div style="margin-top: 16px;">
							<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" class="remotewp-inline-form">
								<input type="hidden" name="action" value="remotewp_deactivate_license">
								<?php wp_nonce_field( 'remotewp_deactivate_license' ); ?>
								<button type="submit" class="button remotewp-btn-danger">
									<?php esc_html_e( 'Deactivate License', 'remotewp' ); ?>
								</button>
							</form>
						</div>
					<?php elseif ( defined( 'REMOTEWP_IS_FULL' ) && REMOTEWP_IS_FULL ) : ?>
						<div style="margin-top: 16px; color: #5a657a; font-size: 13px; font-style: italic;">
							<?php esc_html_e( 'License is pre-activated and managed by X-HOUSE SRL.', 'remotewp' ); ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<?php 
			$is_full = defined( 'REMOTEWP_IS_FULL' ) && REMOTEWP_IS_FULL;
			$tier = $this->license->get_tier();
			if ( ! $is_full && 'free' === $tier ) : 
			?>
			<!-- Activate License Card -->
			<div class="remotewp-card">
				<div class="remotewp-card-header">
					<h2><?php esc_html_e( 'Activate License', 'remotewp' ); ?></h2>
				</div>
				<div class="remotewp-card-body">
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="remotewp_activate_license">
						<?php wp_nonce_field( 'remotewp_activate_license' ); ?>
						<div class="remotewp-field">
							<label for="license_key"><?php esc_html_e( 'License Key', 'remotewp' ); ?></label>
							<input type="text" name="license_key" id="license_key" value=""
							       placeholder="RWPRO-XXXX-XXXX-XXXX-XXXX" class="large-text remotewp-input-mono">
						</div>
						<button type="submit" class="button button-primary">
							<?php esc_html_e( 'Activate', 'remotewp' ); ?>
						</button>
					</form>
				</div>
			</div>

			<!-- Upgrade Grid Premium -->
			<div class="rwp-pricing-container">
				<div class="rwp-pricing-header">
					<h3><?php esc_html_e( 'Choose the RemoteWP PRO plan right for your business', 'remotewp' ); ?></h3>
					<p><?php esc_html_e( 'Unlock full access to all advanced features and empower your AI agent with complete read, write, WooCommerce, and SEO optimization capabilities.', 'remotewp' ); ?></p>
				</div>
				<div class="rwp-pricing-grid">
					<!-- Developer Plan -->
					<div class="rwp-pricing-card">
						<div class="rwp-pcard-header">
							<h4 class="rwp-pcard-title"><?php esc_html_e( 'DEVELOPER', 'remotewp' ); ?></h4>
							<div class="rwp-pcard-price"><strong>$79</strong><span>/ <?php esc_html_e( 'year', 'remotewp' ); ?></span></div>
							<span class="rwp-pcard-limits"><?php esc_html_e( 'Up to 10 WordPress sites', 'remotewp' ); ?></span>
						</div>
						<div class="rwp-pcard-body">
							<ul class="rwp-pcard-features">
								<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'All PRO endpoints unlocked', 'remotewp' ); ?></li>
								<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Unlimited file read & write', 'remotewp' ); ?></li>
								<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( '12 months priority support', 'remotewp' ); ?></li>
								<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Security updates included', 'remotewp' ); ?></li>
							</ul>
							<a href="https://buy.stripe.com/dRmbJ29ZR0oFbfvcK4dIA00" target="_blank" class="button button-secondary rwp-pcard-btn">
								<?php esc_html_e( 'Buy Now', 'remotewp' ); ?>
							</a>
						</div>
					</div>

					<!-- Agency Plan -->
					<div class="rwp-pricing-card rwp-pricing-featured">
						<div class="rwp-pcard-featured-badge"><?php esc_html_e( 'RECOMMENDED', 'remotewp' ); ?></div>
						<div class="rwp-pcard-header">
							<h4 class="rwp-pcard-title"><?php esc_html_e( 'AGENCY', 'remotewp' ); ?></h4>
							<div class="rwp-pcard-price"><strong>$149</strong><span>/ <?php esc_html_e( 'year', 'remotewp' ); ?></span></div>
							<span class="rwp-pcard-limits"><?php esc_html_e( 'UNLIMITED WordPress sites', 'remotewp' ); ?></span>
						</div>
						<div class="rwp-pcard-body">
							<ul class="rwp-pcard-features">
								<li><span class="dashicons dashicons-yes"></span> <strong><?php esc_html_e( 'Unlimited number of domains', 'remotewp' ); ?></strong></li>
								<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Full API & AI bridge access', 'remotewp' ); ?></li>
								<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( '24/7 Premium support', 'remotewp' ); ?></li>
								<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Ideal for agencies & portfolio owners', 'remotewp' ); ?></li>
							</ul>
							<a href="https://buy.stripe.com/bJecN6b3V8Vb5VbcK4dIA01" target="_blank" class="button button-primary rwp-pcard-btn">
								<?php esc_html_e( 'Buy Now', 'remotewp' ); ?>
							</a>
						</div>
					</div>

					<!-- Lifetime Plan -->
					<div class="rwp-pricing-card">
						<div class="rwp-pcard-header">
							<h4 class="rwp-pcard-title"><?php esc_html_e( 'LIFETIME', 'remotewp' ); ?></h4>
							<div class="rwp-pcard-price"><strong>$349</strong><span>/ <?php esc_html_e( 'one-time payment', 'remotewp' ); ?></span></div>
							<span class="rwp-pcard-limits"><?php esc_html_e( 'Lifetime unlimited, no subscription', 'remotewp' ); ?></span>
						</div>
						<div class="rwp-pcard-body">
							<ul class="rwp-pcard-features">
								<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Lifetime access, no monthly fees', 'remotewp' ); ?></li>
								<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Lifetime updates included', 'remotewp' ); ?></li>
								<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Lifetime priority support', 'remotewp' ); ?></li>
								<li><span class="dashicons dashicons-yes"></span> <?php esc_html_e( 'Best value investment', 'remotewp' ); ?></li>
							</ul>
							<a href="https://buy.stripe.com/aFa5kE1tl3AR3N37pKdIA02" target="_blank" class="button button-secondary rwp-pcard-btn">
								<?php esc_html_e( 'Get Lifetime Access', 'remotewp' ); ?>
							</a>
						</div>
					</div>
				</div>
			</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render admin notices.
	 */
	private function render_notices() {
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="remotewp-notice remotewp-notice-success"><p>' . esc_html__( 'Settings saved successfully.', 'remotewp' ) . '</p></div>';
		}

		if ( isset( $_GET['backup_cleanup'] ) ) {
			$cleanup_state = sanitize_key( wp_unslash( $_GET['backup_cleanup'] ) );
			if ( 'done' === $cleanup_state ) {
				$cleaned = isset( $_GET['cleaned'] ) ? absint( $_GET['cleaned'] ) : 0;
				$failed = isset( $_GET['failed'] ) ? absint( $_GET['failed'] ) : 0;
				$class = $failed > 0 ? 'warning' : 'success';
				echo '<div class="remotewp-notice remotewp-notice-' . esc_attr( $class ) . '"><p>' . esc_html( sprintf( __( 'Backup cleanup completed: %1$d removed, %2$d rejected.', 'remotewp' ), $cleaned, $failed ) ) . '</p></div>';
			} elseif ( 'none' === $cleanup_state ) {
				echo '<div class="remotewp-notice remotewp-notice-warning"><p>' . esc_html__( 'Select at least one backup and confirm the irreversible cleanup action.', 'remotewp' ) . '</p></div>';
			}
		}

		if ( isset( $_GET['token_regenerated'] ) ) {
			echo '<div class="remotewp-notice remotewp-notice-warning"><p>' . esc_html__( 'API token regenerated. Update the token in all connected agents.', 'remotewp' ) . '</p></div>';
		}

		if ( isset( $_GET['license_activated'] ) ) {
			echo '<div class="remotewp-notice remotewp-notice-success"><p>' . esc_html__( 'License activated successfully! All Pro features are now unlocked.', 'remotewp' ) . '</p></div>';
		}

		if ( isset( $_GET['license_deactivated'] ) ) {
			echo '<div class="remotewp-notice remotewp-notice-warning"><p>' . esc_html__( 'License deactivated. This site is now on the free tier.', 'remotewp' ) . '</p></div>';
		}

		if ( isset( $_GET['license_error'] ) ) {
			echo '<div class="remotewp-notice remotewp-notice-warning"><p>' . esc_html( wp_unslash( $_GET['license_error'] ) ) . '</p></div>';
		}

		// Owner Lock notices
		if ( isset( $_GET['master_pw_set'] ) ) {
			echo '<div class="remotewp-notice remotewp-notice-success"><p>' . esc_html__( 'Master password has been set. The lock screen is now active.', 'remotewp' ) . '</p></div>';
		}

		if ( isset( $_GET['master_pw_updated'] ) ) {
			echo '<div class="remotewp-notice remotewp-notice-success"><p>' . esc_html__( 'Master password updated successfully.', 'remotewp' ) . '</p></div>';
		}

		if ( isset( $_GET['master_pw_removed'] ) ) {
			echo '<div class="remotewp-notice remotewp-notice-warning"><p>' . esc_html__( 'Master password removed. The lock screen is now disabled.', 'remotewp' ) . '</p></div>';
		}

		if ( isset( $_GET['unlock_error'] ) ) {
			echo '<div class="remotewp-notice remotewp-notice-warning"><p>' . esc_html__( 'Incorrect master password. Please try again.', 'remotewp' ) . '</p></div>';
		}
	}

	/**
	 * Get current settings.
	 *
	 * @return array
	 */
	private function get_settings() {
		return array(
			'rate_limit'        => get_option( 'remotewp_rate_limit', 60 ),
			'lockout_threshold' => get_option( 'remotewp_lockout_threshold', 5 ),
			'lockout_duration'  => get_option( 'remotewp_lockout_duration', 15 ),
			'ip_whitelist'      => get_option( 'remotewp_ip_whitelist', '' ),
			'trust_proxy'       => get_option( 'remotewp_trust_proxy', false ),
			'redaction_extra_keys' => get_option( 'remotewp_redaction_extra_keys', '' ),
		);
	}

	/**
	 * Render the Docs & Support tab.
	 */
	private function render_docs_tab() {
		$is_pro      = apply_filters( 'remotewp_is_pro_build', false );
		$token       = get_option( 'remotewp_api_token', '' );
		$skill_url   = rest_url( REMOTEWP_API_V2_NAMESPACE . '/skill' );
		$status_url  = rest_url( REMOTEWP_API_NAMESPACE . '/status' );
		$curl_sample = sprintf(
			"curl -X GET \"%s\" \\\n  -H \"X-RemoteWP-Token: %s\" \\\n  -H \"Content-Type: application/json\"",
			$status_url,
			$token ? $token : 'YOUR_SECURE_API_TOKEN'
		);
		?>
		<div class="rwp-docs-grid">
			<!-- Left Column: Documentation -->
			<div class="remotewp-card rwp-docs-main-card">
				<div class="remotewp-card-header">
					<h2><?php esc_html_e( 'Integration Documentation', 'remotewp' ); ?></h2>
					<p class="rwp-card-header-subtitle"><?php esc_html_e( 'REST API specifications and integration guides for AI agents.', 'remotewp' ); ?></p>
				</div>
				<div class="remotewp-card-body">
					<div class="rwp-docs-section rwp-docs-capabilities">
						<h3 class="rwp-docs-h3"><?php esc_html_e( 'Capabilities & controls', 'remotewp' ); ?></h3>
						<p class="rwp-docs-text"><?php esc_html_e( 'The Overview stays focused on connection and onboarding. Use this section for the full capability reference.', 'remotewp' ); ?></p>
						<div class="rwp-docs-capability-list">
							<div><strong><?php esc_html_e( 'Automation', 'remotewp' ); ?></strong><span><?php esc_html_e( 'Articles, WooCommerce, SEO, CSS and layout fixes.', 'remotewp' ); ?></span></div>
							<div><strong><?php esc_html_e( 'Security', 'remotewp' ); ?></strong><span><?php esc_html_e( 'Token authentication, permission profiles and protected operations.', 'remotewp' ); ?></span></div>
							<div><strong><?php esc_html_e( 'Monitoring', 'remotewp' ); ?></strong><span><?php esc_html_e( 'Activity history, response status and auditable execution.', 'remotewp' ); ?></span></div>
							<div><strong><?php esc_html_e( 'Workflow', 'remotewp' ); ?></strong><span><?php esc_html_e( 'Structured AI tasks with backups and controlled API behavior.', 'remotewp' ); ?></span></div>
						</div>
					</div>

					<hr class="rwp-docs-divider">

					<!-- Dynamic Instructions Card -->
					<div class="rwp-docs-section">
						<h3 class="rwp-docs-h3"><?php esc_html_e( 'AI Agent Skill Endpoint (/skill)', 'remotewp' ); ?></h3>
						<p class="rwp-docs-text">
							<?php esc_html_e( 'RemoteWP exposes an automated AI Agent Skill specification compliant with the WordPress Agent Skills standard at /wp-json/remotewp/v2/skill. When an AI agent connects (Claude, Cursor, ChatGPT, Copilot, etc.), it reads this endpoint to discover all API methods, file operations, WooCommerce actions, and security rules.', 'remotewp' ); ?>
						</p>
						<div class="rwp-quickstart-actions" style="margin-top: 16px; padding-top: 0; border-top: none; display: flex; gap: 10px; flex-wrap: wrap;">
							<a href="<?php echo esc_url( $skill_url ); ?>" target="_blank" class="button button-primary">
								⚡ <?php esc_html_e( 'Preview Skill Endpoint', 'remotewp' ); ?>
							</a>
							<a href="<?php echo esc_url( $skill_url ); ?>" target="_blank" rel="noopener" class="button button-secondary">
								<?php esc_html_e( 'Open central SKILL.md', 'remotewp' ); ?>
							</a>
							<button type="button" class="button button-secondary remotewp-btn-copy" data-target="rwp-docs-skill-url">
								<?php esc_html_e( 'Copy Skill URL', 'remotewp' ); ?>
							</button>
							<span id="rwp-docs-skill-url" style="display:none;"><?php echo esc_url( $skill_url ); ?></span>
						</div>
					</div>

					<hr class="rwp-docs-divider">

					<!-- API Integration Guide -->
					<div class="rwp-docs-section" style="margin-top: 24px;">
						<h3 class="rwp-docs-h3"><?php esc_html_e( 'Authentication & API Headers', 'remotewp' ); ?></h3>
						<p class="rwp-docs-text">
							<?php esc_html_e( 'All REST requests made to RemoteWP must be authenticated. The connected agent must provide the API token in a custom header on every request:', 'remotewp' ); ?>
						</p>
						<pre class="rwp-docs-code"><code>X-RemoteWP-Token: <?php echo esc_html( $token ? $token : 'YOUR_SECURE_API_TOKEN' ); ?></code></pre>
					</div>

					<hr class="rwp-docs-divider">

					<!-- Sample Request Code -->
					<div class="rwp-docs-section" style="margin-top: 24px;">
						<div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 8px;">
							<h3 class="rwp-docs-h3" style="margin: 0;"><?php esc_html_e( 'Sample Connection Check (cURL)', 'remotewp' ); ?></h3>
							<button type="button" class="button button-secondary remotewp-btn-copy" data-target="rwp-curl-code" style="font-size: 12px; padding: 2px 10px;">
								<?php esc_html_e( 'Copy cURL', 'remotewp' ); ?>
							</button>
						</div>
						<p class="rwp-docs-text">
							<?php esc_html_e( 'You or your AI agent can verify authentication status and server compatibility with a simple GET request:', 'remotewp' ); ?>
						</p>
						<pre class="rwp-docs-code"><code id="rwp-curl-code"><?php echo esc_html( $curl_sample ); ?></code></pre>
					</div>

					<hr class="rwp-docs-divider">

					<!-- REST Endpoint Specifications -->
					<div class="rwp-docs-section" style="margin-top: 24px;">
						<h3 class="rwp-docs-h3"><?php esc_html_e( 'Available Endpoint Methods', 'remotewp' ); ?></h3>
						<div class="rwp-table-responsive" style="margin-top: 16px; border: 1px solid var(--rwp-border); border-radius: var(--rwp-radius-sm);">
							<table class="remotewp-log-table">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Method', 'remotewp' ); ?></th>
										<th><?php esc_html_e( 'Endpoint Path', 'remotewp' ); ?></th>
										<th><?php esc_html_e( 'Description', 'remotewp' ); ?></th>
										<th><?php esc_html_e( 'Access Level', 'remotewp' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td><span class="rwp-method-badge rwp-method-get">GET</span></td>
										<td><code>/skill</code></td>
										<td><?php esc_html_e( 'AI Agent Skill Specification', 'remotewp' ); ?></td>
										<td><span class="rwp-license-badge active" style="font-size:10px; padding:2px 8px; font-weight:600;"><?php esc_html_e( 'Standard', 'remotewp' ); ?></span></td>
									</tr>
									<tr>
										<td><span class="rwp-method-badge rwp-method-get">GET</span></td>
										<td><code>/status</code></td>
										<td><?php esc_html_e( 'Health Check & Connection Latency', 'remotewp' ); ?></td>
										<td><span class="rwp-license-badge active" style="font-size:10px; padding:2px 8px; font-weight:600;"><?php esc_html_e( 'Standard', 'remotewp' ); ?></span></td>
									</tr>
									<tr>
										<td><span class="rwp-method-badge rwp-method-get">GET</span></td>
										<td><code>/list</code></td>
										<td><?php esc_html_e( 'Directory & File Tree Listing', 'remotewp' ); ?></td>
										<td><span class="rwp-license-badge active" style="font-size:10px; padding:2px 8px; font-weight:600;"><?php esc_html_e( 'Standard', 'remotewp' ); ?></span></td>
									</tr>
									<tr>
										<td><span class="rwp-method-badge rwp-method-get">GET</span></td>
										<td><code>/read</code></td>
										<td><?php esc_html_e( 'Read File Content & Code', 'remotewp' ); ?></td>
										<td><span class="rwp-license-badge active" style="font-size:10px; padding:2px 8px; font-weight:600;"><?php esc_html_e( 'Standard', 'remotewp' ); ?></span></td>
									</tr>
									<tr>
										<td><span class="rwp-method-badge rwp-method-get">GET</span></td>
										<td><code>/wp/info</code></td>
										<td><?php esc_html_e( 'WordPress Site Overview & Plugins', 'remotewp' ); ?></td>
										<td><span class="rwp-license-badge active" style="font-size:10px; padding:2px 8px; font-weight:600;"><?php esc_html_e( 'Standard', 'remotewp' ); ?></span></td>
									</tr>
									<tr>
										<td><span class="rwp-method-badge rwp-method-post">POST</span></td>
										<td><code>/write</code></td>
										<td><?php esc_html_e( 'Create or Update File Content', 'remotewp' ); ?></td>
										<td><span class="rwp-license-badge <?php echo $is_pro ? 'active' : 'inactive'; ?>" style="font-size:10px; padding:2px 8px; font-weight:600;">PRO</span></td>
									</tr>
									<tr>
										<td><span class="rwp-method-badge rwp-method-post">POST</span></td>
										<td><code>/delete</code></td>
										<td><?php esc_html_e( 'Remove File or Folder', 'remotewp' ); ?></td>
										<td><span class="rwp-license-badge <?php echo $is_pro ? 'active' : 'inactive'; ?>" style="font-size:10px; padding:2px 8px; font-weight:600;">PRO</span></td>
									</tr>
									<tr>
										<td><span class="rwp-method-badge rwp-method-post">POST</span></td>
										<td><code>/rename</code></td>
										<td><?php esc_html_e( 'Move or Rename File', 'remotewp' ); ?></td>
										<td><span class="rwp-license-badge <?php echo $is_pro ? 'active' : 'inactive'; ?>" style="font-size:10px; padding:2px 8px; font-weight:600;">PRO</span></td>
									</tr>
									<tr>
										<td><span class="rwp-method-badge rwp-method-post">POST</span></td>
										<td><code>/mkdir</code></td>
										<td><?php esc_html_e( 'Create New Directory', 'remotewp' ); ?></td>
										<td><span class="rwp-license-badge <?php echo $is_pro ? 'active' : 'inactive'; ?>" style="font-size:10px; padding:2px 8px; font-weight:600;">PRO</span></td>
									</tr>
									<tr>
										<td><span class="rwp-method-badge rwp-method-get">GET</span></td>
										<td><code>/search</code></td>
										<td><?php esc_html_e( 'File Content Search / Grep', 'remotewp' ); ?></td>
										<td><span class="rwp-license-badge <?php echo $is_pro ? 'active' : 'inactive'; ?>" style="font-size:10px; padding:2px 8px; font-weight:600;">PRO</span></td>
									</tr>
									<tr>
										<td><span class="rwp-method-badge rwp-method-post">POST</span></td>
										<td><code>/wp/exec</code></td>
										<td><?php esc_html_e( 'Execute WP-CLI & Core Actions', 'remotewp' ); ?></td>
										<td><span class="rwp-license-badge <?php echo $is_pro ? 'active' : 'inactive'; ?>" style="font-size:10px; padding:2px 8px; font-weight:600;">PRO</span></td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>
				</div>
			</div>

			<!-- Right Column: Support & Contact Details -->
			<div class="rwp-docs-sidebar">
				<!-- Support Link Card -->
				<div class="remotewp-card rwp-support-card">
					<div class="remotewp-card-header" style="background: linear-gradient(135deg, #EFF6FF 0%, #DBEAFE 100%);">
						<h2 style="color: var(--rwp-primary);"><span class="dashicons dashicons-admin-users" style="color: var(--rwp-primary); margin-right:6px;"></span><?php esc_html_e( 'Priority Support', 'remotewp' ); ?></h2>
					</div>
					<div class="remotewp-card-body">
						<p class="rwp-docs-text" style="font-size: 13.5px; margin-bottom: 20px;">
							<?php esc_html_e( 'Need help integrating RemoteWP or configuring custom AI rules? Our dedicated engineers are available for priority assistance.', 'remotewp' ); ?>
						</p>
						<a href="https://remotewp.dev/support" target="_blank" class="button button-primary" style="width: 100%; justify-content: center; height: 42px !important;">
							<?php esc_html_e( 'Contact remotewp.dev', 'remotewp' ); ?>
						</a>
					</div>
				</div>

				<!-- X-HOUSE SRL Contact Info Card -->
				<div class="remotewp-card rwp-developer-card">
					<div class="remotewp-card-header">
						<h2><span class="dashicons dashicons-businessman" style="margin-right:6px; color: var(--rwp-text);"></span><?php esc_html_e( 'Developer Identity', 'remotewp' ); ?></h2>
					</div>
					<div class="remotewp-card-body" style="padding: 24px;">
						<p class="rwp-docs-text" style="font-size: 13px; color: var(--rwp-text-secondary); margin-bottom: 16px;">
							<?php esc_html_e( 'RemoteWP is developed and maintained under strict automation and security standards by:', 'remotewp' ); ?>
						</p>
						<table class="remotewp-info-table rwp-contact-table" style="font-size: 13px; width: 100%; border-collapse: collapse;">
							<tr style="border-bottom: 1px solid var(--rwp-border-light);">
								<th style="padding: 8px 0; text-align: left; color: var(--rwp-text-secondary); font-weight: 600; width: 90px;"><?php esc_html_e( 'Companie', 'remotewp' ); ?></th>
								<td style="padding: 8px 0; font-weight: 700; color: var(--rwp-text);">X-HOUSE SRL Arad</td>
							</tr>
							<tr style="border-bottom: 1px solid var(--rwp-border-light);">
								<th style="padding: 8px 0; text-align: left; color: var(--rwp-text-secondary); font-weight: 600;"><?php esc_html_e( 'Adresă', 'remotewp' ); ?></th>
								<td style="padding: 8px 0; color: var(--rwp-text);">Str. I.C. Brătianu Nr. 3</td>
							</tr>
							<tr style="border-bottom: 1px solid var(--rwp-border-light);">
								<th style="padding: 8px 0; text-align: left; color: var(--rwp-text-secondary); font-weight: 600;"><?php esc_html_e( 'Legătură', 'remotewp' ); ?></th>
								<td style="padding: 8px 0; color: var(--rwp-text);">ing. Timar Alexandru</td>
							</tr>
							<tr style="border-bottom: 1px solid var(--rwp-border-light);">
								<th style="padding: 8px 0; text-align: left; color: var(--rwp-text-secondary); font-weight: 600;"><?php esc_html_e( 'Telefon', 'remotewp' ); ?></th>
								<td style="padding: 8px 0; color: var(--rwp-text); font-family: var(--rwp-mono); font-size:12.5px;">0735 785 335</td>
							</tr>
							<tr style="border-bottom: 1px solid var(--rwp-border-light);">
								<th style="padding: 8px 0; text-align: left; color: var(--rwp-text-secondary); font-weight: 600;"><?php esc_html_e( 'Email', 'remotewp' ); ?></th>
								<td style="padding: 8px 0;"><a href="mailto:xander@xhouse.ro" style="color: var(--rwp-primary); text-decoration: none; font-weight:600;">xander@xhouse.ro</a></td>
							</tr>
							<tr>
								<th style="padding: 8px 0; text-align: left; color: var(--rwp-text-secondary); font-weight: 600;"><?php esc_html_e( 'Website', 'remotewp' ); ?></th>
								<td style="padding: 8px 0;"><a href="https://www.xhouse.ro" target="_blank" style="color: var(--rwp-primary); text-decoration: none; font-weight:600;">www.xhouse.ro</a></td>
							</tr>
						</table>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	// ─── Owner Lock System (Full Build Only) ──────────────────────────────

	/**
	 * Check if the admin interface is currently locked.
	 *
	 * Returns true if: Full build + master password set + session NOT unlocked.
	 *
	 * @return bool
	 */
	private function is_owner_locked() {
		if ( defined( 'REMOTEWP_DISABLE_LOCK' ) && REMOTEWP_DISABLE_LOCK ) {
			return false;
		}

		if ( ! ( ( defined( 'REMOTEWP_IS_FULL' ) && REMOTEWP_IS_FULL ) || ( defined( 'REMOTEWP_IS_PRO' ) && REMOTEWP_IS_PRO ) ) ) {
			return false;
		}

		if ( ! $this->has_master_password() ) {
			return false;
		}

		return ! $this->is_owner_unlocked();
	}

	/**
	 * Check if a master password is configured.
	 *
	 * @return bool
	 */
	private function has_master_password() {
		$hash = get_option( 'remotewp_master_password', '' );
		return ! empty( $hash );
	}

	/**
	 * Check if the current user has an active unlock session.
	 *
	 * Uses a transient per user with 24-hour expiry.
	 *
	 * @return bool
	 */
	private function is_owner_unlocked() {
		$user_id = get_current_user_id();
		return (bool) get_transient( 'remotewp_unlocked_' . $user_id );
	}

	/**
	 * Render the lock screen.
	 *
	 * Shows only logo + password field + unlock button. Nothing else.
	 */
	private function render_lock_screen() {
		$has_error = isset( $_GET['unlock_error'] );
		?>
		<div class="wrap rwp-admin remotewp-wrap">
			<style>
				.rwp-lock-overlay {
					min-height: 70vh;
					display: flex;
					align-items: center;
					justify-content: center;
				}
				.rwp-lock-card {
					background: #0d1320;
					border: 1px solid rgba(255,255,255,0.06);
					border-radius: 20px;
					padding: 48px 40px 40px;
					max-width: 420px;
					width: 100%;
					text-align: center;
					box-shadow: 0 20px 60px rgba(0,0,0,0.4), 0 0 0 1px rgba(255,255,255,0.03) inset;
				}
				.rwp-lock-icon {
					width: 64px;
					height: 64px;
					border-radius: 50%;
					background: linear-gradient(135deg, rgba(99,102,241,0.15), rgba(99,102,241,0.05));
					border: 1px solid rgba(99,102,241,0.2);
					display: flex;
					align-items: center;
					justify-content: center;
					margin: 0 auto 24px;
				}
				.rwp-lock-icon .dashicons {
					font-size: 28px;
					width: 28px;
					height: 28px;
					color: #818cf8;
				}
				.rwp-lock-title {
					color: #ffffff;
					font-size: 22px;
					font-weight: 700;
					margin: 0 0 8px;
					letter-spacing: -0.3px;
				}
				.rwp-lock-subtitle {
					color: rgba(255,255,255,0.5);
					font-size: 13px;
					margin: 0 0 32px;
					line-height: 1.5;
				}
				.rwp-lock-input {
					width: 100%;
					padding: 14px 18px;
					background: rgba(255,255,255,0.04);
					border: 1px solid rgba(255,255,255,0.1);
					border-radius: 12px;
					color: #ffffff;
					font-size: 15px;
					outline: none;
					transition: border-color 0.2s, box-shadow 0.2s;
					box-sizing: border-box;
					margin-bottom: 16px;
					letter-spacing: 2px;
				}
				.rwp-lock-input:focus {
					border-color: #6366f1;
					box-shadow: 0 0 0 3px rgba(99,102,241,0.15);
				}
				.rwp-lock-input.rwp-lock-error {
					border-color: #ef4444;
					box-shadow: 0 0 0 3px rgba(239,68,68,0.15);
					animation: rwp-shake 0.4s ease;
				}
				.rwp-lock-btn {
					width: 100%;
					padding: 14px 24px;
					background: linear-gradient(135deg, #6366f1, #4f46e5);
					color: #ffffff;
					border: none;
					border-radius: 12px;
					font-size: 14px;
					font-weight: 600;
					cursor: pointer;
					transition: transform 0.15s, box-shadow 0.15s;
					letter-spacing: 0.3px;
				}
				.rwp-lock-btn:hover {
					transform: translateY(-1px);
					box-shadow: 0 6px 20px rgba(99,102,241,0.35);
				}
				.rwp-lock-error-msg {
					color: #fca5a5;
					font-size: 12px;
					margin: -8px 0 16px;
					text-align: left;
				}
				.rwp-lock-footer {
					margin-top: 28px;
					color: rgba(255,255,255,0.25);
					font-size: 11px;
				}
				.rwp-lock-footer img {
					height: 24px;
					opacity: 0.4;
					margin-bottom: 8px;
				}
				@keyframes rwp-shake {
					0%, 100% { transform: translateX(0); }
					25% { transform: translateX(-6px); }
					50% { transform: translateX(6px); }
					75% { transform: translateX(-4px); }
				}
			</style>

			<div class="rwp-lock-overlay">
				<div class="rwp-lock-card">
					<div class="rwp-lock-icon">
						<span class="dashicons dashicons-lock"></span>
					</div>
					<h2 class="rwp-lock-title"><?php esc_html_e( 'RemoteWP is locked', 'remotewp' ); ?></h2>
					<p class="rwp-lock-subtitle"><?php esc_html_e( 'Enter the owner password to access the dashboard.', 'remotewp' ); ?></p>

					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="remotewp_unlock">
						<?php wp_nonce_field( 'remotewp_unlock' ); ?>

						<input type="password" name="remotewp_unlock_pw" class="rwp-lock-input<?php echo $has_error ? ' rwp-lock-error' : ''; ?>"
						       placeholder="<?php esc_attr_e( 'Master Password', 'remotewp' ); ?>" autofocus autocomplete="current-password">

						<?php if ( $has_error ) : ?>
							<p class="rwp-lock-error-msg"><?php esc_html_e( 'Incorrect password. Please try again.', 'remotewp' ); ?></p>
						<?php endif; ?>

						<button type="submit" class="rwp-lock-btn">
							<?php esc_html_e( 'Unlock', 'remotewp' ); ?>
						</button>
					</form>

					<div class="rwp-lock-footer">
						<img src="<?php echo esc_url( REMOTEWP_PLUGIN_URL . 'assets/logo-remotewp.png' ); ?>" alt="RemoteWP">
						<p>RemoteWP v<?php echo esc_html( REMOTEWP_VERSION ); ?> &bull; X-HOUSE SRL</p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle unlock form submission.
	 *
	 * Verifies the submitted password against the stored hash.
	 * If correct, sets a 24-hour transient for the current user.
	 */
	public function handle_unlock() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'remotewp' ) );
		}

		check_admin_referer( 'remotewp_unlock' );

		$password = isset( $_POST['remotewp_unlock_pw'] ) ? trim( wp_unslash( $_POST['remotewp_unlock_pw'] ) ) : '';
		$stored_hash = get_option( 'remotewp_master_password', '' );

		if ( ! empty( $stored_hash ) && wp_check_password( $password, $stored_hash ) ) {
			// Unlock: set transient for 24 hours
			$user_id = get_current_user_id();
			set_transient( 'remotewp_unlocked_' . $user_id, true, DAY_IN_SECONDS );

			$this->logger->log( 'OWNER_UNLOCK', '', 'Owner unlocked admin (User ID: ' . $user_id . ')' );

			wp_redirect( admin_url( 'admin.php?page=remotewp' ) );
			exit;
		}

		// Wrong password
		$this->logger->log( 'OWNER_UNLOCK_FAIL', '', 'Failed unlock attempt (User ID: ' . get_current_user_id() . ')', 'error' );

		wp_redirect( admin_url( 'admin.php?page=remotewp&unlock_error=1' ) );
		exit;
	}

	/**
	 * Handle manual lock (logout from owner session).
	 */
	public function handle_lock_session() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'remotewp' ) );
		}

		check_admin_referer( 'remotewp_lock_session' );

		$user_id = get_current_user_id();
		delete_transient( 'remotewp_unlocked_' . $user_id );

		$this->logger->log( 'OWNER_LOCK', '', 'Owner manually locked admin (User ID: ' . $user_id . ')' );

		wp_redirect( admin_url( 'admin.php?page=remotewp' ) );
		exit;
	}

	/**
	 * Handle setting/changing/removing the master password.
	 */
	public function handle_set_master_password() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'remotewp' ) );
		}

		check_admin_referer( 'remotewp_set_master_password' );

		// Check if user wants to remove the password
		if ( isset( $_POST['remotewp_remove_master_pw'] ) && '1' === $_POST['remotewp_remove_master_pw'] ) {
			update_option( 'remotewp_master_password', '' );

			// Clean up all unlock transients
			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_remotewp_unlocked_%' OR option_name LIKE '_transient_timeout_remotewp_unlocked_%'" );

			$this->logger->log( 'MASTER_PW_REMOVED', '', 'Master password removed — lock screen disabled' );

			wp_redirect( admin_url( 'admin.php?page=remotewp&tab=settings&master_pw_removed=1' ) );
			exit;
		}

		$new_password = isset( $_POST['remotewp_master_pw'] ) ? trim( wp_unslash( $_POST['remotewp_master_pw'] ) ) : '';

		// If empty and password already exists, keep current (no change)
		if ( empty( $new_password ) && $this->has_master_password() ) {
			wp_redirect( admin_url( 'admin.php?page=remotewp&tab=settings' ) );
			exit;
		}

		// If empty and no password exists, do nothing
		if ( empty( $new_password ) ) {
			wp_redirect( admin_url( 'admin.php?page=remotewp&tab=settings' ) );
			exit;
		}

		// Hash and save the new password
		$hash = wp_hash_password( $new_password );
		$was_set = $this->has_master_password();
		update_option( 'remotewp_master_password', $hash );

		// Invalidate all existing unlock sessions (force re-auth with new password)
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_remotewp_unlocked_%' OR option_name LIKE '_transient_timeout_remotewp_unlocked_%'" );

		// Re-unlock current user immediately (they just set the password)
		$user_id = get_current_user_id();
		set_transient( 'remotewp_unlocked_' . $user_id, true, DAY_IN_SECONDS );

		$action = $was_set ? 'MASTER_PW_UPDATED' : 'MASTER_PW_SET';
		$this->logger->log( $action, '', ( $was_set ? 'Master password changed' : 'Master password set — lock screen enabled' ) );

		$param = $was_set ? 'master_pw_updated' : 'master_pw_set';
		wp_redirect( admin_url( 'admin.php?page=remotewp&tab=settings&' . $param . '=1' ) );
		exit;
	}
}
