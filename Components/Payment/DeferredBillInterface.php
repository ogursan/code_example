<?php

namespace Shopen\AppBundle\Components\Payment;

use Shopen\AppBundle\Repository\OrderRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Интерфейс платёжной системы, реализующей возможность оплаты офлайн
 *
 * @package Shopen\AppBundle\Components\Payment
 */
interface DeferredBillInterface
{
    /**
     * @param Request $request
     * @param PaymentTicket $paymentTicket
     * @return mixed
     */
    public function resolveDeferredPayment(Request $request, PaymentTicket $paymentTicket);

    /**
     * Возвращает имя передаваемого платёжной системой параметра, содержащего id заказа/счёта
     *
     * @return string
     */
    public function getAccountParamName();

    /**
     * Возвращает имя передаваемого платёжной системой параметра, содержащего сумму платежа
     *
     * @return string
     */
    public function getAmountParamName();

    /**
     * Возвращает имя передаваемого платёжной системой параметра, содержащего id платежа
     *
     * @return string
     */
    public function getPaymentIdParamName();
}
