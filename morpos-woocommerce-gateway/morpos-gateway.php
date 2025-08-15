<?php
/**
 * Plugin Name: MorPOS
 * Description: MorPOS Hosted Payment Page (HPP).
 * Version: 0.0.1
 * Author: Hamide Kaya
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'MORPOS_GATEWAY_VERSION', '0.0.1' );
define( 'MORPOS_GATEWAY_PATH', plugin_dir_path( __FILE__ ) );
define( 'MORPOS_GATEWAY_URL', plugin_dir_url( __FILE__ ) );

add_filter( 'woocommerce_payment_gateways', function( $methods ) {
    $methods[] = 'WC_Gateway_MorPOS';
    return $methods;
});

add_action( 'plugins_loaded', function() {
    if ( ! class_exists( 'WC_Payment_Gateway' ) ) { return; }
    require_once MORPOS_GATEWAY_PATH . 'includes/class-morpos-client.php';
    require_once MORPOS_GATEWAY_PATH . 'includes/class-wc-gateway-morpos.php';
});

//Optional
add_action( 'http_api_curl', function( $handle, $r, $url ) {
    $opts = get_option( 'woocommerce_morpos_settings', array() );
    $ca_path = isset( $opts['ca_bundle_path'] ) ? trim( $opts['ca_bundle_path'] ) : '';
    $ssl_verify = isset( $opts['ssl_verify'] ) ? $opts['ssl_verify'] === 'yes' : true;

    if ( $ca_path && file_exists( $ca_path ) ) {
        curl_setopt( $handle, CURLOPT_CAINFO, $ca_path );
    }
    if ( ! $ssl_verify ) {
        curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 0 );
    }
}, 10, 3);
