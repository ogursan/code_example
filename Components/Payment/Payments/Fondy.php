<?php

namespace Shopen\AppBundle\Components\Payment\Payments;

use Shopen\AppBundle\Components\L10n\Translator;
use Shopen\AppBundle\Components\Payment\Enum\PaymentNotificationWayEnum;
use Shopen\AppBundle\Components\Payment\Exception\WrongPaymentDataException;
use Shopen\AppBundle\Components\Payment\PaidItem;
use Shopen\AppBundle\Components\Payment\PaymentDataBag;
use Shopen\AppBundle\Components\Payment\PaymentMethod;
use Shopen\AppBundle\Components\Payment\PaymentResponse;
use Shopen\AppBundle\Components\Payment\PaymentSystemInterface;
use Shopen\AppBundle\Components\Payment\RedirectDataBag;
use Shopen\AppBundle\Entity\Price;
use Shopen\AppBundle\Helpers\PriceHelper;
use Shopen\AppBundle\Repository\CurrencyRepository;
use Shopen\AppBundle\Security\User\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


/**
 * Class Fondy
 * @package Shopen\AppBundle\Components\Payment
 */
class Fondy implements PaymentSystemInterface
{
    /**
     * Our ID in FONDY payment system
     */
    const MERCHANT_ID = ''; //TODO это должно жить в конфигах

    /**
     * Our secret salt in FONDY payment system
     */
    const SECRET = ''; //TODO это темболее.

    /**
     * Delimeter for 'merchant_data' values
     */
    const MERCHANT_DATA_DELIMETER = '~';

    /**
     * Uses when create signature for request to Fondy
     */
    const SIGNATURE_DATA_TYPE_REQUEST = 'request';
    
    /**
     * Uses when create signature for response from Fondy
     */
    const SIGNATURE_DATA_TYPE_RESPONSE = 'response';

    /**
     * @var CurrencyRepository
     */
    private $currencyRepository;

    /**
     * @var Translator
     */
    private $translator;

    function __construct(
        CurrencyRepository $currencyRepository,
        Translator $translator
    ) {
        $this->currencyRepository = $currencyRepository;
        $this->translator = $translator;
    }

    /**
     * {@inheritdoc}
     */
    public function getSuccessPaymentData($requestArray)
    {
        $hash = $this->getRequestSignature($requestArray, self::SIGNATURE_DATA_TYPE_RESPONSE);

        if ($hash != $requestArray['signature']) {
            throw new WrongPaymentDataException('Wrong signature from Fondy');
        }

        $merchantData = explode(self::MERCHANT_DATA_DELIMETER, $requestArray['merchant_data']);

        return [
            'order_id' => $requestArray['order_id'],
            'client_contract' => $merchantData[1],
        ];
    }

    /**
     * @param Request $request
     * @param $countryCode
     * @return PaymentDataBag
     */
    public function getPaymentData(Request $request, $countryCode)
    {
        $price = new Price();
        $price = PriceHelper::setFormatValue(
            $price,
            $request->request->get('amount'),
            PriceHelper::VALUE_FORMAT_INT
        );

        $currency = $this->currencyRepository->load($request->get('currency'));
        $price->setCurrency($currency);

        $orderId = explode('/', $request->request->get('order_id'))[0];

        $paymentData = new PaymentDataBag();
        $paymentData
            ->setOrderId($orderId)
            ->setPrice($price)
            ->setLanguageCode($request->getLocale())
            ->setStatus($request->get('order_status'))
            ->setPaymentId(preg_replace('[^0-9]', '', $request->get('payment_id')));

        return $paymentData;
    }

    /**
     * @param Request $request
     * @return bool
     */
    public function validateRequest(Request $request)
    {
        $expectedHash = $this->getRequestSignature($request->request->all(), self::SIGNATURE_DATA_TYPE_RESPONSE);

        return $expectedHash === $request->request->get('signature');
    }

