<?php

namespace Shopen\AppBundle\Components\Payment;

use Shopen\AppBundle\Components\Payment\CashRegister\OrangeData;
use Shopen\AppBundle\Components\Payment\CashRegister\VoidCheck;

/**
 * Фабрика кассовых аппаратов
 *
 * @package Shopen\AppBundle\Components\Payment
 */
class CashRegister
{
    /**
     * @var OrangeData
     */
    private $orangeData;

    public function __construct(OrangeData $orangeData)
    {
        $this->orangeData = $orangeData;
    }

    /**
     * @param $countryCode
     * @return null|CashRegisterInterface
     */
    public function forCountry($countryCode)
    {
        switch ($countryCode) {
            case 'ru':
                $cashRegister = $this->orangeData;
                break;
            default:
                $cashRegister = null;
                break;
        }

        return $cashRegister;
    }

}