<?php

namespace Shopen\AppBundle\Components\Payment\Payments;

use Shopen\AppBundle\Components\Payment\Enum\PaymentNotificationWayEnum;
use Shopen\AppBundle\Components\Payment\Exception\WrongPaymentDataException;
use Shopen\AppBundle\Components\Payment\Libs\Kkb\KkbLib;
use Shopen\AppBundle\Components\Payment\PaidItem;
use Shopen\AppBundle\Components\Payment\PaymentConfirmInterface;
use Shopen\AppBundle\Components\Payment\PaymentDataBag;
use Shopen\AppBundle\Components\Payment\PaymentMethod;
use Shopen\AppBundle\Components\Payment\PaymentReportInterface;
use Shopen\AppBundle\Components\Payment\PaymentResponse;
use Shopen\AppBundle\Components\Payment\PaymentSystemInterface;
use Shopen\AppBundle\Components\Payment\PaymentTransaction;
use Shopen\AppBundle\Components\Payment\RedirectDataBag;
use Shopen\AppBundle\Entity\Price;
use Shopen\AppBundle\Helpers\PriceHelper;
use Shopen\AppBundle\Repository\CurrencyRepository;
use Shopen\AppBundle\Security\User\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


/**
 * Class Epay
 * @package Shopen\AppBundle\Components\Payment\Payments
 */
class Epay implements PaymentSystemInterface, PaymentConfirmInterface, PaymentReportInterface
{
    /**
     * @var KkbLib
     */
    private $kkbLib;

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var CurrencyRepository
     */
    private $currencyRepository;

    /**
     * @var string
     */
    private $secret;

    /**
     * @param KkbLib $kkb
     * @param string $baseUrl
     * @param CurrencyRepository $currencyRepository
     * @param $secret
     */
    public function __construct(
        KkbLib $kkb,
        $baseUrl,
        CurrencyRepository $currencyRepository,
        $secret
    ) {
        $this->kkbLib = $kkb;
        $this->baseUrl = $baseUrl;
        $this->currencyRepository = $currencyRepository;
        $this->secret = $secret;
    }

    /**
     * Check is payment success and get data for success-page
     * @param $requestArray
     * @return array
     * @throws WrongPaymentDataException
     */
    public function getSuccessPaymentData($requestArray)
    {
        $expectedSign = sha1($requestArray['order_id'] . $requestArray['contract'] . $this->secret);

        if ($expectedSign != $requestArray['payment_id']) {
            throw new WrongPaymentDataException('Epay wrong signature');
        }

        return [
            'order_id' => $requestArray['order_id'],
            'client_contract' => $requestArray['contract'],
        ];
    }

    /**
     * @param Request $request
     * @param $countryCode
     * @return PaymentDataBag
     */
    public function getPaymentData(Request $request, $countryCode)
    {
        $currency = $this->currencyRepository->load('KZT');


        $payload = $request->get('response');
        $appendix = str_replace(' ', '+', $request->get('appendix'));

        $result = $this->kkbLib->processResponse($payload);
        $extraData = json_decode(
            preg_replace('~<\?xml version="1.0"\?><xml>(.+)</xml>~', '$1', base64_decode($appendix)),
            true
        );

        $price = new Price();
        $price->setCurrency($currency)
            ->setValue($result['PAYMENT_AMOUNT']);

        $paymentData = new PaymentDataBag();
        $paymentData
            ->setPrice($price)
            ->setOrderId($result['ORDER_ORDER_ID'])
            ->setPaymentId($result['PAYMENT_REFERENCE'])
            ->setLanguageCode($request->getLocale())
            ->setContract($extraData['contract'])
            ->setStatus('success');

        return $paymentData;
    }

    /**
     * @param Request $request
     * @return bool
     */
    public function validateRequest(Request $request)
    {
        $result = $this->kkbLib->processResponse($request->get('response'));

        return isset($result['CHECKRESULT']) && $result['CHECKRESULT'] == '[SIGN_GOOD]' && $result['PAYMENT_RESPONSE_CODE'] == '00';
    }

    /**
     * @param PaymentResponse $paymentResponse
     * @return Response
     */
    public function buildResponse(PaymentResponse $paymentResponse)
    {
        $status = $paymentResponse->isSuccess() ? 200 : 500;

        return new Response('0', $status);
    }

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
    ) {
        $redirectUrl = $this->baseUrl . '/jsp/process/logon.jsp';

        $mainData = $this->kkbLib->processRequest(
            $orderId,
            PriceHelper::getFormatValue($price, PriceHelper::VALUE_FORMAT_FLOAT)
        );

        $extraData = [
            'contract' => $user->getContract(),
        ];

        $params = [
            'Signed_Order_B64' => $mainData,
            'email' => $user->getEmail(),
            'BackLink' => $successUrl,
            'FailureBackLink' => $failUrl,
            'PostLink' => $handleNotificationUrl,
            'appendix' => base64_encode('<?xml version="1.0"?><xml>' . json_encode($extraData) . '</xml>'),
        ];

        $redirectData = new RedirectDataBag();
        $redirectData
            ->setUrl($redirectUrl)
            ->setParams($params)
            ->setMethod(RedirectDataBag::METHOD_POST);

        return $redirectData;
    }

    /**
     * @return string
     */
    public function getAlias()
    {
        return 'epay';
    }

    /**
     * @return array
     */
    public function getCountryCodes()
    {
        return ['kz'];
    }

    public function getSuccessStatusCode()
    {
        return 'success';
    }

    public function confirmPayment(Request $request)
    {
        $result = $request->get('response');
        $result = $this->kkbLib->processResponse($result);

        $orderId = $result['ORDER_ORDER_ID'];
        $referenceId = $result['PAYMENT_REFERENCE'];
        $approvalCode = $result['PAYMENT_APPROVAL_CODE'];
        $orderPriceValue = $result['PAYMENT_AMOUNT'];

        $approveXml = $this->kkbLib->processComplete($referenceId, $approvalCode, $orderId, $orderPriceValue);
        $approveUrl = $this->baseUrl . '/jsp/remote/control.jsp?' . urlencode($approveXml);

        file_get_contents($approveUrl);
    }

    /**
     * Return array of supported payment types
     *
     * @return array
     */
    public function getSupportedPaymentTypes()
    {
        return [PaymentMethod::BANK_CARD];
    }

    /**
     * @return bool
     */
    public function canPrintBill()
    {
        return true;
    }

    /**
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     * @return PaymentTransaction[]
     * @deprecated
     */
    public function getTransactions(\DateTime $dateFrom, \DateTime $dateTo)
    {
        return [];
    }

    /**
     * Возвращает алиас для процедуры БД
     *
     * @return string
     */
    public function getDbAlias()
    {
        return 'EPAY_KZ';
    }

    /**
     * Собирает транзакцию по набору данных от платежной системы
     *
     * @param PaymentDataBag $paymentDataBag
     * @return PaymentTransaction
     */
    public function buildTransaction(PaymentDataBag $paymentDataBag)
    {
        $transaction = new PaymentTransaction();

        $transaction
            ->setId($paymentDataBag->getPaymentId())
            ->setOrderId($paymentDataBag->getOrderId())
            ->setSum($paymentDataBag->getPrice()->getValue())
            ->setDate(new \DateTime('now'));

        return $transaction;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentNotificationWay()
    {
        return PaymentNotificationWayEnum::create(PaymentNotificationWayEnum::GATEWAY_TO_SHOP);
    }
}
