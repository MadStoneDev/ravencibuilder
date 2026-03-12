<?php

namespace ZionBuilder;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * RavenciPluginUpdater
 *
 * Handles plugin update checks for the free RavenciBuilder plugin.
 * No license key required for the free version.
 */
class RavenciPluginUpdater {

	const API_BASE_URL = 'https://builder.ravenci.solutions/api';

	private $plugin_file;
	private $plugin_slug;
	private $plugin_basename;
	private $version;
	private $cache_key;

	/**
	 * @param string $plugin_file Full path to the main plugin file.
	 * @param array  $args {
	 *     @type string $version     Current plugin version.
	 *     @type string $plugin_slug Plugin slug identifier.
	 * }
	 */
	public function __construct( $plugin_file, $args = [] ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->version         = isset( $args['version'] ) ? $args['version'] : '';
		$this->plugin_slug     = isset( $args['plugin_slug'] ) ? $args['plugin_slug'] : 'ravencibuilder';
		$this->cache_key       = 'ravenci_update_' . md5( $this->plugin_slug );

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugins_api_filter' ], 10, 3 );
		add_action( 'upgrader_process_complete', [ $this, 'clear_cache' ], 10, 2 );
	}

	public function check_update( $transient_data ) {
		if ( empty( $transient_data ) ) {
			return $transient_data;
		}

		$remote = $this->get_update_info();

		if ( ! $remote || ! isset( $remote->new_version ) ) {
			return $transient_data;
		}

		if ( version_compare( $this->version, $remote->new_version, '<' ) ) {
			$update = (object) [
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_basename,
				'new_version' => $remote->new_version,
				'tested'      => isset( $remote->tested ) ? $remote->tested : '',
				'requires_php'=> isset( $remote->requires_php ) ? $remote->requires_php : '7.0.0',
				'url'         => 'https://ravencibuilder.com',
				'package'     => ! empty( $remote->download_url ) ? $remote->download_url : '',
			];

			$transient_data->response[ $this->plugin_basename ] = $update;
		} else {
			$transient_data->no_update[ $this->plugin_basename ] = (object) [
				'slug'        => $this->plugin_slug,
				'plugin'      => $this->plugin_basename,
				'new_version' => $this->version,
				'url'         => 'https://ravencibuilder.com',
			];
		}

		return $transient_data;
	}

	public function plugins_api_filter( $data, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $data;
		}

		if ( ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
			return $data;
		}

		$remote = $this->get_update_info();

		if ( ! $remote ) {
			return $data;
		}

		return (object) [
			'name'          => 'RavenciBuilder',
			'slug'          => $this->plugin_slug,
			'version'       => $remote->new_version,
			'author'        => '<a href="https://ravencibuilder.com">RavenciBuilder</a>',
			'homepage'      => 'https://ravencibuilder.com',
			'requires_php'  => isset( $remote->requires_php ) ? $remote->requires_php : '7.0.0',
			'tested'        => isset( $remote->tested ) ? $remote->tested : '',
			'download_link' => ! empty( $remote->download_url ) ? $remote->download_url : '',
			'sections'      => [
				'changelog' => isset( $remote->changelog ) ? $remote->changelog : '',
			],
			'last_updated'  => isset( $remote->last_updated ) ? $remote->last_updated : '',
		];
	}

	public function clear_cache( $upgrader, $options ) {
		if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
			delete_transient( $this->cache_key );
		}
	}

	private function get_update_info() {
		$cached = get_transient( $this->cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$url = add_query_arg(
			[
				'plugin_slug' => $this->plugin_slug,
				'version'     => $this->version,
			],
			self::API_BASE_URL . '/updates/check'
		);

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 15,
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if ( ! $body || empty( $body->success ) ) {
			return false;
		}

		set_transient( $this->cache_key, $body, 3 * HOUR_IN_SECONDS );

		return $body;
	}
}
