<?php

namespace App\Services;

use App\Services\Gateway\{
    AopF2F,
    Codepay,
	Vmqpay,
    PaymentWall,
    SPay,
    PAYJS,
    BitPayX,
    THeadPay,
    CoinPay,
    EasyPay
};

class Payment
{
    public static function getClient()
    {
        $method = $_ENV['payment_system'];
        switch ($method) {
            case ('codepay'):
                return new Codepay();
            case ('vmqpay'):
                return new Vmqpay();
            case ('paymentwall'):
                return new PaymentWall();
            case ('spay'):
                return new SPay();
            case ('f2fpay'):
                return new AopF2F();
            case ('payjs'):
                return new PAYJS($_ENV['payjs_key']);
            case ('bitpayx'):
                return new BitPayX($_ENV['bitpay_secret']);
            case ('theadpay'):
                return new THeadPay();
            case ('coinpay'):
                return new CoinPay(Config::get('coinpay_secret'), Config::get('coinpay_appid'));
            case ("easypay"):
                return new EasyPay(Config::get('easypay_app_secret'));
            default:
                return null;
        }
    }

    public static function notify($request, $response, $args)
    {
        return self::getClient()->notify($request, $response, $args);
    }

    public static function returnHTML($request, $response, $args)
    {
        return self::getClient()->getReturnHTML($request, $response, $args);
    }

    public static function purchaseHTML()
    {
        if (self::getClient() != null) {
            return self::getClient()->getPurchaseHTML();
        }

        return '';
    }

    public static function getStatus($request, $response, $args)
    {
        return self::getClient()->getStatus($request, $response, $args);
    }

    public static function purchase($request, $response, $args)
    {
        return self::getClient()->purchase($request, $response, $args);
    }
}
