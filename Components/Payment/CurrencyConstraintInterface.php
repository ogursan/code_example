<?php

namespace Shopen\AppBundle\Components\Payment;

use Shopen\AppBundle\Entity\Currency;

/**
 * Интерфейс платёжной системы, имеющей ограничения по валюте приёма платежей.
 *
 * Подобная система может быть способна осуществлять приём разных валют, но, в силу каких-либо законодательных
 * или организационных особенностей, мы можем не можем принимать через неё платежи в разных валютах
 * и вынуждены прибегать к конвертации.
 *
 * @package Shopen\AppBundle\Components\Payment
 */
interface CurrencyConstraintInterface
{
    /**
     * @return Currency
     */
    public function getAvailableCurrency();
}