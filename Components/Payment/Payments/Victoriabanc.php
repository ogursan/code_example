<?php

namespace Shopen\AppBundle\Components\Payment\Payments;


use Shopen\AppBundle\Components\HttpClient\HttpClient;
use Shopen\AppBundle\Components\Payment\Enum\PaymentNotificationWayEnum;
use Shopen\AppBundle\Components\Payment\Exception\WrongPaymentDataException;
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
use Shopen\AppBundle\Repository\OrderRepository;
use Shopen\AppBundle\Security\User\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Victoriabanc implements PaymentSystemInterface, PaymentConfirmInterface, PaymentReportInterface
{
    private $url = 'https://egateway.victoriabank.md/cgi-bin/cgi_link';

    private $certPath;

    private $publicCertPath;

    private $merchantId;

    private $terminalId;

    /**
     * @var CurrencyRepository
     */
    private $currencyRepository;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var HttpClient
     */
    private $httpClient;

    public function __construct(
        $certPath,
        $publicCertPath,
        $merchantId,
        $terminalId,
        CurrencyRepository $currencyRepository,
        OrderRepository $orderRepository,
        HttpClient $httpClient
    ) {
        $this->certPath = $certPath;
        $this->publicCertPath = $publicCertPath;
        $this->merchantId = $merchantId;
        $this->terminalId = $terminalId;
        $this->currencyRepository = $currencyRepository;
        $this->orderRepository = $orderRepository;
        $this->httpClient = $httpClient;
    }

    /**
     * @param Request $request
     * @param $countryCode
     * @return PaymentDataBag
     */
    public function getPaymentData(Request $request, $countryCode)
    {
        $orderId = $request->get('ORDER');
        $currency = $this->currencyRepository->load('MDL');

        $price = new Price();
        $price->setCurrency($currency);

        $price = PriceHelper::setFormatValue($price, $request->get('AMOUNT'));

        $paymentData = new PaymentDataBag();

        $order = $this->orderRepository->load($orderId);
        $contract = !empty($order['contract']) ? $order['contract'] : null;

        $paymentData
            ->setOrderId($orderId)
            ->setContract($contract)
            ->setPaymentId($request->get('APPROVAL'))
            ->setLanguageCode($request->getLocale())
            ->setPrice($price)
            ->setStatus('success');

        return $paymentData;
    }

    /**
     * @param Request $request
     * @return bool
     * @throws \Exception
     */
    public function validateRequest(Request $request)
    {
        $inData = [
            'ACTION' => $request->get('ACTION'),
            'RC' => $request->get('RC'),
            'RRN' => $request->get('RRN'),
            'ORDER' => $request->get('ORDER'),
            'AMOUNT' => $request->get('AMOUNT'),
        ];

        $mac = '';

        foreach ($inData as $field) {
            if ($field != '-') {
                $mac .= strlen($field) . $field;
            } else {
                $mac .= $field;
            }
        }

        $dataHash = strtoupper(md5($mac));

        $binSign = hex2bin($request->get('P_SIGN'));

        $rsaKey = file_get_contents($this->publicCertPath);

        if (!openssl_get_publickey($rsaKey)) {
            throw new \Exception('Failed get public key');
        }

        if (!openssl_public_decrypt($binSign, $decryptedBin, $rsaKey)) {
            $errors = [];
            while ($msg = openssl_error_string()) {
                $errors[] = $msg;
            }
            throw new \Exception('Decrypt failed');
        }

        $decrypted = strtoupper(bin2hex($decryptedBin));
        $prefix = '3020300C06082A864886F70D020505000410';

        $decryptedHash = str_replace($prefix, '', $decrypted);

        return $dataHash == $decryptedHash && $request->get('TRTYPE') == 0;
    }

    /**
     * @param PaymentResponse $paymentResponse
     * @return Response
     */
    public function buildResponse(PaymentResponse $paymentResponse)
    {
        return new Response();
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
        $utcTimeZone = new \DateTimeZone('UTC');
        $currentDateTime = new \DateTime();
        $currentDateTime->setTimezone($utcTimeZone);

        $params = [
            'AMOUNT' => round($price->getValue(), 2),
            'CURRENCY' => $price->getCurrency()->getExternalIso(),
            'ORDER' => $orderId,
            'DESC' => $description,
            'MERCH_NAME' => 'Siberian Health',
            'MERCH_URL' => 'https://md.siberianhealth.com/',
            'MERCHANT' => $this->merchantId,
            'TERMINAL' => $this->terminalId,
            'EMAIL' => 'shubin.ad@sibvaleo.com',
            'MERCH_ADDRESS' => 'Moldova, Chisinau , bd Stefan cel Mare 3',
            'TRTYPE' => '0',
            'COUNTRY' => 'md',
            'MERCH_GMT' => '+3',
            'TIMESTAMP' => $currentDateTime->format('YmdHis'),
            'NONCE' => '11111111000000011111',
            'BACKREF' => $successUrl,
            'LANG' => $languageCode,
        ];

        $params['P_SIGN'] = $this->encryptSign($params);

        $redirectData = new RedirectDataBag();

        $redirectData
            ->setMethod(RedirectDataBag::METHOD_POST)
            ->setUrl($this->url)
            ->setParams($params);

        return $redirectData;
    }

    /**
     * Return PaymentSystem string code
     *
     * @return string
     */
    public function getAlias()
    {
        return 'victoriabanc';
    }

    /**
     * Return list of supported country codes
     *
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
     * Return array of supported payment types
     *
     * @return array
     */
    public function getSupportedPaymentTypes()
    {
        return [
            PaymentMethod::BANK_CARD_MD,
        ];
    }

    /**
     * Возвращает статус возможности самостоятельной печати и отправки кассового чека
     *
     * @return bool
     */
    public function canPrintBill()
    {
        return true;
    }

    /**
     *  Возвращает способ оповещения нашего интернет магазина об окончании платежной транзакции на стороне платежной системы
     *  Либо платежная присылает нотификейшены магазину, либо магазин опрашивает платежную систему
     *
     * @return PaymentNotificationWayEnum
     */
    public function getPaymentNotificationWay()
    {
        return PaymentNotificationWayEnum::create(PaymentNotificationWayEnum::GATEWAY_TO_SHOP);
    }

    /**
     * @param Request $request
     */
    public function confirmPayment(Request $request)
    {
        $utcTimeZone = new \DateTimeZone('UTC');
        $currentDateTime = new \DateTime();
        $currentDateTime->setTimezone($utcTimeZone);

        $params = [
            'ORDER' => $request->get('ORDER'),
            'AMOUNT' => $request->get('AMOUNT'),
            'CURRENCY' => $request->get('CURRENCY'),
            'RRN' => $request->get('RRN'),
            'INT_REF' => $request->get('INT_REF'),
            'TRTYPE' => '21',
            'TERMINAL' => $this->terminalId,
            'TIMESTAMP' => $currentDateTime->format('YmdHis'),
            'NONCE' => '11111111000000011111',
        ];

        $params['P_SIGN'] = $this->encryptSign($params);

        $options = [
            CURLOPT_HEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query($params),
        ];

        $this->httpClient->post($this->url, [], $options);
    }

    //PaymentReportInterface

    /**
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     * @return PaymentTransaction[]
     * @deprecated
     */
    public function getTransactions(\DateTime $dateFrom, \DateTime $dateTo)
    {
        return []; // У API нет метода для получения списка транзакций
    }

    /**
     * Возвращает алиас для процедуры БД
     *
     * @return string
     */
    public function getDbAlias()
    {
        return 'VICTORIABANC';
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
            ->setDate(new \DateTime('now'))
            ->setType($paymentDataBag->getPaymentMethod())
            ->setCurrency($paymentDataBag->getPrice()->getCurrency()->getExternalIso());

        return $transaction;
    }


    /**
     * Check is payment success and get data for success-page
     *
     * @param $requestArray
     * @return array
     * @throws WrongPaymentDataException
     */
    public function getSuccessPaymentData($requestArray)
    {
        return [
            'order_id' => $requestArray['order_id'],
            'client_contract' => $requestArray['contract'],
        ];
    }

    /**
     * @param array $params
     * @return string
     * @throws \Exception
     */
    public function encryptSign(array $params)
    {
        $key = file_get_contents($this->certPath);

        $data = [
            'ORDER' => $params['ORDER'],
            'NONCE' => $params['NONCE'],
            'TIMESTAMP' => $params['TIMESTAMP'],
            'TRTYPE' => $params['TRTYPE'],
            'AMOUNT' => $params['AMOUNT'],
        ];

        if (!$keyResource = openssl_get_privatekey($key)) {
            throw new \Exception('Invalid key');
        }

        $keyDetails = openssl_pkey_get_details($keyResource);
        $keyLength = $keyDetails['bits'] / 8;

        $mac = '';

        foreach ($data as $id => $value) {
            $mac .= strlen($value) . $value;
        }

        $first = '0001';
        $prefix = '003020300C06082A864886F70D020505000410';
        $dataHash = md5($mac);

        $output = $first;

        $paddingLength = $keyLength - strlen($dataHash) / 2 - strlen($prefix) / 2 - strlen($first) / 2;

        for ($i = 0; $i < $paddingLength; $i++) {
            $output .= 'FF';
        }

        $output .= $prefix . $dataHash;

        $bin = pack('H*', $output);

        if (!openssl_private_encrypt($bin, $encryptedBin, $key, OPENSSL_NO_PADDING)) {
            throw new \Exception('Encrypt failed');
        }

        $sign = bin2hex($encryptedBin);

        return strtoupper($sign);
    }
}