<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class MorPOS_Client {

    private $base_url;
    private $client_id;
    private $client_secret_b64;
    private $api_key;
    private $merchant_id;
    private $sub_merchant_id;
    private $ssl_verify;

    public function __construct( $args ) {
        $this->base_url          = rtrim( $args['base_url'] ?? '', '/' );
        $this->client_id         = $args['client_id'] ?? '';
        $this->client_secret_b64 = $args['client_secret_b64'] ?? '';
        $this->api_key           = $args['api_key'] ?? '';
        $this->merchant_id       = $args['merchant_id'] ?? '';
        $this->sub_merchant_id   = $args['sub_merchant_id'] ?? '';
        $this->ssl_verify        = $args['ssl_verify'] ?? true;
    }

    public function generate_conversation_id( $prefix = 'YBS' ) {
        $digits = '';
        for ( $i = 0; $i < 17; $i++ ) { $digits .= wp_rand(0,9); }
        return strtoupper( preg_replace( '/[^A-Z]/', '', $prefix ) ) . $digits;
    }

    private function now_timestamp() {
        return date( 'YmdHis' );
    }

    private function build_headers() {
        $xTimestamp = $this->now_timestamp();

        $decoded = base64_decode( $this->client_secret_b64, true );
        if ( $decoded === false ) {
            $decoded = '';
        }
        $combined = $decoded . $xTimestamp;

        $sha_hex  = hash( 'sha256', $combined );
        $encoded  = base64_encode( $sha_hex );

        return array(
            'Content-Type'   => 'application/json',
            'User-Agent'     => 'WooCommerce/' . ( defined('WC_VERSION') ? WC_VERSION : 'unknown' ) . ' MorPOS/' . MORPOS_GATEWAY_VERSION,
            'X-ClientSecret' => $encoded,
            'X-ClientId'     => $this->client_id,
            'X-GrantType'    => 'client_credentials',
            'X-Scope'        => 'pf_write pf_read',
            'X-Timestamp'    => $xTimestamp,
        );
    }

    private function compute_sign( $payload ) {
        $flat = array(
            'conversationId'        => (string) ($payload['conversationId'] ?? ''),
            'merchantId'            => (string) ($payload['merchantId'] ?? ''),
            'returnUrl'             => (string) ($payload['returnUrl'] ?? ''),
            'failUrl'               => (string) ($payload['failUrl'] ?? ''),
            'paymentMethod'         => (string) ($payload['paymentMethod'] ?? ''),
            'language'              => (string) ($payload['language'] ?? ''),
            'paymentInstrumentType' => (string) ($payload['paymentInstrumentType'] ?? ''),
            'transactionType'       => (string) ($payload['transactionDetails']['transactionType'] ?? ''),
            'vftFlag'               => 'False',
            'installmentCount'      => (string) ($payload['transactionDetails']['installmentCount'] ?? 0),
            'amount'                => (string) ($payload['transactionDetails']['amount'] ?? ''),
            'currencyCode'          => (string) ($payload['transactionDetails']['currencyCode'] ?? ''),
            'pFSubMerchantId'       => (string) ($payload['extraParameter']['pFSubMerchantId'] ?? ''),
            'apiKey'                => (string) $this->api_key,
        );

        $concatenated = implode( ';', array_values( $flat ) );

        $hash_bytes = hash( 'sha256', $concatenated, true );
        return strtoupper( base64_encode( $hash_bytes ) );
    }

    public function create_hpp_redirect( $payload ) {
        if ( empty( $payload['conversationId'] ) || ! preg_match( '/^[A-Z]{3}\d{17}$/', $payload['conversationId'] ) ) {
            $payload['conversationId'] = $this->generate_conversation_id('YBS');
        }

        $payload['sign'] = $this->compute_sign( $payload );

        $url = rtrim($this->base_url, '/') . '/v1/HostedPayment/HostedPaymentRedirect';

        $args = array(
            'timeout'     => 45,
            'sslverify'   => $this->ssl_verify,
            'headers'     => $this->build_headers(),
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
        );

        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            $msg = $response->get_error_message();
            return new WP_Error( 'morpos_http_error', $msg );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $json = json_decode( $body, true );

        if ( $code >= 400 ) {
            $msg = $body;
            if ( is_array( $json ) && isset( $json['Message'] ) ) {
                $msg = $json['Message'];
                if ( isset( $json['Details'] ) ) { $msg .= ' | ' . $json['Details']; }
                if ( !empty($json['validationResults']) && is_array($json['validationResults']) ) {
                    foreach ($json['validationResults'] as $vr) {
                        if (!empty($vr['ErrorMessage'])) {
                            $msg .= ' (Validation: ' . $vr['ErrorMessage'] . ')';
                        }
                    }
                }
            }

            $known = array(
                'RVV0000999999' => __('ConversationId format is not correct.', 'woocommerce-gateway-morpos'),
            );
            if ( !empty($json['Code']) && isset($known[$json['Code']]) ) {
                $msg .= ' — ' . $known[$json['Code']];
            }

            return new WP_Error( 'morpos_http_status_' . $code, $msg );
        }

        return $json ? $json : $body;
    }

    public function extract_first_url( $mixed ) {
        if ( is_string( $mixed ) ) {
            if ( preg_match( '#https?://[^\s"]+#', $mixed, $m ) ) return $m[0];
            return false;
        }
        if ( is_array( $mixed ) ) {
            $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($mixed));
            foreach ( $it as $value ) {
                if ( is_string( $value ) && preg_match( '#https?://[^\s"]+#', $value, $m ) ) {
                    return $m[0];
                }
            }
        }
        return false;
    }
}
