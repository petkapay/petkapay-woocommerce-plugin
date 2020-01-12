<?php
/*
  Plugin Name: PetkaPay - Bitcoin payments
  Plugin URI: https://petkapay.com
  Description: Accept Bitcoin via PetkaPay in your WooCommerce store
  Version: 1.0.1
  Author: PetkaPay
  Author URI: https://petkapay.com
  License: MIT License
  License URI: https://github.com/petkapay/petkapay-woocommerce-plugin/blob/master/LICENSE
  Github Plugin URI: https://github.com/petkapay/petkapay-woocommerce-plugin
 */

add_action('plugins_loaded', 'petkapay_init');

define('PETKAPAY_WOOCOMMERCE_VERSION', '1.0.2');

function petkapay_init() {
  if (!class_exists('WC_Payment_Gateway')) {
    return;
  }

  define('PLUGIN_DIR', plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__)) . '/');

  require_once(__DIR__ . '/lib/init.php');

  class WC_Gateway_PetkaPay extends WC_Payment_Gateway {

    public function __construct() {
      global $woocommerce;

      $this->id = 'petkapay';
      $this->has_fields = false;
      $this->method_title = 'PetkaPay';
      $this->method_description = 'Bitcoin payments via PetkaPay.com';
      $this->icon = apply_filters('woocommerce_petkapay_icon', PLUGIN_DIR . 'assets/petkaPayWithBtc.svg');

      $this->init_form_fields();
      $this->init_settings();

      $this->title = $this->get_option('title');
      $this->description = $this->get_option('description');
      $this->merchant_id = $this->get_option('merchant_id');
      $this->signatureKey = $this->get_option('signatureKey');

      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
      add_action('woocommerce_api_wc_gateway_petkapay', array($this, 'payment_callback'));
    }

    public function admin_options() {
      ?>
      <h3><?php _e('PetkaPay', 'woothemes'); ?></h3>
      <p><?php _e('Accept Bitcoin through PetkaPay.com and receive payments in EUR or CHF or BTC.<br>
              <a href="https://petkapay.com" target="_blank">petkapay.com</a><br>
        <a href="mailto:support@petkapay.com">support@petkapay.com</a>', 'woothemes'); ?></p>
      <table class="form-table">
        <?php $this->generate_settings_html(); ?>
      </table>
        <?php
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
                'default' => __('Pay with Bitcoin | Litecoin | Ethereum'),
            ),
            'merchant_id' => array(
                'title' => __('Merchant ID', 'woocommerce'),
                'type' => 'text',
                'description' => __('PetkaPay Merchant ID. Get it from <a href="https://app.petkapay.com/settings" target="_blank">your PetkaPay account</a>.', 'woocommerce'),
                'default' => '',
            ),
            'signatureKey' => array(
                'title' => __('Signature key', 'woocommerce'),
                'type' => 'text',
                'description' => __('Signature key. Get it from <a href="https://app.petkapay.com/settings" target="_blank">your PetkaPay account</a>.', 'woocommerce'),
                'default' => '',
            ),
        );
      }

      public function process_payment($order_id) {
        global $woocommerce, $page, $paged;
        $order = new WC_Order($order_id);

        $this->init_petkapay();

        $merchantId = $this->merchant_id;
        $amount = $order->get_total() * 100;
        $currency = get_woocommerce_currency();
        $refNumber = $order->get_id();
        $signData = $merchantId . $amount . $currency . $refNumber;

        $signature = hash_hmac('sha256', $signData, $this->signatureKey);

        $wcOrder = wc_get_order($order_id);

        $order = \PetkaPay\Order::create(array(
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
              'redirect' => "https://api.petkapay.com/html/paymentFrame.html?paymentNumber=" . $order->paymentNumber,
          );
        } else {
          return array(
              'result' => 'fail',
          );
        }
      }

      public function payment_callback() {
        $request = $_REQUEST;

        global $woocommerce;

        $order = new WC_Order($request['refNumber']);


        try {
          if (!$order || !$order->get_id()) {
            throw new Exception('Order #' . $request['refNumber'] . ' does not exists');
          }

          $paymentNumber = get_post_meta($order->get_id(), 'petkapay_paymentNumber', true);
          $metaCurrentStatus = get_post_meta($order->get_id(), 'petkapay_status', true);

          $this->init_petkapay();
          $cgOrder = \PetkaPay\Order::find($paymentNumber);

          if (!$cgOrder) {
            throw new Exception('PetkaPay Order #' . $order->get_id() . ' with paymentNumber ' . $paymentNumber . ' does not exists');
          }

          $merchantId = $this->merchant_id;
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
          }
        } catch (Exception $e) {
          die(get_class($e) . ': ' . $e->getMessage());
        }
      }

      private function init_petkapay() {
        \PetkaPay\PetkaPay::config(
                array(
                    'user_agent' => ('PetkaPay - WooCommerce v' . WOOCOMMERCE_VERSION . ' Plugin v' . PETKAPAY_WOOCOMMERCE_VERSION),
                )
        );
      }

    }

    function add_petkapay_gateway($methods) {
      $methods[] = 'WC_Gateway_PetkaPay';

      return $methods;
    }

    add_filter('woocommerce_payment_gateways', 'add_petkapay_gateway');

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
  