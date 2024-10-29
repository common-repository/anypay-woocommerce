<?php
/**
 * @package Anypay_WooCommerce
 * Version: 0.7.0
 * Plugin Name: Anypay WooCommerce
 * Plugin URI: http://wordpress.org/plugins/anypay-woocommerce/
 * Description: Bitcoin and multi-coin checkout for e-commerce stores.
 * Author: Anypay
 * Author URI: https://anypayx.com
 * Contributors: owenkellogg, brandonbryant, eddiewillers, pshenmic
 * Tags: bitcoin, payments, BSV
 * Requires PHP: 5.6
 * Requires at least: 4.0
 * Stable Tag: 0.7.0
 * Tested up to: 6.2.2
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 **/

if ( ! in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) return;

add_filter( 'woocommerce_payment_gateways', 'add_anypay_gateway_class' );

function add_anypay_gateway_class($methods) {
    $methods[] = 'WC_Gateway_Anypay';
    return $methods;
}

global $anypay_db_version;

$anypay_db_version = '1.0.0';

function anypay_install()
{

    global $wpdb;
    global $anypay_db_version;

    $invoice_table = $wpdb->prefix . 'woocommerce_anypay_invoices';
    $charset_collate = $wpdb->get_charset_collate();

    $sql .= "CREATE TABLE $invoice_table (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        order_id mediumint(9) NOT NULL,
        uid text NOT NULL,
        account_id mediumint(9) NOT NULL,
        amount float NOT NULL,
        currency text NOT NULL,
        status text NOT NULL,
        hash text,
        output_hash text,
        amount_paid float,
        denomination text NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    dbDelta($sql);

    add_option('anypay_db_version', $anypay_db_version);

}

register_activation_hook(__FILE__, 'anypay_install');

add_action( 'plugins_loaded', 'init_anypay_gateway_class' );

