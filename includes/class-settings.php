<?php
/**
 * Plugin settings.
 *
 * @package VercelWordPressDeployer
 */

namespace VercelWordPressDeployer;

defined( 'ABSPATH' ) || exit;

/**
 * Settings screen and stored configuration.
 */
class Settings {

	const OPTION = 'vercel_wordpress_deployer_settings';

	const GROUP = 'vercel_wordpress_deployer';

	const PAGE = 'vercel-wordpress-deployer';

	/**
	 * Registers the settings page and sanitizer.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_init', array( self::class, 'register_setting' ) );
		add_action( 'admin_menu', array( self::class, 'register_page' ) );
		add_action( 'added_option', array( self::class, 'disable_autoload' ) );
		add_action( 'updated_option', array( self::class, 'disable_autoload' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( Plugin::class, 'capability' ) );
	}

	/**
	 * Keeps the deploy hook and token out of the autoloaded options cache.
	 *
	 * @param string $option Option name.
	 * @return void
	 */
	public static function disable_autoload( $option ) {
		if ( self::OPTION !== $option || ! function_exists( 'wp_set_option_autoload' ) ) {
			return;
		}

		wp_set_option_autoload( self::OPTION, false );
	}

	/**
	 * Default values used before the first save.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'hook_url'     => '',
			'access_token' => '',
			'team'         => '',
			'post_types'   => array( 'post', 'page' ),
		);
	}

	/**
	 * Merges stored settings with defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = wp_parse_args( $stored, self::defaults() );

		$settings['hook_url']     = is_string( $settings['hook_url'] ) ? $settings['hook_url'] : '';
		$settings['access_token'] = is_string( $settings['access_token'] ) ? $settings['access_token'] : '';
		$settings['team']         = is_string( $settings['team'] ) ? $settings['team'] : '';
		$settings['post_types']   = is_array( $settings['post_types'] ) ? array_values( $settings['post_types'] ) : array();

		return $settings;
	}

	/**
	 * Deploy hook URL, or an empty string when it is missing.
	 *
	 * @return string
	 */
	public static function hook_url() {
		$settings = self::get();

		return $settings['hook_url'];
	}

	/**
	 * Whether an access token is stored.
	 *
	 * @return bool
	 */
	public static function has_token() {
		$settings = self::get();

		return '' !== $settings['access_token'];
	}

	/**
	 * Registers the option with the Settings API.
	 *
	 * @return void
	 */
	public static function register_setting() {
		/*
		 * Do not pass a default here. WordPress then treats that default as the
		 * saved value and skips writing the first real hook URL and token.
		 */
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'vercel_wordpress_deployer_connection',
			__( 'Connection', 'vercel-wordpress-deployer' ),
			array( self::class, 'render_connection_section' ),
			self::PAGE
		);

		add_settings_field(
			'hook_url',
			__( 'Deploy hook URL', 'vercel-wordpress-deployer' ),
			array( self::class, 'render_hook_field' ),
			self::PAGE,
			'vercel_wordpress_deployer_connection'
		);

		add_settings_field(
			'access_token',
			__( 'Access token', 'vercel-wordpress-deployer' ),
			array( self::class, 'render_token_field' ),
			self::PAGE,
			'vercel_wordpress_deployer_connection'
		);

		add_settings_field(
			'team',
			__( 'Team', 'vercel-wordpress-deployer' ),
			array( self::class, 'render_team_field' ),
			self::PAGE,
			'vercel_wordpress_deployer_connection'
		);

		add_settings_section(
			'vercel_wordpress_deployer_types',
			__( 'Content types', 'vercel-wordpress-deployer' ),
			array( self::class, 'render_types_section' ),
			self::PAGE
		);

