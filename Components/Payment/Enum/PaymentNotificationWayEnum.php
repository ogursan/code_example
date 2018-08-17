<?php

namespace Shopen\AppBundle\Components\Payment\Enum;


use Shopen\AppBundle\Enums\AbstractEnum;

class PaymentNotificationWayEnum extends AbstractEnum
{

    const GATEWAY_TO_SHOP = 1;
    const SHOP_TO_GATEWAY = 2;

    public static function getPossibleValues()
    {
        return [
            self::GATEWAY_TO_SHOP,
            self::SHOP_TO_GATEWAY,
        ];
    }

    public static function getReadables()
    {
        return [
            self::GATEWAY_TO_SHOP => ' From gateway to shop',
            self::SHOP_TO_GATEWAY  => 'From shop to gateway',
        ];
    }

}