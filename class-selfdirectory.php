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
		public $version = '1.2.0';

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

			// translations_api runs in all contexts (WP-CLI, REST, cron, admin).
			add_filter( 'translations_api', array( $this, 'translations_api' ), 10, 3 );

			if ( true !== $should_load ) {
				return;
			}

			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'update_plugins' ) );
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
			$this->files[] = $file;
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
						'User-Agent' => 'SelfDirectory/1.2.0 WordPress/' . get_bloginfo( 'version' ),
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
						'User-Agent' => 'SelfDirectory/1.2.0 WordPress/' . get_bloginfo( 'version' ),
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

			$slug   = dirname( $basename ); // plugin folder name, e.g. "axellcore"
			// Asset name format: {repo}.{version}-{locale}.zip
			$pattern = '/^' . preg_quote( $gh['repo'], '/' ) . '\.[^-]+-([a-z]{2,3}_[A-Z]{2,4})\.zip$/';
			$packs   = array(); // locale => pack data (newest release wins)

			foreach ( $releases as $release ) {
				if ( empty( $release['assets'] ) ) {
					continue;
				}
				foreach ( $release['assets'] as $asset ) {
					if ( ! preg_match( $pattern, $asset['name'] ?? '', $m ) ) {
						continue;
					}
					$locale = $m[1];
					if ( isset( $packs[ $locale ] ) ) {
						continue; // releases are newest-first; first match wins.
					}
					$packs[ $locale ] = array(
						'type'       => 'plugin',
						'slug'       => $slug,
						'language'   => $locale,
						'version'    => ltrim( $release['tag_name'], 'v' ),
						'updated'    => gmdate( 'Y-m-d H:i:s', strtotime( $release['published_at'] ) ),
						'package'    => $asset['browser_download_url'],
						'autoupdate' => true,
						'requires_php' => '',
						'requires'   => '',
					);
				}
			}

			if ( empty( $packs ) ) {
				return;
			}

			$installed = wp_get_installed_translations( 'plugins' )[ $slug ] ?? array();

			if ( ! isset( $value->translations ) || ! is_array( $value->translations ) ) {
				$value->translations = array();
			}

			foreach ( $packs as $locale => $pack ) {
				$revision = $installed[ $locale ]['PO-Revision-Date'] ?? '';
				if ( $revision && strtotime( $revision ) >= strtotime( $pack['updated'] ) ) {
					continue; // already up-to-date.
				}
				$value->translations[] = $pack;
			}
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