function init_anypay_gateway_class() {

  class WC_Gateway_Anypay extends WC_Payment_Gateway {
    public function __construct() {
      $settings = $this->get_option('woocommerce_anypay_settings');
      $account_id = $settings->account_id;

      $this->id = 'wc_gateway_anypay';
      $this->has_fields = false;
      $this->description = 'Pay with Bitcoin via Anypay';
      $this->title = 'Anypay';
      $this->icon = 'https://bico.media/9aa6da6372c5a706339cf6d0e8343ff8d48e45f381bc3e3d4e126cc7f9e2a8bf.png';
      $this->order_button_text = __('Pay with Anypay', 'woocommerce');
      $this->method_title = 'Anypay';
      $this->method_description = sprintf( 'Simple Bitcoin Checkout for WordPress.' );
      $this->supports = array('products');

      // Load the settings.
	  $this->init_form_fields();
      $this->init_settings();

      add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
      add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'save_anypay_settings' ) );
      add_action('woocommerce_api_'.strtolower(get_class($this)), array(&$this, 'handle_callback'));


    }


    function admin_options() {
     ?>
       <h2><?php _e('Anypay Payment Gateway Settings','woocommerce'); ?></h2>
       <table class="form-table">
       <?php $this->generate_settings_html(); ?>
       </table> <?php

    }

    public function handle_callback() {
      $raw_post = file_get_contents( 'php://input' );

	  $decoded  = json_decode( $raw_post );

      $safe_status = sanitize_text_field($decoded->status);

      $safe_uid = sanitize_text_field($decoded->uid);

      if (strlen($safe_uid) > 36 ) {
        $safe_uid = substr( $safe_uid, 0 , 36);
      }

      $safe_hash = sanitize_text_field($decoded->hash);

      if (strlen($safe_hash) > 64) {
        $safe_hash = substr($safe_hash, 0, 64);
      }

      $safe_invoice_amount_paid = floatval($decoded->invoice_amount_paid);

      $safe_output_hash = sanitize_text_field($decoded->output_hash);

      if (strlen($safe_output_hash) > 64) {
        $safe_output_hash = substr($safe_output_hash, 0, 64);
      }

      $safe_external_id = intval($decoded->external_id);

      if ($decoded->status === 'paid') {
        $order = wc_get_order( $safe_external_id  );

        $order->payment_complete($safe_uid);
      }

      $this->anypay_updateInvoice($safe_uid, $safe_hash, $safe_status, $safe_invoice_amount_paid, $safe_output_hash);

      exit();
	}

    /**
	 * Processes and saves options.
	 * If there is an error thrown, will continue to save and validate fields, but will leave the erroring field out.
	 *
	 * @return bool was anything saved?
	 **/
     function save_anypay_settings() {

      $my_string = 'Save Settings';
      do_action( 'php_console_log', $my_string );

      $anypay_settings = $this->get_option('woocommerce_anypay_settings');

      if (!$anypay_settings) {
        $anypay_settings = array();
      }

      $api_key =  esc_attr($_POST['woocommerce_wc_gateway_anypay_api_key']);
      echo '<script>console.log("API key: ' . $api_key . '")</script>';

      $anypay_settings['api_key'] = $api_key;

      $this->update_option('woocommerce_anypay_settings', $anypay_settings);
    }

    /**
	 * Initialise Gateway Settings Form Fields.
     */
	function init_form_fields() {
     $this->form_fields = array(
        'api_key' => array(
        'title' => 'Anypay API key',
        'type' => 'text',
        'description' => 'All payments will be sent to the coin addresses you set on your Anypay account. No
        account? <a target="_blank" href="https://anypayx.com/auth/register/">Register for free</a>',
        'default' => ''
        ),
	  );
     }

     public function payment_fields() {
       $anypay_settings = $this->get_option('woocommerce_anypay_settings');

       try {
         $coins = $anypay_settings['coins'];

         echo '<script>console.log("Coins: ' . esc_attr( $anypay_settings['coins'] ) . '")</script>';

         //echo '<p class="pwe-eth-pricing-note"><strong>';

         //printf( __( 'Payment available in %s', implode(', ', $coins) ), $response );

         //echo '</p></strong>';
        } catch ( \Exception $e ) {
        echo '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout">';
        echo '<ul class="woocommerce-error">';
        echo '<li>';
        _e(
            'Unable to provide an order value in BSV at this time.',
            'anypay'
        );
        echo '</li>';
        echo '</ul>';
        echo '</div>';
        }
    }

    function process_payment( $order_id ) {
      global $woocommerce;

      $anypay_settings = $this->get_option('woocommerce_anypay_settings');

      $order = new WC_Order( $order_id );

      $api_key = esc_attr( $anypay_settings['api_key'] );

      $args = array(
        'body' => array(
        'amount' =>(float) $order->get_total(),
        'redirect_url' => $this->get_return_url( $order ),
        'webhook_url' => home_url('/?wc-api=wc_gateway_anypay'),
        'external_id' => $order->get_id(),
        'wordpress_site_url' => get_site_url()
      ),
      'headers' => array(
        'Authorization' => 'Basic ' . base64_encode( $api_key . ':'),
        'Content-Type' => 'application/x-www-form-urlencoded'
        )
      );

      $url = 'https://api.anypayx.com/invoices';

      $result = wp_remote_post( $url, $args );

      if ($result['response']['code'] === 200 ) {
        // Mark as on-hold (awaiting the bitcoin payment)
        $order->update_status('pending-payment', __( 'Awaiting bitcoin payment', 'woocommerce' ));

        // Remove cart
        $woocommerce->cart->empty_cart();

        $body = json_decode($result['body']);
        $success = $body->success;
        $invoice = $body->invoice;

        if ($success) {
          $safe_status = sanitize_text_field($invoice->status);
          $safe_uid = sanitize_text_field($invoice->uid);

          $safe_account_id = intval($invoice->account_id);

          $safe_amount = floatval($invoice->amount);

          $safe_currency = sanitize_text_field($invoice->currency);

          $safe_denomination_currency = sanitize_text_field($invoice->denomination_currency);

          $this->anypay_createInvoice( $order->get_id(), $safe_uid, $safe_account_id, $safe_amount, $safe_currency, $safe_status, $safe_denomination_currency);

          return array(
           'result'   => 'success',
           'redirect' => 'https://anypayx.com/i/' . $safe_uid
          );
        }
      }
    }

    public function anypay_createInvoice($order_id, $uid, $account_id, $amount, $currency, $status, $denomination) {
     global $wpdb;

     $invoice_table = $wpdb->prefix . 'woocommerce_anypay_invoices';

     if (!is_null($order_id)) {
       $order_id = esc_sql($order_id);
     }

     if (!is_null($uid)) {
       $uid = esc_sql($uid);
     }

     if(!is_null($account_id)){
       $account_id = esc_sql($account_id);
     }

     if(!is_null($amount)) {
       $amount = esc_sql($amount);
     }

     if(!is_null($currency)) {
       $currency = esc_sql($currency);
     }

     if (!is_null($status)){
       $status = esc_sql($status);
     }

     if (!is_null($denomination)) {
       $denomination = esc_sql($denomination);
     }

     return $wpdb->insert($invoice_table, array(
       'uid' => $uid,
       'account_id' => $account_id,
       'status' => $status,
       'order_id' => $order_id,
       'amount' => $amount,
       'denomination' => $denomination,
       'currency' => $currency));
    }

    public function anypay_updateInvoice($where_uid, $hash, $status, $amount_paid, $output_hash) {
     global $wpdb;

     $invoices_table = $wpdb->prefix . 'woocommerce_anypay_invoices';
     if (!is_null($hash)) {
       $hash = esc_sql($hash);
     }

     if (!is_null($where_uid)) {
       $where_uid = esc_sql($where_uid);
     }

     if (!is_null($status)) {
       $status = esc_sql($status);
     }

     if (!is_null($amount_paid)) {
       $amount_paid = esc_sql($amount_paid);
     }

     if (!is_null($output_hash)) {
       $output_hash = esc_sql($output_hash);
     }

     $update_query = array(
       'hash' => $hash,
       'status' => $status,
       'amount_paid' => $amount_paid,
       'output_hash' => $output_hash
     );

     $where = array(
       'uid' => $where_uid
     );

     return $wpdb->update($invoices_table, $update_query, $where);
   }
  }
}
