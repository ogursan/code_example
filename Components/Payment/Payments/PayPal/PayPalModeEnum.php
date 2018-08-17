<?php

namespace Shopen\AppBundle\Components\Payment\Payments\PayPal;

use Shopen\AppBundle\Enums\AbstractEnum;

class PayPalModeEnum extends AbstractEnum
{
    const SANBOX = 'sandbox';
    const LIVE = 'live';

    /**
     * @inheritdoc
     */
    public static function getPossibleValues()
    {
        return [
            static::SANBOX,
            static::LIVE,
        ];
    }

    /**
     * @inheritdoc
     */
    public static function getReadables()
    {
        return [
            static::SANBOX => static::SANBOX,
            static::LIVE => static::LIVE,
        ];
    }

}