    /**
     * @param PaymentResponse $paymentResponse
     * @return Response
     */
    public function buildResponse(PaymentResponse $paymentResponse)
    {
        return $paymentResponse->isSuccess() ? $this->getSuccessResponse() : $this->getFailResponse($paymentResponse->getMessage());
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
        // Хохлы почему-то не могут с одним order_id работать больше одного раза
        $params = [
            'server_callback_url' => $handleNotificationUrl,
            'response_url' => $successUrl,
            'order_id' => $orderId . '/' . strtoupper(substr(md5(openssl_random_pseudo_bytes(64)), 0, 8)),
            'order_desc' => $description,
            'currency' => $price->getCurrency()->getExternalIso(),
            'amount' => PriceHelper::getFormatValue($price, PriceHelper::VALUE_FORMAT_INT),
            'default_payment_system' => 'card',
            'merchant_id' => self::MERCHANT_ID,
            'merchant_data' => join(self::MERCHANT_DATA_DELIMETER, ['fondy', $user->getContract()]),
        ];

        $fondyAvailableLanguages = [
            'ru' => 'ru',
            'ua' => 'uk',
            'en' => 'en',
            'lv' => 'lv',
            'fr' => 'fr',
        ];
        if (isset($fondyAvailableLanguages[$languageCode])) {
            $params['lang'] = $fondyAvailableLanguages[$languageCode];
        }

        ksort($params);
        $params['signature'] = $this->getRequestSignature($params, self::SIGNATURE_DATA_TYPE_REQUEST);

        $fondyChannel = curl_init();
        curl_setopt($fondyChannel, CURLOPT_URL, "https://api.fondy.eu/api/checkout/redirect/");
        curl_setopt($fondyChannel, CURLOPT_HEADER, 0);
        curl_setopt($fondyChannel, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($fondyChannel, CURLOPT_POST, true);
        curl_setopt($fondyChannel, CURLOPT_POSTFIELDS, http_build_query($params));
        $redirectUrlPage = curl_exec($fondyChannel);
        curl_close($fondyChannel);

        preg_match('~.*=(https:.*token.*)"~', $redirectUrlPage, $redirectUrlArray);
        if (isset($redirectUrlArray[1])) {
            $url = $redirectUrlArray[1];
        }

        if (!isset($url)) {
            throw new WrongPaymentDataException(
                $this->translator->t('Не удалось получить ссылку на систему оплаты')
            );
        }

        $redirectData = new RedirectDataBag();
        $redirectData
            ->setUrl($url)
            ->setMethod(RedirectDataBag::METHOD_GET);

        $urlParts = parse_url($url);
        if (isset($urlParts['query'])) {
            parse_str($urlParts['query'], $urlParams);
            $redirectData->setParams($urlParams);
        }

        return $redirectData;
    }

    /**
     * @return string
     */
    public function getAlias()
    {
        return 'fondy';
    }

    /**
     * @return array
     */
    public function getCountryCodes()
    {
        return ['ua'];
    }

    /**
     * @return string
     */
    public function getSuccessStatusCode()
    {
        return 'approved';
    }

    /**
     * Create response with error 400
     * @param string $content
     * @return Response
     */
    private function getFailResponse($content)
    {
        $response = new Response();
        $response->setStatusCode(400);
        $response->setContent($content);

        return $response;
    }

    /**
     * Create success response with 200 code and contents "OK"
     * @return Response
     */
    private function getSuccessResponse()
    {
        $response = new Response();
        $response->setStatusCode(200);
        $response->setContent('OK');

        return $response;
    }

    private function getRequestSignature($requestData, $dataFrom)
    {
        if ($dataFrom == self::SIGNATURE_DATA_TYPE_RESPONSE) {
            $signatureData = [
                'order_id' => '',
                'merchant_id' => '',
                'amount' => '',
                'currency' => '',
                'order_status' => '',
                'response_status' => '',
                'tran_type' => '',
                'sender_cell_phone' => '',
                'sender_account' => '',
                'masked_card' => '',
                'card_bin' => '',
                'card_type' => '',
                'rrn' => '',
                'approval_code' => '',
                'response_code' => '',
                'response_description' => '',
                'reversal_amount' => '',
                'settlement_amount' => '',
                'settlement_currency' => '',
                'order_time' => '',
                'settlement_date' => '',
                'eci' => '',
                'fee' => '',
                'payment_system' => '',
                'sender_email' => '',
                'payment_id' => '',
                'actual_amount' => '',
                'actual_currency' => '',
                'product_id' => '',
                'merchant_data' => '',
                'verification_status' => '',
                'rectoken' => '',
                'rectoken_lifetime' => '',
            ];
        } elseif ($dataFrom == self::SIGNATURE_DATA_TYPE_REQUEST) {
            $signatureData = [
                'order_id' => '',
                'merchant_id' => '',
                'order_desc' => '',
                'amount' => '',
                'currency' => '',
                'version' => '',
                'response_url' => '',
                'server_callback_url' => '',
                'payment_systems' => '',
                'default_payment_system' => '',
                'lifetime' => '',
                'merchant_data' => '',
                'preauth' => '',
                'sender_email' => '',
                'delayed' => '',
                'lang' => '',
                'product_id' => '',
                'required_rectoken' => '',
                'verification' => '',
                'verification_type' => '',
                'rectoken' => '',
                'receiver_rectoken' => '',
                'design_id' => '',
                'subscription' => '',
                'subscription_callback_url' => '',
            ];
        } else {
            throw new WrongPaymentDataException('Wrong type of signature data');
        }

        ksort($signatureData);

        foreach ($signatureData as $key => $value) {
            if (isset($requestData[$key]) && $requestData[$key] != '') {
                $signatureData[$key] = $requestData[$key];
            } else {
                unset($signatureData[$key]);
            }
        }

        array_unshift($signatureData, self::SECRET);
        $requestString = join('|', $signatureData);

        return sha1($requestString);
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
     * @inheritdoc
     */
    public function getPaymentNotificationWay()
    {
        return PaymentNotificationWayEnum::create(PaymentNotificationWayEnum::GATEWAY_TO_SHOP);
    }

    /**
     * @return bool
     */
    public function canPrintBill()
    {
        return true;
    }

}
