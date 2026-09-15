<?php

namespace ZionBuilder;

use ZionBuilder\CommonJS;

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

/**
 * Class Install
 *
 * @package ZionBuilder
 */
class Screenshot {
	const URL_ARGUMENT        = 'zionbuilder-generate-screenshot';
	const PROXY_URL_ARGUMENT  = 'zionbuilder-proxy';
	const PROXY_URL_NONCE_KEY = 'zionbuilder-proxy-nonce';
	const PROXY_ASSET_PARAM   = 'zionbuilder-asset';

	public function __construct() {
		if ( isset( $_GET[self::URL_ARGUMENT] ) && $_GET[self::URL_ARGUMENT] === '1' ) {
			add_action( 'wp_enqueue_scripts', [ $this, 'on_enqueue_scripts' ] );
		}
		if ( Permissions::user_allowed_edit() && isset( $_GET[self::PROXY_URL_NONCE_KEY] ) && wp_verify_nonce( $_GET[self::PROXY_URL_NONCE_KEY], self::PROXY_URL_NONCE_KEY ) && isset( $_GET[self::PROXY_URL_ARGUMENT] ) && isset( $_GET[self::PROXY_ASSET_PARAM] ) ) {
			$this->print_proxy_asset( $_GET[self::PROXY_ASSET_PARAM] );
		}
	}

	public function print_proxy_asset( $asset ) {
		// Validate URL scheme to prevent file://, gopher://, etc.
		$scheme = wp_parse_url( $asset, PHP_URL_SCHEME );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			wp_die( esc_html__( 'Invalid URL scheme.', 'zionbuilder' ), 403 );
		}

		// Block requests to private/internal IP ranges to mitigate SSRF
		$host = wp_parse_url( $asset, PHP_URL_HOST );
		if ( empty( $host ) ) {
			wp_die( esc_html__( 'Invalid proxy URL.', 'zionbuilder' ), 403 );
		}

		$ip = gethostbyname( $host );
		if ( $ip !== $host && filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_NO_LOOPBACK ) === false ) {
			wp_die( esc_html__( 'Proxy requests to internal addresses are not allowed.', 'zionbuilder' ), 403 );
		}

		$response = wp_remote_get( mb_convert_encoding( $asset, 'ISO-8859-1', 'UTF-8' ) );

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$headers = wp_remote_retrieve_headers( $response );

		header( 'content-type: ' . $headers['content-type'] );

		echo wp_remote_retrieve_body( $response ); // phpcs:ignore -- We output the asset data
		die;
	}

	public function on_enqueue_scripts() {
		// Load Scripts
		Plugin::instance()->scripts->enqueue_style(
			'zb-screenshot',
			'screenshot',
			[],
			Plugin::instance()->get_version()
		);

		// Load Scripts
		Plugin::instance()->scripts->enqueue_script(
			'zb-screenshot',
			'screenshot',
			[],
			Plugin::instance()->get_version(),
			true
		);

		wp_localize_script(
			'zb-screenshot',
			'ZnPbScreenshotData',
			[
				'home_url'  => home_url(),
				'is_debug'  => Environment::is_debug(),
				'constants' => [
					'PROXY_URL_ARGUMENT'  => self::PROXY_URL_ARGUMENT,
					'PROXY_URL_NONCE_KEY' => self::PROXY_URL_NONCE_KEY,
					'PROXY_ASSET_PARAM'   => self::PROXY_ASSET_PARAM,
				],
				'assets'    => [
					'placeholder_iframe' => Utils::get_file_url( 'assets/img/frame.svg' ),
				],
				'nonce_key' => wp_create_nonce( self::PROXY_URL_NONCE_KEY ),
			]
		);
	}
}
