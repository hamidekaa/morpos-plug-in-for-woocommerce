<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Gateway_MorPOS extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = 'morpos';
        $this->method_title       = 'MorPOS';
        $this->method_description = __('MorPOS Hosted Payment Page Integration', 'morpos');
        $this->has_fields         = false;
        $this->supports           = array( 'products' );

        $this->init_form_fields();
        $this->init_settings();

        add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'handle_return' ) );

        $this->title            = $this->get_option( 'title', 'MorPOS (Credit/Debit Card)' );
        $this->description      = $this->get_option( 'description', '' );
        $this->enabled          = $this->get_option( 'enabled', 'no' );
        $this->environment      = $this->get_option( 'environment', 'preprod' );
        $this->base_url         = $this->get_option( 'base_url', 'https://finagopay-pf-api-gateway.prp.morpara.com' );
        $this->client_id        = $this->get_option( 'client_id', '' );
        $this->client_secret_b64= $this->get_option( 'client_secret_b64', '' );
        $this->api_key          = $this->get_option( 'api_key', '' );
        $this->merchant_id      = $this->get_option( 'merchant_id', '' );
        $this->sub_merchant_id  = $this->get_option( 'sub_merchant_id', '' );
        $this->ssl_verify       = $this->get_option( 'ssl_verify', 'yes' );
        $this->ca_bundle_path   = $this->get_option( 'ca_bundle_path', '' );

        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
    }

    public function admin_notices() {
        if ( 'yes' !== $this->enabled ) { return; }
        $missing = array();
        foreach ( array(
            'base_url' => 'Base URL',
            'client_id' => 'Client ID',
            'client_secret_b64' => 'Client Secret (Base64)',
            'api_key' => 'API Key (Base64)',
            'merchant_id' => 'Merchant Id',
        ) as $k => $label ) {
            if ( ! $this->get_option( $k ) ) { $missing[] = $label; }
        }
        if ( $missing ) {
            echo '<div class="notice notice-error"><p><b>MorPOS:</b> Pliase fill in the following settings: '. esc_html( implode(', ', $missing) ) .'</p></div>';
        }
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title'   => __( 'Active', 'morpos' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable payment for MorPOS', 'morpos' ),
                'default' => 'no',
            ),
            'title' => array(
                'title'       => __( 'Title', 'morpos' ),
                'type'        => 'text',
                'default'     => 'MorPOS (Credit/Debit Card)',
            ),
            'description' => array(
                'title'       => __( 'Description', 'morpos' ),
                'type'        => 'textarea',
                'default'     => '',
            ),
            'environment' => array(
                'title'       => __( 'Environment', 'morpos' ),
                'type'        => 'select',
                'description' => __( 'PreProd veya Prod', 'morpos' ),
                'default'     => 'preprod',
                'options'     => array(
                    'preprod' => 'PreProd',
                    'prod'    => 'Prod'
                )
            ),
            'base_url' => array(
                'title'       => __( 'Base URL', 'morpos' ),
                'type'        => 'text',
                'default'     => 'https://finagopay-pf-api-gateway.prp.morpara.com',
            ),
            'client_id' => array(
                'title'       => __( 'X-ClientId', 'morpos' ),
                'type'        => 'text',
            ),
            'client_secret_b64' => array(
                'title'       => __( 'Client Secret (Base64)', 'morpos' ),
                'type'        => 'password',
            ),
            'api_key' => array(
                'title'       => __( 'API Key (Base64)', 'morpos' ),
                'type'        => 'password',
            ),
            'merchant_id' => array(
                'title'       => __( 'Merchant Id', 'morpos' ),
                'type'        => 'text',
            ),
            'sub_merchant_id' => array(
                'title'       => __( 'PF SubMerchant Id', 'morpos' ),
                'type'        => 'text',
            ),
            'ssl_verify' => array(
                'title'       => __( 'SSL Verification', 'morpos' ),
                'type'        => 'checkbox',
                'label'       => __( 'SSL certificate verification', 'morpos' ),
                'default'     => 'yes',
            ),
            'ca_bundle_path' => array(
                'title'       => __( 'CA Bundle', 'morpos' ),
                'type'        => 'text',
                'description' => __( 'Your Choise: If empty the system uses the default.', 'morpos' ),
            ),
        );
    }

    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        $callback = WC()->api_request_url( get_class( $this ) );
$return_url = add_query_arg(array(
    'status'   => 'success',
    'order_id' => $order->get_id(),
    'key'      => $order->get_order_key(),
), $callback);

$fail_url = add_query_arg(array(
    'status'   => 'fail',
    'order_id' => $order->get_id(),
    'key'      => $order->get_order_key(),
), $callback);

        $client = new MorPOS_Client(array(
            'base_url'         => $this->base_url,
            'client_id'        => $this->client_id,
            'client_secret_b64'=> $this->client_secret_b64,
            'api_key'          => $this->api_key,
            'merchant_id'      => $this->merchant_id,
            'sub_merchant_id'  => $this->sub_merchant_id,
            'ssl_verify'       => $this->ssl_verify === 'yes',
        ));

        $amount = wc_format_decimal( $order->get_total(), 2 );
        $currency = get_woocommerce_currency();
        $currency_code = $currency === 'TRY' ? '949' : '949';

        $conversation_id = $client->generate_conversation_id('YBS');

        $payload = array(
            'merchantId'          => $this->merchant_id,
            'returnUrl'           => $return_url,
            'failUrl'             => $fail_url,
            'paymentMethod'       => 'HOSTEDPAYMENT',
            'paymentInstrumentType'=> 'CARD',
            'language'            => 'tr',
            'conversationId'      => $conversation_id,
            'transactionDetails'  => array(
                'transactionType' => 'SALE',
                'installmentCount'=> 0,
                'amount'          => number_format( (float) $amount, 2, '.', '' ),
                'currencyCode'    => $currency_code,
                'vftFlag'         => false,
            ),
            'extraParameter'      => array(
                'pFSubMerchantId' => (string) $this->sub_merchant_id,
            ),
        );

        $result = $client->create_hpp_redirect( $payload );

        if ( is_wp_error( $result ) ) {
            wc_add_notice( __( 'Payment could not be initiated: ', 'morpos' ) . $result->get_error_message(), 'error' );
            return array( 'result' => 'fail' );
        }

        $redirect = $client->extract_first_url( $result );
        if ( ! $redirect ) {
            wc_add_notice( __( 'Payment page not found', 'morpos' ) . esc_html( json_encode( $result ) ), 'error' );
            return array( 'result' => 'fail' );
        }

        $order->update_status( 'on-hold', __( 'Redirect to MorPOS page', 'morpos' ) );

        return array(
            'result'   => 'success',
            'redirect' => $redirect,
        );
    }


    public function handle_return() {
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $status   = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $key      = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';

        if ( ! $order_id || ! $status || ! $key ) {
            wp_die( 'Invalid callback parameters.' );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order || $order->get_order_key() !== $key ) {
            wp_die( 'Order not found or key mismatch.' );
        }

        if ( $status === 'success' ) {
            //Optional
            $order->payment_complete();
            wc_reduce_stock_levels( $order_id );
            WC()->cart->empty_cart();

            wp_safe_redirect( $this->get_return_url( $order ) );
            exit;
        } else {
            $order->update_status( 'failed', 'MorPOS: Fail or cancel.' );
            wc_add_notice( __( 'Payment Failed', 'morpos' ), 'error' );
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        }
    }
}