		add_settings_field(
			'post_types',
			__( 'Deploy when these change', 'vercel-wordpress-deployer' ),
			array( self::class, 'render_types_field' ),
			self::PAGE,
			'vercel_wordpress_deployer_types'
		);
	}

	/**
	 * Adds the settings page under Settings.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_options_page(
			__( 'Vercel Deployer', 'vercel-wordpress-deployer' ),
			__( 'Vercel Deployer', 'vercel-wordpress-deployer' ),
			Plugin::capability(),
			self::PAGE,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Connection section copy.
	 *
	 * @return void
	 */
	public static function render_connection_section() {
		echo '<p>' . esc_html__( 'The deploy hook queues a new Vercel build. A token is only used to follow that build and link to it.', 'vercel-wordpress-deployer' ) . '</p>';
	}

	/**
	 * Content type section copy.
	 *
	 * @return void
	 */
	public static function render_types_section() {
		echo '<p>' . esc_html__( 'Published content, including updates, unpublishing, and trash, starts a deployment. Drafts and autosaves do not. Edits that land together are sent as one deployment.', 'vercel-wordpress-deployer' ) . '</p>';
	}

	/**
	 * Settings page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage deployments.', 'vercel-wordpress-deployer' ),
				'',
				array( 'response' => 403 )
			);
		}

		$notice = Deployer::peek();
		$hidden = 'idle' === $notice['phase'] ? ' hidden' : '';

		echo '<div class="wrap vwd-screen">';
		echo '<div class="vwd-heading">';
		echo '<h1>' . esc_html__( 'Vercel Deployer', 'vercel-wordpress-deployer' ) . '</h1>';
		echo '<button type="button" class="button button-primary" id="vwd-deploy">' . esc_html__( 'Deploy now', 'vercel-wordpress-deployer' ) . '</button>';
		echo '</div>';

		echo '<p class="vwd-status" id="vwd-status"' . $hidden . '>';
		echo esc_html( $notice['message'] );
		if ( '' !== $notice['url'] ) {
			echo ' <a href="' . esc_url( $notice['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'See Deployment', 'vercel-wordpress-deployer' ) . '</a>';
		}
		echo '</p>';

		settings_errors();

		echo '<form action="' . esc_url( admin_url( 'options.php' ) ) . '" method="post">';
		settings_fields( self::GROUP );
		do_settings_sections( self::PAGE );
		submit_button( __( 'Save settings', 'vercel-wordpress-deployer' ) );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * Deploy hook field.
	 *
	 * @return void
	 */
	public static function render_hook_field() {
		$settings = self::get();
		$draft    = get_transient( 'vercel_wordpress_deployer_hook_draft' );
		$value    = $settings['hook_url'];

		if ( is_string( $draft ) && '' !== $draft ) {
			delete_transient( 'vercel_wordpress_deployer_hook_draft' );
			$value = $draft;
		}

		printf(
			'<input type="url" class="regular-text code" name="%1$s[hook_url]" id="vwd-hook-url" value="%2$s" placeholder="%3$s" spellcheck="false" autocomplete="off" />',
			esc_attr( self::OPTION ),
			esc_attr( $value ),
			esc_attr( 'https://api.vercel.com/v1/integrations/deploy/prj_…/…' )
		);
		echo '<p class="description">' . esc_html__( 'Paste the hook from the Vercel project settings. It is stored in WordPress and is only sent to Vercel by the server.', 'vercel-wordpress-deployer' ) . '</p>';
	}

	/**
	 * Optional token field. The saved token is never written back into the form.
	 *
	 * @return void
	 */
	public static function render_token_field() {
		$settings = self::get();
		$has_token = '' !== $settings['access_token'];

		printf(
			'<input type="password" class="regular-text" name="%1$s[access_token]" id="vwd-access-token" value="" autocomplete="new-password" />',
			esc_attr( self::OPTION )
		);

		if ( $has_token ) {
			$tail = substr( $settings['access_token'], -4 );

			echo '<p class="description"><strong>' . esc_html(
				sprintf(
					/* translators: %s: last four characters of the saved token */
					__( 'A token is saved, ending in %s.', 'vercel-wordpress-deployer' ),
					$tail
				)
			) . '</strong> ' . esc_html__( 'Leave this blank to keep it. The field stays empty so the token is not shown again.', 'vercel-wordpress-deployer' ) . '</p>';
			printf(
				'<label><input type="checkbox" name="%1$s[clear_token]" value="1" /> %2$s</label>',
				esc_attr( self::OPTION ),
				esc_html__( 'Remove saved token', 'vercel-wordpress-deployer' )
			);
		}

		$link = '<a href="' . esc_url( 'https://vercel.com/account/tokens' ) . '" target="_blank" rel="noopener noreferrer">vercel.com/account/tokens</a>';

		echo '<p class="description">' . wp_kses(
			sprintf(
				/* translators: %s: link to the Vercel token page */
				__( 'Optional. The token is used to read the deployment and show the See Deployment link. Create one at %s.', 'vercel-wordpress-deployer' ),
				$link
			),
			array(
				'a' => array(
					'href'   => true,
					'target' => true,
					'rel'    => true,
				),
			)
		) . '</p>';
	}

	/**
	 * Team slug or team ID field.
	 *
	 * @return void
	 */
	public static function render_team_field() {
		$settings = self::get();

		printf(
			'<input type="text" class="regular-text" name="%1$s[team]" id="vwd-team" value="%2$s" placeholder="%3$s" spellcheck="false" autocomplete="off" />',
			esc_attr( self::OPTION ),
			esc_attr( $settings['team'] ),
			esc_attr( 'my-team' )
		);
		echo '<p class="description">' . esc_html__( 'Required for team projects when a token is set. Use the team slug from the Vercel URL, or a team ID that starts with team_. Leave this empty for a personal account.', 'vercel-wordpress-deployer' ) . '</p>';
	}

	/**
	 * Content type checkboxes.
	 *
	 * @return void
	 */
	public static function render_types_field() {
		$settings = self::get();
		$selected = $settings['post_types'];
		$choices  = self::selectable_post_types();

		printf(
			'<input type="hidden" name="%1$s[post_types_present]" value="1" />',
			esc_attr( self::OPTION )
		);

		if ( array() === $choices ) {
			echo '<p>' . esc_html__( 'No content types are available.', 'vercel-wordpress-deployer' ) . '</p>';
			return;
		}

		echo '<div class="vwd-types">';

		foreach ( $choices as $name => $label ) {
			printf(
				'<label><input type="checkbox" name="%1$s[post_types][]" value="%2$s" %3$s /> <span class="vwd-type-name">%4$s</span> <code>%5$s</code></label>',
				esc_attr( self::OPTION ),
				esc_attr( $name ),
				checked( in_array( $name, $selected, true ), true, false ),
				esc_html( $label ),
				esc_html( $name )
			);
		}

		echo '</div>';
	}

	/**
	 * Public content types an administrator can arm.
	 *
	 * @return array<string, string> Post type name to label.
	 */
	public static function selectable_post_types() {
		$objects = get_post_types( array( 'show_ui' => true ), 'objects' );
		$skip    = array(
			'attachment',
			'wp_block',
			'wp_navigation',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_font_family',
			'wp_font_face',
			'customize_changeset',
			'custom_css',
			'nav_menu_item',
			'revision',
			'oembed_cache',
			'user_request',
			'wp_pattern',
		);
		$choices = array();

		foreach ( $objects as $object ) {
			if ( in_array( $object->name, $skip, true ) ) {
				continue;
			}

			$label                 = isset( $object->labels->name ) ? (string) $object->labels->name : $object->name;
			$choices[ $object->name ] = $label;
		}

		uasort(
			$choices,
			static function ( $left, $right ) {
				return strcasecmp( (string) $left, (string) $right );
			}
		);

		return $choices;
	}

	/**
	 * Sanitizes a settings submission.
	 *
	 * @param mixed $input Raw settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ) {
		$existing = self::get();
		$input    = is_array( $input ) ? $input : array();

		$hook_url = isset( $input['hook_url'] ) ? self::strip_invisible( (string) $input['hook_url'] ) : '';
		$parsed   = self::normalize_hook( $hook_url );

		if ( '' === $hook_url ) {
			$hook_url = '';
		} elseif ( null === $parsed ) {
			set_transient( 'vercel_wordpress_deployer_hook_draft', $hook_url, MINUTE_IN_SECONDS );
			add_settings_error(
				self::OPTION,
				'hook_url',
				__( 'The deploy hook URL was not saved. Paste the URL from Vercel that starts with https://api.vercel.com/v1/integrations/deploy/.', 'vercel-wordpress-deployer' )
			);
			$hook_url = $existing['hook_url'];
		} else {
			$hook_url = $parsed['url'];
		}

		$token = self::sanitize_token( $input, $existing['access_token'] );
		$team  = self::sanitize_team( isset( $input['team'] ) ? (string) $input['team'] : '' );

		if ( ! empty( $input['post_types_present'] ) ) {
			$posted     = isset( $input['post_types'] ) ? $input['post_types'] : array();
			$post_types = self::sanitize_post_types( $posted );
		} else {
			$post_types = $existing['post_types'];
		}

		return array(
			'hook_url'     => $hook_url,
			'access_token' => $token,
			'team'         => $team,
			'post_types'   => $post_types,
		);
	}

	/**
	 * Normalizes a deploy hook and extracts the project and hook IDs.
	 *
	 * @param string $url Raw URL.
	 * @return array{url: string, project_id: string, hook_id: string}|null
	 */
	public static function normalize_hook( $url ) {
		$url = self::strip_invisible( (string) $url );
		$url = trim( $url, "\"'<>" );

		if ( '' === $url ) {
			return null;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return null;
		}

		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		$host   = strtolower( (string) $parts['host'] );
		$path   = (string) $parts['path'];
		$query  = isset( $parts['query'] ) ? (string) $parts['query'] : '';

		if ( 'https' !== $scheme || 'api.vercel.com' !== $host ) {
			return null;
		}

		if ( ! preg_match( '#^/v1/integrations/deploy/(prj_[A-Za-z0-9_-]+)/([A-Za-z0-9_-]+)/?$#', $path, $matches ) ) {
			return null;
		}

		$path = '/v1/integrations/deploy/' . $matches[1] . '/' . $matches[2];

		$allowed_query = array();

		if ( '' !== $query ) {
			$args = array();
			parse_str( $query, $args );

			if ( ! is_array( $args ) ) {
				return null;
			}

			foreach ( $args as $key => $arg_value ) {
				$key       = (string) $key;
				$arg_value = is_scalar( $arg_value ) ? (string) $arg_value : '';

				if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', $key ) || ! preg_match( '/^[A-Za-z0-9._~:-]*$/', $arg_value ) ) {
					return null;
				}

				$allowed_query[ $key ] = $arg_value;
			}
		}

		$normalized = 'https://api.vercel.com' . $path;

		if ( array() !== $allowed_query ) {
			$normalized .= '?' . http_build_query( $allowed_query );
		}

		return array(
			'url'        => $normalized,
			'project_id' => $matches[1],
			'hook_id'    => $matches[2],
		);
	}

	/**
	 * Keeps the previous token when the password field is left blank.
	 *
	 * @param array<mixed> $input Existing submission.
	 * @param string       $existing Stored token.
	 * @return string
	 */
	private static function sanitize_token( $input, $existing ) {
		if ( ! empty( $input['clear_token'] ) ) {
			return '';
		}

		$token = isset( $input['access_token'] ) ? self::strip_invisible( (string) $input['access_token'] ) : '';
		$token = preg_replace( '/\s+/u', '', $token );
		$token = is_string( $token ) ? $token : '';

		if ( '' === $token ) {
			return $existing;
		}

		if ( strlen( $token ) < 8 || strlen( $token ) > 2048 || preg_match( '/[\x00-\x1F\x7F<>"\']/', $token ) ) {
			add_settings_error(
				self::OPTION,
				'access_token',
				__( 'The access token was not saved. Paste the token from Vercel again.', 'vercel-wordpress-deployer' )
			);
			return $existing;
		}

		return $token;
	}

	/**
	 * Removes whitespace and invisible characters introduced by copying from a browser.
	 *
	 * @param string $value Raw pasted value.
	 * @return string
	 */
	private static function strip_invisible( $value ) {
		$value = str_replace( "\xEF\xBB\xBF", '', (string) $value );
		$value = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', '', $value );
		$value = is_string( $value ) ? $value : '';

		return trim( $value );
	}

	/**
	 * Accepts a team slug or a team_ ID.
	 *
	 * @param string $team Raw team value.
	 * @return string
	 */
	private static function sanitize_team( $team ) {
		$team = sanitize_text_field( trim( $team ) );

		if ( '' === $team ) {
			return '';
		}

		if ( preg_match( '/^team_[A-Za-z0-9]+$/', $team ) ) {
			return $team;
		}

		$slug = sanitize_title( $team );

		if ( '' === $slug || strlen( $slug ) > 64 ) {
			add_settings_error(
				self::OPTION,
				'team',
				__( 'Enter a team slug or a team ID that starts with team_.', 'vercel-wordpress-deployer' )
			);
			return '';
		}

		return $slug;
	}

	/**
	 * Keeps only content types that are currently selectable.
	 *
	 * @param mixed $posted Submitted types.
	 * @return array<int, string>
	 */
	private static function sanitize_post_types( $posted ) {
		$allowed = array_keys( self::selectable_post_types() );
		$clean   = array();

		if ( ! is_array( $posted ) ) {
			return $clean;
		}

		foreach ( $posted as $type ) {
			$type = sanitize_key( (string) $type );

			if ( '' === $type || ! in_array( $type, $allowed, true ) || in_array( $type, $clean, true ) ) {
				continue;
			}

			$clean[] = $type;
		}

		return $clean;
	}
}
