<?php

namespace Shopen\AppBundle\Components\Payment\Payments;

use Shopen\AppBundle\Components\Payment\Enum\PaymentNotificationWayEnum;
use Shopen\AppBundle\Helpers\PriceHelper;
use Shopen\AppBundle\Components\L10n\L10n;
use Shopen\AppBundle\Components\Payment\Exception\WrongPaymentDataException;
use Shopen\AppBundle\Components\Payment\Exception\UnsuccessfulPaymentException;
use Shopen\AppBundle\Components\Payment\PaidItem;
use Shopen\AppBundle\Components\Payment\PaymentDataBag;
use Shopen\AppBundle\Components\Payment\PaymentMethod;
use Shopen\AppBundle\Components\Payment\PaymentResponse;
use Shopen\AppBundle\Components\Payment\PaymentSystemInterface;
use Shopen\AppBundle\Components\Payment\RedirectDataBag;
use Shopen\AppBundle\Entity\Price;
use Shopen\AppBundle\Repository\CurrencyRepository;
use Shopen\AppBundle\Security\User\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Shopen\AppBundle\Components\Cache\RedisCache;


class BPay implements PaymentSystemInterface
{
    CONST VERSION = '1.2';
    CONST PAYMENT_URL = 'https://www.bpay.md/user-api/payment1';

    const IS_TEST = 1;
    const IS_PROD = 0;

    const SUCCESS_PAYMENT_CODE = 100;
    const FAIL_PAYMENT_CODE = 30;

    const METHOD_CHECK = "check";
    const METHOD_PAY = "pay";

    /**
     * @var string
     */
    private $signature;

    /**
     * @var string
     */
    private $merchantId;

    /**
     * @var L10n
     */
    private $l10n;

    /**
     * @var CurrencyRepository
     */
    private $currencyRepository;

    /**
     * @var RedisCache
     */
    private $cache;

    /**
     * @var array
     */
    private $languageMap = [
        'ru' => 'RU',
        'en' => 'EN',
        'ro' => 'RO',
    ];

    public function __construct(L10n $l10n, $merchantId, $signature, CurrencyRepository $currencyRepository, RedisCache $cache)
    {
        $this->l10n = $l10n;
        $this->merchantId = $merchantId;
        $this->signature = $signature;
        $this->currencyRepository = $currencyRepository;
        $this->cache = $cache;
    }

    /**
     * @param Request $request
     * @param $countryCode
     * @return PaymentDataBag
     */
    public function getPaymentData(Request $request, $countryCode)
    {
        $xmlResponse = $request->get('data');
        $xmldata = simplexml_load_string(base64_decode($xmlResponse));

        $price = new Price();
        $price = PriceHelper::setFormatValue(
            $price,
            (float)$xmldata->amount,
            PriceHelper::VALUE_FORMAT_FLOAT
        );

        $currency = $this->currencyRepository->load($this->getCurrencyFromCode((int)$xmldata->valute));
        $price->setCurrency($currency);

        $orderId = (int)$xmldata->order_id;

        $data = new PaymentDataBag();
        $data
            ->setOrderId($orderId)
            ->setPrice($price)
            ->setLanguageCode($request->getLocale())
            ->setStatus((string)$xmldata->comand == 'pay' ? $this->getSuccessStatusCode() : '' )
            ->setPaymentId((string)$xmldata->order_id)
            ->setContract($xmldata->advanced1);

        return $data;
    }

    /**
     * @param Request $request
     * @return bool
     */
    public function validateRequest(Request $request)
    {
        $data = $request->get('data');
        $key = $request->get('key');
        $xmldata = base64_decode($data);
        $verifySign = md5(md5($xmldata) . md5($this->signature));
        try {
            $this->cache->set('verifySign',  $verifySign);
        } catch (\Exception $e) {
        }
        $xml = simplexml_load_string($xmldata);
        if($key == $verifySign && (string)$xml->comand == self::METHOD_PAY) {
            return true;
        }

        return false;
    }

    /**
     * @param PaymentResponse $paymentResponse
     * @return Response
     */
    public function buildResponse(PaymentResponse $paymentResponse)
    {
        $status = $paymentResponse->isSuccess() ? self::SUCCESS_PAYMENT_CODE : self::FAIL_PAYMENT_CODE;

        return $this->getXmlResponse($status, $paymentResponse->getMessage());
    }

    /**
     * @param $status
     * @param $message
     * @return Response
     */
    private function getXmlResponse($status, $message)
    {
        $responseText = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $responseText .= '<result>' . "\n";
        $responseText .= '<code>' . $status . '</code>' . "\n";
        $responseText .= '<text>' . $message . '</text>' . "\n";
        $responseText .= '</result>';

        $response = new Response($responseText);
        $response->headers->set('Content-Type', 'text/xml');

        return $response;
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
     * @throws WrongPaymentDataException
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
        $priceData = $price->getValue();

        $xmldata =
        '<payment>
            <type>' . self::VERSION . '</type>
            <merchantid>' . $this->merchantId  . '</merchantid>
            <amount>' . $priceData . '</amount>
            <description>' . $description . '</description>
            <method>card_usd</method>
            <order_id>' . $orderId . '</order_id>
            <success_url>'.htmlspecialchars($successUrl).'</success_url>
            <fail_url>'.htmlspecialchars($failUrl).'</fail_url>
            <callback_url>'.htmlspecialchars($handleNotificationUrl).'</callback_url>
            <lang>' . $this->getExternalLanguageCode($languageCode) . '</lang>
            <advanced1>' . $user->getContract() .'</advanced1>
        </payment>';

        // шифрум данные и подписываем их
        $data = base64_encode($xmldata);
        $sign = md5(md5($xmldata) . md5($this->signature));

        $params = [
            'data' => $data,
            'key' => $sign
        ];

        $url = self::PAYMENT_URL;

        $redirectData = new RedirectDataBag();
        $redirectData
            ->setUrl($url)
            ->setParams($params)
            ->setMethod(RedirectDataBag::METHOD_POST);

        return $redirectData;
    }

    /**
     * @return string
     */
    public function getAlias()
    {
        return 'bpay';
    }

    /**
     * @return array
     */
    public function getCountryCodes()
    {
        return ['md'];
    }

    /**
     * @return string
     */
    public function getSuccessStatusCode()
    {
        return 'success';
    }

    /**
     * Check is payment success and get data for success-page
     *
     * @param $requestArray
     * @return array
     * @throws UnsuccessfulPaymentException
     */
    public function getSuccessPaymentData($requestArray)
    {
        //
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
     * @inheritdoc
     */
    public function getPaymentNotificationWay()
    {
        return PaymentNotificationWayEnum::create(PaymentNotificationWayEnum::GATEWAY_TO_SHOP);
    }

    /**
     * @param $languageCode
     * @return string
     */
    private function getExternalLanguageCode($languageCode)
    {
        if (isset($this->languageMap[$languageCode])) {
            $language = $this->languageMap[$languageCode];
        } else {
            $language = 'EN';
        }

        return $language;
    }

    private function getCurrencyFromCode($code) {
        switch ($code) {
            case '498':
                return 'MDL';
                break;
            default:
                return 'MDL';
        }
    }

}
