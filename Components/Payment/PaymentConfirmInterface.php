<?php

namespace Shopen\AppBundle\Components\Payment;

use Symfony\Component\HttpFoundation\Request;


/**
 * Interface PaymentConfirmInterface
 *
 * Use if payment gate requires confirmation after Payment Notification
 *
 * @package Shopen\AppBundle\Components\Payment
 */
interface PaymentConfirmInterface
{
    /**
     * @param Request $request
     */
    public function confirmPayment(Request $request);
}
