<?php

/*
  Plugin Name: PetkaPay - Bitcoin payments
  Plugin URI: https://petkapay.com
  Description: Accept Bitcoin via PetkaPay in your WooCommerce store
  Version: 1.0.2
  Author: PetkaPay
  Author URI: https://petkapay.com
  License: MIT License
  License URI: https://github.com/petkapay/petkapay-woocommerce-plugin/blob/master/LICENSE
  Github Plugin URI: https://github.com/petkapay/petkapay-woocommerce-plugin
 */

define('PETKAPAY_WOOCOMMERCE_VERSION', '1.0.2');

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter('woocommerce_payment_gateways', 'add_petkapay_gateway');

function add_petkapay_gateway($methods) {
  $methods[] = 'WC_Gateway_PetkaPay'; // your class name is here
  return $methods;
}

add_action('plugins_loaded', 'petkapay_init');

function petkapay_init() {
  if (!class_exists('WC_Payment_Gateway')) {
    return;
  }

  define('PETKAPAY_PLUGIN_DIR', plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__)) . '/');

  require_once(__DIR__ . '/lib/PetkaPayAPI.php');

  class WC_Gateway_PetkaPay extends WC_Payment_Gateway {

    public function __construct() {
      global $woocommerce;

      $this->id = 'petkapay';
      $this->has_fields = false;
      $this->method_title = 'PetkaPay';
      $this->method_description = 'Bitcoin payments via PetkaPay.com';
      $this->icon = apply_filters('woocommerce_petkapay_icon', PETKAPAY_PLUGIN_DIR . 'assets/petkaPayWithBtc.svg');

      $this->init_form_fields();
      $this->init_settings();

      $this->title = $this->get_option('title');
      $this->description = $this->get_option('description');
      $this->btcAddress = $this->get_option('btcAddress');
      $this->merchantId = $this->get_option('merchantId');
      $this->merchantRefNumber = $this->get_option('merchantRefNumber');
      $this->signatureKey = $this->get_option('signatureKey');
      
      \PetkaPay\PetkaPayAPI::$API_URL = $this->get_option('apiUrl');

      // This action hook saves the settings
      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
      // Register a webhook
      add_action('woocommerce_api_wc_gateway_petkapay', array($this, 'process_payment_callback'));
    }

    public function admin_options() {
      echo '<h2>' . esc_html($this->get_method_title());
      wc_back_link(__('Return to payments', 'woocommerce'), admin_url('admin.php?page=wc-settings&tab=checkout'));
      echo '</h2>';
      $description = _e('Accept Bitcoin through PetkaPay.com and receive payments in EUR or CHF or BTC.<br>
              <a href="https://petkapay.com" target="_blank">petkapay.com</a><br>
        <a href="mailto:support@petkapay.com">support@petkapay.com</a>', 'woothemes');
      echo wp_kses_post(wpautop($description));
      echo '<table class="form-table">' . $this->generate_settings_html($this->get_form_fields(), false) . '</table>'; // WPCS: XSS ok.
    }

    public function process_admin_options() {
      $saved = parent::process_admin_options();

      $btcAddress = $this->get_option('btcAddress');
      if (!empty($btcAddress) && $this->btcAddress !== $btcAddress) {
        //get Merchant ID and secret
        //set api url again if it has changed in form
        \PetkaPay\PetkaPayAPI::$API_URL = $this->get_option('apiUrl');
        $merchant = \PetkaPay\PetkaPayAPI::createMerchant(array(
                    'btcAddress' => $btcAddress,
                    'refNumber' => $this->merchantRefNumber
        ));
        if (!$merchant) {
          $this->add_error('Failed to create Merchant Account');
        } else {
          $this->update_option("merchantId", $merchant->id);
          $this->update_option("merchantRefNumber", $merchant->refNumber);
          $this->update_option("signatureKey", $merchant->secretKey);
        }
      }

      $this->display_errors();

      return $saved;
    }

    public function init_form_fields() {
      $this->form_fields = array(
          'enabled' => array(
              'title' => __('Enable PetkaPay', 'woocommerce'),
              'label' => __('Enable Cryptocurrency payments via PetkaPay', 'woocommerce'),
              'type' => 'checkbox',
              'description' => '',
              'default' => 'no',
          ),
          'title' => array(
              'title' => __('Title', 'woocommerce'),
              'type' => 'text',
              'description' => __('The payment method title which a customer sees at the checkout.', 'woocommerce'),
              'default' => __('Bitcoin via PetkaPay', 'woocommerce'),
          ),
          'description' => array(
              'title' => __('Description', 'woocommerce'),
              'type' => 'textarea',
              'description' => __('The payment method description which a user sees at the checkout.', 'woocommerce'),
              'default' => __('Pay with Bitcoin'),
          ),
          'apiUrl' => array(
              'title' => __('API url', 'petkapay'),
              'type' => 'text',
              'description' => __('Use default https://api.petkapay.com/v1 or set your own if self-hosting', 'woocommerce'),
              'default' => 'https://api.petkapay.com/v1',
          ),
          'btcAddress' => array(
              'title' => __('Bitcoin Address', 'petkapay'),
              'type' => 'text',
              'description' => __('Your Bitcoin public key. You will receive payments to this address.', 'woocommerce'),
              'default' => '',
          ),
      );
    }

    public function process_payment($order_id) {
      global $woocommerce, $page, $paged;
      $order = new WC_Order($order_id);

      $merchantId = $this->merchantId;
      $amount = $order->get_total() * 100;
      $currency = get_woocommerce_currency();
      $refNumber = $order->get_id();
      $signData = $merchantId . $amount . $currency . $refNumber;

      $signature = hash_hmac('sha256', $signData, $this->signatureKey);

      $wcOrder = wc_get_order($order_id);

      $order = \PetkaPay\PetkaPayAPI::create(array(
                  'refNumber' => $refNumber,
                  'amount' => $amount,
                  'currency' => $currency,
                  'cancelUrl' => $order->get_cancel_order_url(),
                  'callbackUrl' => trailingslashit(get_bloginfo('wpurl')) . '?wc-api=wc_gateway_petkapay',
                  'successUrl' => add_query_arg('order-received', $order->get_id(), add_query_arg('key', $order->get_order_key(), $this->get_return_url($wcOrder))),
                  'payCurrency' => "BTC",
                  'signature' => $signature,
                  'merchantId' => $merchantId
      ));


      if ($order && $order->paymentNumber) {
        update_post_meta($order_id, 'petkapay_paymentNumber', $order->paymentNumber);
        return array(
            'result' => 'success',
            'redirect' => \PetkaPay\PetkaPayAPI::redirectUrl($order->paymentNumber),
        );
      } else {
        return array(
            'result' => 'fail',
        );
      }
    }

    public function process_payment_callback() {
      $request = $_REQUEST;

      global $woocommerce;

      $order = new WC_Order($request['refNumber']);


      try {
        if (!$order || !$order->get_id()) {
          throw new Exception('Order #' . $request['refNumber'] . ' does not exists');
        }

        $paymentNumber = get_post_meta($order->get_id(), 'petkapay_paymentNumber', true);
        $metaCurrentStatus = get_post_meta($order->get_id(), 'petkapay_status', true);

        $cgOrder = \PetkaPay\PetkaPayAPI::find($paymentNumber);

        if (!$cgOrder) {
          throw new Exception('PetkaPay Order #' . $order->get_id() . ' with paymentNumber ' . $paymentNumber . ' does not exists');
        }

        $merchantId = $this->merchantId;
        $amount = $order->get_total() * 100;
        $currency = $order->get_currency();
        $refNumber = $order->get_id();
        $signData = $merchantId . $amount . $currency . $paymentNumber;

        $signature = hash_hmac('sha256', $signData, $this->signatureKey);

        $requestSignature = $request['signature'];
        if (empty($requestSignature) || strcmp($signature, $requestSignature) !== 0) {
          throw new Exception('Callback signature does not match');
        }

        $requestStatus = $request['status'];

        switch ($requestStatus) {
          case 'PAID_CONFIRMED':
            if (!$metaCurrentStatus || $metaCurrentStatus != "Paid and confirmed") {
              $order->add_order_note(__('Payment is confirmed on the network. Purchased goods/services can be securely delivered to the buyer.', 'petkapay'));
              update_post_meta($order->get_id(), 'petkapay_status', "Paid and confirmed");
              $order->update_status("processing");
              $order->payment_complete();
            }
            break;
          case 'PAID':
            if (!$metaCurrentStatus || $metaCurrentStatus != "Processing") {
              $order->add_order_note(__('PetkaPay is processing payment. Waiting for network confirmations. Please wait...', 'petkapay'));
              update_post_meta($order->get_id(), 'petkapay_status', "Processing");
            }
            break;
          case 'RETURNED':
            if (!$metaCurrentStatus || $metaCurrentStatus != "Processing") {
              $order->add_order_note(__('PetkaPay has returned payment to customer.', 'petkapay'));
              update_post_meta($order->get_id(), 'petkapay_status', "Returned");
              $order->update_status("cancelled");
            }
            break;
        }
      } catch (Exception $e) {
        die(get_class($e) . ': ' . $e->getMessage());
      }
    }

  }

  /**
   * Add Settings link to the plugin entry in the plugins menu
   * */
  add_filter('plugin_action_links', 'petkapay_plugin_action_links', 10, 2);

  function petkapay_plugin_action_links($links, $file) {
    static $this_plugin;

    if (false === isset($this_plugin) || true === empty($this_plugin)) {
      $this_plugin = plugin_basename(__FILE__);
    }

    if ($file == $this_plugin) {
      $settings_link = '<a href="' . get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-settings&tab=checkout&section=wc_gateway_petkapay">Settings</a>';
      array_unshift($links, $settings_link);
    }

    return $links;
  }

}
