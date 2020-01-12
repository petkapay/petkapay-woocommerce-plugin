<?php
namespace PetkaPay;

class PetkaPay
{
    const VERSION           = '1.0.2';
    const USER_AGENT_ORIGIN = 'PetkaPay PHP Library';

    public static $user_agent  = '';

    public static function config($config)
    {
        if (isset($config['user_agent']))
            self::$user_agent = $config['user_agent'];
    }

    public static function request($url, $method = 'POST', $params = array())
    {
        $user_agent  = self::$user_agent;
        $curlopt_ssl_verifypeer = false;

        $url       = 'https://api.petkapay.com' . $url;
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

        if ($http_status === 200)
            return $response;
        else
            throw new \Exception(isset($response->message) ? $response->message : json_decode($headers));
    }
}
