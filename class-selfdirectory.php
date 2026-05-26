<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'SelfDirectory' ) ) {
	/**
	 * Self-hosted plugin update checker.
	 *
	 * Registers plugins for update checking against a self-hosted source.
	 * Supports the GitHub Releases API (preferred) and a legacy wp.json manifest.
	 *
	 * Usage — add to the plugin main file:
	 *
	 *   require_once __DIR__ . '/lib/selfdirectory/class-selfdirectory.php';
	 *   add_action( 'selfd_register', function () { selfd( __FILE__ ); } );
	 *
	 * The plugin file must declare a `Directory:` header (or fall back to
	 * `Plugin URI:`) pointing to either:
	 *   - A GitHub repository URL  (https://github.com/owner/repo)  → GitHub API
	 *   - Any other URL serving a wp.json manifest                   → legacy
	 *
	 * @package SelfDirectory
	 * @since   1.0.0
	 */
	final class SelfDirectory {

		/**
		 * Library version.
		 *
		 * @since 1.0.0
		 * @var   string
		 */
		public $version = '1.2.2';

		/**
		 * Singleton instance.
		 *
		 * @since 1.0.0
		 * @var   static|null
		 */
		protected static $_instance = null;

		/**
		 * Absolute paths to plugin files registered for update checking.
		 *
		 * @since 1.0.0
		 * @var   string[]
		 */
		public $files = array();

		/**
		 * Return the singleton instance, creating it on first call.
		 *
		 * @since  1.0.0
		 * @return static
		 */
		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}
			return self::$_instance;
		}

		/**
		 * Bootstrap: defers initialisation to the `init` hook so that
		 * plugin headers and filters are fully loaded before we attach.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			add_action( 'init', array( $this, 'init' ) );
		}

		/**
		 * Attach WordPress hooks when the updater should be active.
		 *
		 * By default only runs in the admin context (excluding AJAX).
		 * Override via the `selfd_load` filter to change when updates are checked:
		 *
		 *   // Always run (e.g. for REST or CLI consumers):
		 *   add_filter( 'selfd_load', '__return_true' );
		 *
		 * @since  1.0.0
		 * @return void
		 */
		public function init() {
			$should_load = is_admin()
				|| ( defined( 'DOING_CRON' ) && DOING_CRON )
				|| ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_POST['action'] ) && in_array( $_POST['action'], [ 'update-plugin', 'install-plugin', 'update-selected' ], true ) )
				|| wp_doing_cron();

			$should_load = apply_filters( 'selfd_load', $should_load );

			// Always register plugins so translations_api works in all contexts.
			foreach ( array( 'plugin' ) as $context ) {
				add_filter( "extra_{$context}_headers", array( $this, 'directory_header' ) );
			}
			do_action( 'selfd_register' );

			// plugins_api and translations_api run in all contexts (WP-CLI, REST, cron, admin).
			add_filter( 'plugins_api', array( $this, 'plugins_api' ), 10, 3 );
			add_filter( 'translations_api', array( $this, 'translations_api' ), 10, 3 );
			// Redirect WP-CLI reconstructed download URLs to GitHub release assets.
			add_filter( 'http_request_args', array( $this, 'http_follow_github_redirects' ), 10, 2 );
			add_filter( 'pre_http_request', array( $this, 'pre_http_request_rewrite' ), 10, 3 );

			if ( true !== $should_load ) {
				return;
			}

			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'update_plugins' ) );
			add_action( 'upgrader_process_complete', array( $this, 'auto_install_language_packs' ), 10, 2 );
			// Flush GitHub caches when WordPress forces an update check (e.g. "Check again").
			add_action( 'delete_site_transient_update_plugins', array( $this, 'flush_github_caches' ) );
		}

		/**
		 * Flush all SelfDirectory GitHub caches.
		 *
		 * Triggered by `delete_site_transient_update_plugins` — which fires whenever
		 * WordPress deletes the update_plugins transient (e.g. user clicks
		 * "Check again" on the Updates screen). Deletes all `selfd_releases_*`
		 * transients so the next update check hits the GitHub API fresh.
		 *
		 * Note: `selfd_headers_*` transients are intentionally kept — they cache
		 * plugin headers from immutable Git tags and never need to be re-fetched.
		 *
		 * @since  1.2.2
		 * @return void
		 */
		public function flush_github_caches() {
			global $wpdb;
			$rows = $wpdb->get_col(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE '_transient_selfd_releases_%'
				    OR option_name LIKE '_transient_timeout_selfd_releases_%'"
			);
			foreach ( $rows as $row ) {
				delete_option( $row );
			}
		}

		/**
		 * Register a plugin file for update checking.
		 *
		 * Called inside `selfd_register` action handlers via the {@see selfd()} helper:
		 *
		 *   add_action( 'selfd_register', function () { selfd( __FILE__ ); } );
		 *
		 * @since  1.0.0
		 * @param  string $file Absolute path to the plugin's main PHP file.
		 * @return void
		 */
		public function register( $file ) {
			if ( ! in_array( $file, $this->files, true ) ) {
				$this->files[] = $file;
			}
		}

		/**
		 * Expose the custom `Directory:` plugin file header to WordPress.
		 *
		 * WordPress ignores unknown headers unless they are declared via
		 * `extra_plugin_headers`. Without this filter, `get_plugin_data()`
		 * would silently drop the `Directory:` value.
		 *
		 * @since  1.0.0
		 * @param  string[] $extra_headers Currently registered extra headers.
		 * @return string[]
		 */
		public function directory_header( $extra_headers ) {
			$extra_headers[] = 'Directory';
			return $extra_headers;
		}

		/**
		 * Parse a github.com URL into owner and repo components.
		 *
		 * Accepts URLs with or without trailing path segments — only the first
		 * two path segments (owner/repo) are used.
		 *
		 * @since  1.1.0
		 * @param  string $url e.g. https://github.com/owner/repo
		 * @return array{owner:string,repo:string}|null  Null when $url is not a valid github.com URL.
		 */
		protected function parse_github_url( $url ) {
			$parsed = wp_parse_url( $url );
			if ( empty( $parsed['host'] ) || 'github.com' !== $parsed['host'] ) {
				return null;
			}

			$parts = explode( '/', trim( $parsed['path'] ?? '', '/' ) );
			if ( count( $parts ) < 2 || '' === $parts[0] || '' === $parts[1] ) {
				return null;
			}

			return array( 'owner' => $parts[0], 'repo' => $parts[1] );
		}

		/**
		 * Fetch all published releases for a GitHub repository, newest first.
		 *
		 * Drafts and pre-releases are filtered out. Results are stored in a
		 * WordPress transient:
		 *   - Success → cached for 12 hours.
		 *   - Failure → negative-cached for 1 hour to avoid hammering the API
		 *               on every admin page load when the repo has no releases.
		 *
		 * @since  1.1.0
		 * @param  string   $owner GitHub repository owner (user or organisation).
		 * @param  string   $repo  GitHub repository name.
		 * @return array[]|null    Indexed array of release objects (newest first), or null on failure.
		 */
		protected function get_github_releases( $owner, $repo ) {
			$cache_key = 'selfd_releases_' . md5( "{$owner}/{$repo}" );
			$cached    = get_transient( $cache_key );

			if ( false !== $cached ) {
				return is_array( $cached ) ? $cached : null;
			}

			$response = wp_remote_get(
				"https://api.github.com/repos/{$owner}/{$repo}/releases",
				array(
					'headers' => array(
						'Accept'     => 'application/vnd.github+json',
						'User-Agent' => 'SelfDirectory/1.2.2 WordPress/' . get_bloginfo( 'version' ),
					),
					'timeout' => 10,
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				set_transient( $cache_key, 0, HOUR_IN_SECONDS );
				return null;
			}

			$all = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $all ) || ! is_array( $all ) ) {
				set_transient( $cache_key, 0, HOUR_IN_SECONDS );
				return null;
			}

			// GitHub returns releases newest-first; keep only published ones.
			$releases = array_values(
				array_filter( $all, function ( $r ) {
					return ! $r['draft'] && ! $r['prerelease'];
				} )
			);

			if ( empty( $releases ) ) {
				set_transient( $cache_key, 0, HOUR_IN_SECONDS );
				return null;
			}

			set_transient( $cache_key, $releases, 12 * HOUR_IN_SECONDS );
			return $releases;
		}

		/**
		 * Read plugin headers from the main plugin file at a specific Git tag.
		 *
		 * Fetches the raw file from raw.githubusercontent.com and extracts
		 * `Requires at least`, `Tested up to`, and `Requires PHP` via regex,
		 * mirroring what WordPress does with get_plugin_data() locally.
		 *
		 * Cached indefinitely — a Git tag's content is immutable, so there
		 * is no value in ever re-fetching the same tag/file combination.
		 *
		 * @since  1.1.0
		 * @param  string $owner           GitHub owner.
		 * @param  string $repo            GitHub repository name.
		 * @param  string $tag             Tag name, e.g. "0.2.0" or "v0.2.0".
		 * @param  string $plugin_basename Filename of the main plugin file, e.g. "axellcore.php".
		 * @return array{requires:string,tested:string,requires_php:string}
		 */
		protected function get_remote_plugin_headers( $owner, $repo, $tag, $plugin_basename ) {
			$cache_key = 'selfd_headers_' . md5( "{$owner}/{$repo}/{$tag}/{$plugin_basename}" );
			$cached    = get_transient( $cache_key );

			if ( is_array( $cached ) ) {
				return $cached;
			}

			$response = wp_remote_get(
				"https://raw.githubusercontent.com/{$owner}/{$repo}/{$tag}/{$plugin_basename}",
				array(
					'headers' => array(
						'User-Agent' => 'SelfDirectory/1.2.2 WordPress/' . get_bloginfo( 'version' ),
					),
					'timeout' => 10,
				)
			);

			$headers = array( 'requires' => '', 'tested' => '', 'requires_php' => '' );

			if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
				$content = wp_remote_retrieve_body( $response );
				$map     = array(
					'requires'     => 'Requires at least',
					'tested'       => 'Tested up to',
					'requires_php' => 'Requires PHP',
				);
				foreach ( $map as $key => $label ) {
					if ( preg_match( '/' . preg_quote( $label, '/' ) . ':\s*(\S+)/i', $content, $m ) ) {
						$headers[ $key ] = $m[1];
					}
				}
			}

			set_transient( $cache_key, $headers, 0 ); // 0 = no expiry.
			return $headers;
		}

		/**
		 * Build update info by querying the GitHub Releases API.
		 *
		 * Fetches all published releases (newest first) and treats the first as
		 * "latest". Builds a complete `versions` map (version → package URL) from
		 * the full release list, enabling rollback via plugins such as WP Rollback.
		 *
		 * Asset resolution per release (in order):
		 *   1. First `.zip` file attached to the release assets.
		 *   2. GitHub-generated source zipball (`zipball_url`) as fallback.
		 *
		 * The `v` prefix is stripped from tag names so that tags like "v0.2.0"
		 * and plugin headers like "Version: 0.2.0" compare as equal.
		 *
		 * @since  1.1.0
		 * @param  string                          $file Absolute path to the plugin main file.
		 * @param  array{owner:string,repo:string} $gh   Parsed GitHub repo components.
		 * @return array{slug:string,version:string,package:string,requires:string,tested:string,requires_php:string,versions:array<string,array{version:string,package:string}>}|null
		 */
		protected function get_update_via_github( $file, $gh ) {
			$releases = $this->get_github_releases( $gh['owner'], $gh['repo'] );
			if ( ! $releases ) {
				return null;
			}

			$latest = $releases[0]; // Newest published release.
			$tag    = $latest['tag_name'];

			// Build version → update-object map from all releases.
			// Headers (requires/tested/requires_php) are only fetched for the latest
			// tag to avoid N extra API calls for older releases.
			// Pattern that matches language pack assets ({repo}.{version}-{locale}.zip)
			// — excluded from plugin zip resolution.
			$lang_pattern = '/^' . preg_quote( $gh['repo'], '/' ) . '\.[^-]+-[a-z]{2,3}_[A-Z]{2,4}\.zip$/';

			$versions = array();
			foreach ( $releases as $release ) {
				$v   = ltrim( $release['tag_name'], 'v' );
				$pkg = null;
				if ( ! empty( $release['assets'] ) ) {
					foreach ( $release['assets'] as $asset ) {
						$name = $asset['name'] ?? '';
						// Skip language pack zips — only pick the plugin zip.
						if ( str_ends_with( $name, '.zip' ) && ! preg_match( $lang_pattern, $name ) ) {
							$pkg = $asset['browser_download_url'];
							break;
						}
					}
				}
				if ( ! $pkg ) {
					$pkg = $release['zipball_url'] ?? null;
				}
				if ( $pkg ) {
					$versions[ $v ] = array(
						'version' => $v,
						'package' => $pkg,
					);
				}
			}

			$version     = ltrim( $tag, 'v' );
			$version_obj = $versions[ $version ] ?? null;
			if ( ! $version_obj ) {
				return null;
			}
			$package = $version_obj['package'];

			$headers = $this->get_remote_plugin_headers(
				$gh['owner'],
				$gh['repo'],
				$tag,
				basename( $file )
			);

			return array_merge(
				array(
					'slug'     => $gh['repo'],
					'version'  => $version,
					'package'  => $package,
					'versions' => $versions,
				),
				$headers
			);
		}

		/**
		 * Legacy: build update info by fetching a wp.json manifest.
		 *
		 * Used when the resolved directory URL is not a github.com URL.
		 * Expected manifest format:
		 *
		 *   {
		 *     "slug": "my-plugin",
		 *     "latest": {
		 *       "version":      "1.2.0",
		 *       "package":      "https://example.com/my-plugin-1.2.0.zip",
		 *       "requires":     "6.4",
		 *       "tested":       "6.8",
		 *       "requires_php": "8.1"
		 *     },
		 *     "versions": {
		 *       "1.2.0": {
		 *         "version":      "1.2.0",
		 *         "package":      "https://example.com/my-plugin-1.2.0.zip",
		 *         "requires":     "6.4",
		 *         "tested":       "6.8",
		 *         "requires_php": "8.1"
		 *       },
		 *       "1.1.0": {
		 *         "version":      "1.1.0",
		 *         "package":      "https://example.com/my-plugin-1.1.0.zip",
		 *         "requires":     "6.4",
		 *         "tested":       "6.7",
		 *         "requires_php": "8.1"
		 *       }
		 *     }
		 *   }
		 *
		 * Each entry in `versions` mirrors the `latest` structure. The map is
		 * optional but recommended for rollback support (e.g. via WP Rollback).
		 * When absent, an empty array is returned.
		 *
		 * SSL is attempted first; falls back to plain HTTP if the SSL request
		 * fails and the site supports SSL (matching WordPress core behaviour).
		 *
		 * @since  1.0.0
		 * @param  string $wp_json_url Full URL to the wp.json manifest.
		 * @return array{slug:string,version:string,package:string,requires:string,tested:string,requires_php:string,versions:array<string,array<string,string>>}|null
		 */
		protected function get_update_via_wp_json( $wp_json_url ) {
			$ssl      = wp_http_supports( array( 'ssl' ) );
			$url      = $ssl ? set_url_scheme( $wp_json_url, 'https' ) : $wp_json_url;
			$response = wp_remote_get( $url );

			if ( $ssl && is_wp_error( $response ) ) {
				$response = wp_remote_get( $wp_json_url );
			}

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return null;
			}

			$data   = json_decode( wp_remote_retrieve_body( $response ), true );
			$latest = $data['latest'] ?? null;
			if ( ! $latest || empty( $latest['version'] ) ) {
				return null;
			}

			return array(
				'slug'         => $data['slug'] ?? '',
				'version'      => $latest['version'],
				'package'      => $latest['package'] ?? '',
				'requires'     => $latest['requires'] ?? '',
				'tested'       => $latest['tested'] ?? '',
				'requires_php' => $latest['requires_php'] ?? '',
				'versions'     => $data['versions'] ?? array(),
			);
		}

		/**
		 * Automatically install language packs after a plugin is installed or updated.
		 *
		 * Hooked on `upgrader_process_complete`. Fires when any plugin is installed
		 * or updated via the admin UI (zip upload, plugin screen update, auto-update).
		 * For each registered file that was affected, fetches available language packs
		 * from GitHub releases and installs them immediately — no manual visit to
		 * Dashboard → Updates required.
		 *
		 * @since 1.2.1
		 * @param WP_Upgrader $upgrader  Upgrader instance.
		 * @param array       $hook_extra Extra data: action, type, plugins/themes.
		 * @return void
		 */
		public function auto_install_language_packs( $upgrader, array $hook_extra ): void {
			if ( ( $hook_extra['type'] ?? '' ) !== 'plugin' ) {
				return;
			}

			$action = $hook_extra['action'] ?? '';
			if ( ! in_array( $action, array( 'install', 'update' ), true ) ) {
				return;
			}

			// Collect basenames of affected plugins.
			$affected = array();
			if ( ! empty( $hook_extra['plugins'] ) ) {
				$affected = (array) $hook_extra['plugins'];
			} elseif ( ! empty( $hook_extra['plugin'] ) ) {
				$affected = array( $hook_extra['plugin'] );
			}

			foreach ( $this->files as $file ) {
				$basename = plugin_basename( $file );
				if ( ! empty( $affected ) && ! in_array( $basename, $affected, true ) ) {
					continue;
				}

				if ( ! file_exists( $file ) ) {
					continue;
				}

				$plugin_data = get_plugin_data( $file, false, false );
				$directory   = esc_url( $plugin_data['Directory'] ?? '' ) ?: esc_url( $plugin_data['PluginURI'] ?? '' );
				$gh          = $directory ? $this->parse_github_url( $directory ) : null;
				if ( ! $gh ) {
					continue;
				}

				$releases = $this->get_github_releases( $gh['owner'], $gh['repo'] );
				if ( ! $releases ) {
					continue;
				}

				$installed_version  = $plugin_data['Version'] ?? '';
				$lang_pattern       = '/^' . preg_quote( $gh['repo'], '/' ) . '\.([^-]+)-([a-z]{2,3}_[A-Z]{2,4})\.zip$/';
				$installed_trans    = wp_get_installed_translations( 'plugins' )[ dirname( $basename ) ] ?? array();
				$packs_to_install   = array();

				foreach ( $releases as $release ) {
					$release_version = ltrim( $release['tag_name'], 'v' );
					foreach ( $release['assets'] ?? array() as $asset ) {
						if ( ! preg_match( $lang_pattern, $asset['name'] ?? '', $m ) ) {
							continue;
						}
						$asset_version = $m[1];
						$locale        = $m[2];

						if ( $installed_version && $asset_version !== $installed_version ) {
							continue;
						}
						if ( isset( $packs_to_install[ $locale ] ) ) {
							continue;
						}

						// Skip if already at this version.
						$id_ver = $installed_trans[ $locale ]['Project-Id-Version'] ?? '';
						if ( $id_ver && preg_match( '/([0-9]+\.[0-9]+\.[0-9]+)$/', $id_ver, $vm ) && $vm[1] === $asset_version ) {
							continue;
						}

						$packs_to_install[ $locale ] = (object) array(
							'type'     => 'plugin',
							'slug'     => dirname( $basename ),
							'language' => $locale,
							'version'  => $release_version,
							'updated'  => gmdate( 'Y-m-d H:i:s', strtotime( $release['published_at'] ) ),
							'package'  => $asset['browser_download_url'],
							'autoupdate' => true,
						);
					}
				}

				if ( empty( $packs_to_install ) ) {
					continue;
				}

				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/misc.php';

				$skin     = new Automatic_Upgrader_Skin();
				$lang_upgrader = new Language_Pack_Upgrader( $skin );
				foreach ( $packs_to_install as $pack ) {
					$lang_upgrader->upgrade( $pack );
				}
			}
		}

		/**
		 * Inject update data into the WordPress plugin update transient.
		 *
		 * Hooked on `pre_set_site_transient_update_plugins`. For each registered
		 * plugin file, the update source URL is resolved in order:
		 *
		 *   1. `Directory:` plugin header  (explicit, preferred)
		 *   2. `Plugin URI:` header        (fallback when Directory is absent)
		 *
		 * The resolved URL is then classified:
		 *   - github.com URL → {@see get_update_via_github()} (Releases API)
		 *   - anything else  → {@see get_update_via_wp_json()} at {url}/wp.json
		 *
		 * @since  1.0.0
		 * @param  object $value The `update_plugins` site transient value.
		 * @return object
		 */
		public function update_plugins( $value ) {
			foreach ( $this->files as $file ) {
				if ( ! file_exists( $file ) ) {
					continue;
				}

				$plugin_data = get_plugin_data( $file, false, false );

				// Prefer the explicit Directory header; fall back to Plugin URI.
				$directory = esc_url( $plugin_data['Directory'] ?? '' );
				if ( ! $directory ) {
					$directory = esc_url( $plugin_data['PluginURI'] ?? '' );
				}
				if ( ! $directory ) {
					continue;
				}

				$gh = $this->parse_github_url( $directory );

				if ( $gh ) {
					$update = $this->get_update_via_github( $file, $gh );
				} else {
					$update = $this->get_update_via_wp_json( untrailingslashit( $directory ) . '/wp.json' );
				}

				$basename = plugin_basename( $file );

				if ( $update && ! empty( $update['version'] ) ) {
					if ( version_compare( $plugin_data['Version'], $update['version'] ) < 0 ) {
						// Newer version available — add to update response.
						$value->response[ $basename ] = (object) array(
							'slug'         => $update['slug'] ?? dirname( $basename ),
							'new_version'  => $update['version'],
							'package'      => $update['package'] ?? '',
							'requires'     => $update['requires'] ?? '',
							'tested'       => $update['tested'] ?? '',
							'requires_php' => $update['requires_php'] ?? '',
							'versions'     => $update['versions'] ?? array(),
						);
					} elseif ( ! isset( $value->response[ $basename ] ) ) {
						// Plugin is up-to-date — register in no_update so WordPress
						// knows the plugin exists and can offer language pack installs.
						if ( ! isset( $value->no_update ) ) {
							$value->no_update = array();
						}
						$value->no_update[ $basename ] = (object) array(
							'slug'         => $update['slug'] ?? dirname( $basename ),
							'new_version'  => $update['version'],
							'package'      => '',
							'requires'     => $update['requires'] ?? '',
							'tested'       => $update['tested'] ?? '',
							'requires_php' => $update['requires_php'] ?? '',
							'versions'     => $update['versions'] ?? array(),
						);
					}
				}

				// Language packs — always injected regardless of plugin update status.
				if ( $gh ) {
					$this->inject_language_packs( $value, $basename, $gh );
				}
			}

			return $value;
		}

		/**
		 * Inject language pack update entries into the WordPress update transient.
		 *
		 * Scans GitHub release assets for files matching `{repo}.{version}-{locale}.zip`
		 * (where locale is e.g. `pt_BR`) and adds them to `$value->translations`
		 * so that WordPress can download and install them via the admin panel.
		 *
		 * Only assets from the newest release that contains a given locale are used.
		 * A pack is only injected when no translation is installed or the pack's
		 * publication timestamp is newer than the installed translation's revision date.
		 *
		 * @since  1.2.0
		 * @param  object                          $value    The `update_plugins` site transient.
		 * @param  string                          $basename Plugin basename, e.g. `axellcore/axellcore.php`.
		 * @param  array{owner:string,repo:string} $gh       Parsed GitHub repo components.
		 * @return void
		 */
		protected function inject_language_packs( $value, $basename, $gh ) {
			$releases = $this->get_github_releases( $gh['owner'], $gh['repo'] );
			if ( ! $releases ) {
				return;
			}

			$slug = dirname( $basename ); // plugin folder name, e.g. "axellcore"

			// Resolve the currently installed plugin version.
			$plugin_file = '';
			foreach ( $this->files as $f ) {
				if ( plugin_basename( $f ) === $basename ) {
					$plugin_file = $f;
					break;
				}
			}
			$plugin_data       = $plugin_file ? get_plugin_data( $plugin_file, false, false ) : array();
			$installed_version = $plugin_data['Version'] ?? '';

			// Asset name format: {repo}.{version}-{locale}.zip
			$pattern = '/^' . preg_quote( $gh['repo'], '/' ) . '\.([^-]+)-([a-z]{2,3}_[A-Z]{2,4})\.zip$/';
			$packs   = array(); // locale => pack data

			foreach ( $releases as $release ) {
				if ( empty( $release['assets'] ) ) {
					continue;
				}
				$release_version = ltrim( $release['tag_name'], 'v' );
				foreach ( $release['assets'] as $asset ) {
					if ( ! preg_match( $pattern, $asset['name'] ?? '', $m ) ) {
						continue;
					}
					$asset_version = $m[1];
					$locale        = $m[2];

					// Only offer the pack that matches the installed plugin version.
					if ( $installed_version && $asset_version !== $installed_version ) {
						continue;
					}

					if ( isset( $packs[ $locale ] ) ) {
						continue; // releases are newest-first; first match wins.
					}
					$packs[ $locale ] = array(
						'type'         => 'plugin',
						'slug'         => $slug,
						'language'     => $locale,
						'version'      => $release_version,
						'updated'      => gmdate( 'Y-m-d H:i:s', strtotime( $release['published_at'] ) ),
						'package'      => $asset['browser_download_url'],
						'autoupdate'   => true,
						'requires_php' => '',
						'requires'     => '',
					);
				}
			}

			if ( empty( $packs ) ) {
				return;
			}

			$installed_translations = wp_get_installed_translations( 'plugins' )[ $slug ] ?? array();

			if ( ! isset( $value->translations ) || ! is_array( $value->translations ) ) {
				$value->translations = array();
			}

			// Build a set of slugs already in $value->translations to avoid duplicates.
			$already = array();
			foreach ( $value->translations as $existing ) {
				if ( ( $existing['slug'] ?? '' ) === $slug ) {
					$already[] = $existing['language'] ?? '';
				}
			}

			foreach ( $packs as $locale => $pack ) {
				// Skip if already queued.
				if ( in_array( $locale, $already, true ) ) {
					continue;
				}

				$tr = $installed_translations[ $locale ] ?? array();

				// Skip if the installed translation already covers this plugin version.
				// Project-Id-Version is e.g. "Axell Core 0.2.3" — extract the trailing version.
				$id_version = $tr['Project-Id-Version'] ?? '';
				if ( $id_version && preg_match( '/([0-9]+\.[0-9]+\.[0-9]+)$/', $id_version, $vm ) ) {
					if ( $vm[1] === $pack['version'] ) {
						continue; // same version already installed.
					}
				}

				// Fallback: skip if PO-Revision-Date is newer than the pack's publish date.
				$revision = $tr['PO-Revision-Date'] ?? '';
				if ( $revision && strtotime( $revision ) >= strtotime( $pack['updated'] ) ) {
					continue;
				}

				$value->translations[] = $pack;
			}
		}

		/**
		 * Redirect WP-CLI version-specific download URLs to GitHub release assets.
		 *
		 * WP-CLI calls alter_api_response() which reconstructs the download URL as
		 * {base}{slug}.{version}.zip (wordpress.org pattern). For self-hosted plugins
		 * this produces an invalid URL. This filter intercepts both the HEAD check
		 * and the actual download, replacing the malformed URL with the correct
		 * GitHub release asset URL when a matching version exists in our releases.
		 *
		 * Also ensures GitHub URLs follow redirects (GitHub assets return 302).
		 *
		 * @since 1.2.1
		 * @param array  $args Parsed request args.
		 * @param string $url  Request URL.
		 * @return array
		 */
		public function http_follow_github_redirects( array $args, string $url ): array {
			// GitHub release assets respond with 302 — ensure redirects are followed.
			if ( strpos( $url, 'github.com' ) !== false ) {
				$args['redirection'] = max( 5, $args['redirection'] ?? 5 );
			}
			return $args;
		}

		/**
		 * Rewrite WP-CLI reconstructed download URLs to correct GitHub asset URLs.
		 *
		 * When WP-CLI calls alter_api_response() it rebuilds the download URL as
		 * {base}{slug}.{version}.zip (wordpress.org pattern). For GitHub-hosted
		 * plugins this produces a URL that returns 404. This filter intercepts both
		 * the HEAD verification request and the actual download, transparently
		 * replacing the malformed URL with the correct GitHub release asset URL.
		 *
		 * Returns false (let WordPress proceed) for everything except matched URLs,
		 * where it performs the request against the correct URL instead.
		 *
		 * @since 1.2.1
		 * @param false|array|WP_Error $preempt Whether to preempt the request.
		 * @param array                $args    HTTP request args.
		 * @param string               $url     Request URL.
		 * @return false|array|WP_Error
		 */
		public function pre_http_request_rewrite( $preempt, array $args, string $url ) {
			if ( false !== $preempt ) {
				return $preempt;
			}

			foreach ( $this->files as $file ) {
				$slug = dirname( plugin_basename( $file ) );
				if ( ! preg_match( '/(?:^|\/)' . preg_quote( $slug, '/' ) . '\.([0-9]+\.[0-9]+\.[0-9]+)\.zip(?:[?#]|$)/', $url, $m ) ) {
					continue;
				}
				$requested_version = $m[1];

				$plugin_data = get_plugin_data( $file, false, false );
				$directory   = esc_url( $plugin_data['Directory'] ?? '' ) ?: esc_url( $plugin_data['PluginURI'] ?? '' );
				$gh          = $directory ? $this->parse_github_url( $directory ) : null;
				if ( ! $gh ) {
					continue;
				}

				// Only rewrite if the URL doesn't look like a valid GitHub release URL already.
				if ( strpos( $url, 'github.com/' . $gh['owner'] . '/' . $gh['repo'] . '/releases/' ) !== false ) {
					continue;
				}

				$releases = $this->get_github_releases( $gh['owner'], $gh['repo'] );
				if ( ! $releases ) {
					continue;
				}

				$lang_pattern = '/^' . preg_quote( $gh['repo'], '/' ) . '\.[^-]+-[a-z]{2,3}_[A-Z]{2,4}\.zip$/';
				$asset_url    = null;
				foreach ( $releases as $release ) {
					if ( ltrim( $release['tag_name'], 'v' ) !== $requested_version ) {
						continue;
					}
					foreach ( $release['assets'] ?? array() as $asset ) {
						$name = $asset['name'] ?? '';
						if ( str_ends_with( $name, '.zip' ) && ! preg_match( $lang_pattern, $name ) ) {
							$asset_url = $asset['browser_download_url'];
							break 2;
						}
					}
				}

				if ( ! $asset_url ) {
					continue;
				}

				// Re-dispatch the request against the correct URL.
				$args['redirection'] = max( 5, $args['redirection'] ?? 5 );
				return wp_remote_request( $asset_url, $args );
			}

			return false;
		}

		/**
		 * Intercept plugins_api() for registered plugins.
		 *
		 * Hooked on `plugins_api`. Returns plugin information including a `versions`
		 * map built from GitHub releases, which enables:
		 *   wp plugin install <slug> --version=<x.y.z> --force
		 *
		 * @since  1.2.1
		 * @param  false|object|WP_Error $result Preemptive result; false = let WordPress proceed.
		 * @param  string               $action API action (e.g. 'plugin_information').
		 * @param  object               $args   Request args including slug.
		 * @return false|object|WP_Error
		 */
		public function plugins_api( $result, $action, $args ) {
			if ( 'plugin_information' !== $action ) {
				return $result;
			}

			$slug = $args->slug ?? '';
			if ( ! $slug ) {
				return $result;
			}

			// Find the registered file matching this slug.
			$file = null;
			foreach ( $this->files as $f ) {
				if ( dirname( plugin_basename( $f ) ) === $slug ) {
					$file = $f;
					break;
				}
			}
			if ( ! $file ) {
				return $result;
			}

			$plugin_data = get_plugin_data( $file, false, false );
			$directory   = esc_url( $plugin_data['Directory'] ?? '' ) ?: esc_url( $plugin_data['PluginURI'] ?? '' );
			$gh          = $directory ? $this->parse_github_url( $directory ) : null;
			if ( ! $gh ) {
				return $result;
			}

			$info = $this->get_update_via_github( $file, $gh );
			if ( ! $info ) {
				return $result;
			}

			// Build versions map: version => download_link (flat, as WP expects).
			$versions = array();
			foreach ( $info['versions'] ?? array() as $v => $vdata ) {
				$versions[ $v ] = $vdata['package'] ?? '';
			}
			// Always include the latest version.
			if ( ! isset( $versions[ $info['version'] ] ) ) {
				$versions[ $info['version'] ] = $info['package'];
			}

			// If a specific version was requested (e.g. WP-CLI --version flag),
			// resolve download_link to that version's package URL.
			$requested = $args->version ?? '';
			if ( $requested && isset( $versions[ $requested ] ) ) {
				$download_link    = $versions[ $requested ];
				$resolved_version = $requested;
			} else {
				$download_link    = $info['package'];
				$resolved_version = $info['version'];
			}

			return (object) array(
				'name'          => $plugin_data['Name'] ?? $slug,
				'slug'          => $slug,
				'version'       => $resolved_version,
				'author'        => $plugin_data['Author'] ?? '',
				'requires'      => $info['requires'],
				'tested'        => $info['tested'],
				'requires_php'  => $info['requires_php'],
				'download_link' => $download_link,
				'versions'      => $versions,
				'sections'      => array(
					'description' => $plugin_data['Description'] ?? '',
				),
			);
		}

		/**
		 * Intercept translations_api() for registered plugins.
		 *
		 * Hooked on `translations_api` (runs in all contexts including WP-CLI).
		 * Returns available language packs from GitHub releases so that
		 * `wp language plugin install <slug> <locale>` works for self-hosted plugins.
		 *
		 * @since  1.2.0
		 * @param  false|array $result  Preemptive result; false = let WordPress proceed.
		 * @param  string      $type    API type: 'plugins', 'themes', or 'core'.
		 * @param  object      $args    Request args including slug and version.
		 * @return false|array
		 */
		public function translations_api( $result, $type, $args ) {
			if ( 'plugins' !== $type ) {
				return $result;
			}

			$slug = is_object( $args ) ? ( $args->slug ?? '' ) : ( $args['slug'] ?? '' );
			if ( ! $slug ) {
				return $result;
			}

			// Find the registered file matching this slug.
			$file = null;
			foreach ( $this->files as $f ) {
				if ( dirname( plugin_basename( $f ) ) === $slug ) {
					$file = $f;
					break;
				}
			}
			if ( ! $file ) {
				return $result;
			}

			$plugin_data = get_plugin_data( $file, false, false );
			$directory   = esc_url( $plugin_data['Directory'] ?? '' ) ?: esc_url( $plugin_data['PluginURI'] ?? '' );
			$gh          = $directory ? $this->parse_github_url( $directory ) : null;
			if ( ! $gh ) {
				return $result;
			}

			$releases = $this->get_github_releases( $gh['owner'], $gh['repo'] );
			if ( ! $releases ) {
				return $result;
			}

			$pattern      = '/^' . preg_quote( $gh['repo'], '/' ) . '\.[^-]+-([a-z]{2,3}_[A-Z]{2,4})\.zip$/';
			$translations = array();

			foreach ( $releases as $release ) {
				if ( empty( $release['assets'] ) ) {
					continue;
				}
				foreach ( $release['assets'] as $asset ) {
					if ( ! preg_match( $pattern, $asset['name'] ?? '', $m ) ) {
						continue;
					}
					$locale = $m[1];
					// Newest release wins per locale.
					if ( isset( $translations[ $locale ] ) ) {
						continue;
					}
					$translations[ $locale ] = array(
						'type'     => 'plugin',
						'slug'     => $slug,
						'language' => $locale,
						'version'  => ltrim( $release['tag_name'], 'v' ),
						'updated'  => gmdate( 'Y-m-d H:i:s', strtotime( $release['published_at'] ) ),
						'package'  => $asset['browser_download_url'],
						'autoupdate' => true,
					);
				}
			}

			if ( empty( $translations ) ) {
				return $result;
			}

			return array( 'translations' => array_values( $translations ) );
		}
	}

	if ( ! function_exists( 'load_self_directory' ) ) {
		/**
		 * Instantiate the SelfDirectory singleton and store it in $GLOBALS.
		 *
		 * Attached to `plugins_loaded` or called immediately when that hook
		 * has already fired (e.g. when this file is required late).
		 *
		 * @since  1.0.0
		 * @return void
		 */
		function load_self_directory() {
			$GLOBALS['selfd'] = SelfDirectory::instance();
		}
	}

	if ( did_action( 'plugins_loaded' ) ) {
		load_self_directory();
	} else {
		add_action( 'plugins_loaded', 'load_self_directory' );
	}
}

if ( ! function_exists( 'selfd' ) ) {
	/**
	 * Register a plugin file with SelfDirectory for update checking.
	 *
	 * Convenience wrapper around {@see SelfDirectory::register()}.
	 * Must be called inside a `selfd_register` action callback:
	 *
	 *   add_action( 'selfd_register', function () { selfd( __FILE__ ); } );
	 *
	 * @since  1.0.0
	 * @param  string $file Absolute path to the plugin's main PHP file.
	 * @return void
	 */
	function selfd( $file ) {
		$instance = call_user_func( array( get_class( $GLOBALS['selfd'] ), 'instance' ) );
		call_user_func( array( $instance, 'register' ), $file );
	}
}
