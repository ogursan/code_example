<?php

namespace Shopen\AppBundle\Components\Payment\Payments\PayPal;

use Shopen\AppBundle\Enums\AbstractEnum;

class PayPalCurrencyEnum extends AbstractEnum
{

    const RUB = 'RUB';
    const CNY = 'CNY';


    /**
     * @inheritdoc
     */
    public static function getPossibleValues()
    {
        return [
            static::RUB,
            static::CNY,
            ];
    }

    /**
     * @inheritdoc
     */
    public static function getReadables()
    {
        return [
            static::RUB => static::RUB,
            static::CNY => static::CNY,
        ];
    }

}