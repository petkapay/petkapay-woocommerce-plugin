<?php
namespace PetkaPay;

class PetkaPayAPI
{
    public static $API_URL = '';

    public static $user_agent  = 'PetkaPay - WooCommerce v' . WOOCOMMERCE_VERSION . ' Plugin v' . PETKAPAY_WOOCOMMERCE_VERSION;

    public static function find($orderId, $options = array()) {
    try {
      return PetkaPayAPI::request('/payment/getStatus?paymentNumber=' . $orderId, 'GET', array());
    } catch (\Exception $e) {
      error_log($e->getMessage());
      return false;
    }
  }

  public static function redirectUrl($orderId, $options = array()) {
    return PetkaPayAPI::$API_URL . '/html/paymentFrame.html?paymentNumber=' . $orderId;
  }

  public static function create($params, $options = array()) {
    try {
      return PetkaPayAPI::request('/payment/start', 'POST', $params);
    } catch (\Exception $e) {
      error_log($e->getMessage());
      return false;
    }
  }

  public static function createMerchant($params, $options = array()) {
    try {
      return PetkaPayAPI::request('/merchant/create', 'POST', $params);
    } catch (\Exception $e) {
      error_log($e->getMessage());
      return false;
    }
  }

    public static function request($url, $method = 'POST', $params = array())
    {
        $user_agent  = self::$user_agent;
        $curlopt_ssl_verifypeer = false;

        $url       = self::$API_URL . $url;
        $headers   = array();
        $curl      = curl_init();

        $curl_options = array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL            => $url
        );

        if ($method == 'POST') {
            $headers[] = 'Content-Type: application/json';
            array_merge($curl_options, array(CURLOPT_POST => 1));
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($params));
        }

        curl_setopt_array($curl, $curl_options);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_USERAGENT, $user_agent);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, $curlopt_ssl_verifypeer);

        $response    = json_decode(curl_exec($curl));
        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        if ($http_status === 200)
            return $response;
        else
            throw new \Exception($url . '-' . $http_status . '-' . print_r($response, true) . '-' . $error);
    }
}
