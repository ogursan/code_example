<?php

namespace Shopen\AppBundle\Components\Payment;

interface CashRegisterInterface
{
    /**
     * @param PaymentDataBag $payment
     * @param PaidItem[] $paidItems
     * @param string $customerContact
     * @return CashRegisterInterface
     */
    public function createBill(PaymentDataBag $payment, $paidItems, $customerContact);

    /**
     * @return bool
     */
    public function printBill();

    /**
     * @return mixed
     */
    public function checkBill();

    /**
     * @return string
     */
    public function getError();
}