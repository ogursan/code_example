<?php

namespace Shopen\AppBundle\Components\Payment;

use Shopen\AppBundle\Components\Payment\Exception\WrongPaymentDataException;
use Symfony\Component\HttpFoundation\Request;


interface PaymentNotificationInterface {

    /**
     * @param Request $request
     * @param $status
     * @param $countryCode
     * @return mixed
     */
    public function handleNotification(Request $request, $status, $countryCode);

    /**
     * Check is payment success and get data for success-page
     * @param $requestArray
     * @return array
     * @throws WrongPaymentDataException
     */
    public function getSuccessPaymentData($requestArray);
} 