<?php
namespace PetkaPay;

use PetkaPay\PetkaPay;

class Order
{
    private $order;

    public function __construct($order)
    {
        $this->order = $order;
    }

    public function toHash()
    {
        return $this->order;
    }

    public function __get($name)
    {
        return $this->order[$name];
    }

    public static function find($orderId, $options = array())
    {
        try {
            return PetkaPay::request('/paymentProcess/getStatus?paymentNumber=' . $orderId, 'GET', array());
        } catch (Exception $e) {
            return false;
        }
    }

    public static function create($params, $options = array())
    {
        try {
            return PetkaPay::request('/pay/start', 'POST', $params);
        } catch (Exception $e) {
            return false;
        }
    }
}
