<?php

namespace Shopen\AppBundle\Components\Payment\Payments\PayPal;

use Shopen\AppBundle\Enums\AbstractEnum;

class PayPalLocaleEnum extends AbstractEnum
{

    const DEFLT = 'en_US';
    const RU = 'ru_RU';
    const ZH = 'zh_CN';
    const DE = 'de_DE';
    const EN = 'en_US';
    const IT = 'it_IT';
    const FR = 'fr_FR';
    const PT = 'pt_PT';
    const PL = 'pl_PL';

    public static function getPossibleValues()
    {
        return [
            self::RU,
            self::ZH,
            self::DE,
            self::EN,
            self::EN,
            self::IT,
            self::FR,
            self::PT,
            self::PL,
        ];
    }

    public static function getReadables()
    {
        return [
            self::RU => self::RU,
            self::ZH => self::ZH,
            self::DE => self::DE,
            self::EN => self::EN,
            self::IT => self::IT,
            self::FR => self::FR,
            self::PT => self::PT,
            self::PL => self::PL,
        ];
    }

}