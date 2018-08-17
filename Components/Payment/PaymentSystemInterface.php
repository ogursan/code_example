<?php

namespace Shopen\AppBundle\Components\Payment;

use Shopen\AppBundle\Components\Payment\Enum\PaymentNotificationWayEnum;
use Shopen\AppBundle\Entity\Price;
use Shopen\AppBundle\Security\User\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


/**
 * Interface PaymentSystemInterface
 * @package Shopen\AppBundle\Components\Payment
 */
interface PaymentSystemInterface
{
    /**
     * @param Request $request
     * @param $countryCode
     * @return PaymentDataBag
     */
    public function getPaymentData(Request $request, $countryCode);

    /**
     * @param Request $request
     * @return bool
     */
    public function validateRequest(Request $request);

    /**
     * @param PaymentResponse $paymentResponse
     * @return Response
     */
    public function buildResponse(PaymentResponse $paymentResponse);

    /**
     * @param string $paymentTypeAlias
     * @param Price $price
     * @param User $user
     * @param int $orderId
     * @param string $successUrl
     * @param string $failUrl
     * @param string $handleNotificationUrl
     * @param string $description
     * @param string $languageCode
     * @param Price $tax
     * @param PaidItem[] $items
     * @return RedirectDataBag
     */
    public function getRedirectData(
        $paymentTypeAlias,
        Price $price,
        User $user,
        $orderId,
        $successUrl,
        $failUrl,
        $handleNotificationUrl,
        $description,
        $languageCode,
        Price $tax = null,
        $items = []
    );

    /**
     * Return PaymentSystem string code
     *
     * @return string
     */
    public function getAlias();

    /**
     * Return list of supported country codes
     *
     * @return array
     */
    public function getCountryCodes();

    /**
     * @return string
     */
    public function getSuccessStatusCode();

    /**
     * Return array of supported payment types
     *
     * @return array
     */
    public function getSupportedPaymentTypes();

    /**
     * Возвращает статус возможности самостоятельной печати и отправки кассового чека
     *
     * @return bool
     */
    public function canPrintBill();

    /**
     *  Возвращает способ оповещения нашего интернет магазина об окончании платежной транзакции на стороне платежной системы
     *  Либо платежная присылает нотификейшены магазину, либо магазин опрашивает платежную систему
     *
     * @return PaymentNotificationWayEnum
     */
    public function getPaymentNotificationWay();
}
