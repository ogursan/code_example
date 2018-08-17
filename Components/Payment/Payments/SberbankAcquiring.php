<?php

namespace Shopen\AppBundle\Components\Payment\Payments;

use Shopen\AppBundle\Components\HttpClient\HttpClient;
use Shopen\AppBundle\Components\Payment\CurrencyConstraintInterface;
use Shopen\AppBundle\Components\Payment\Enum\PaymentNotificationWayEnum;
use Shopen\AppBundle\Components\Payment\Exception\WrongPaymentDataException;
use Shopen\AppBundle\Components\Payment\Exception\UnsuccessfulPaymentException;
use Shopen\AppBundle\Components\Payment\OnlyNotificationUrlInterface;
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


class SberbankAcquiring implements PaymentSystemInterface, PaymentReportInterface, OnlyNotificationUrlInterface, CurrencyConstraintInterface
{
    const PAYMENT_STATUS_SUCCESSFUL = 2;

    const CURRENCY_RUB = 'RUB';

    /**
     * @var HttpClient
     */
    protected $httpClient;

    /**
     * @var string
     */
    private $merchantId;

    /**
     * @var string
     */
    private $merchantPassword;

    /**
     * @var string
     */
    private $secret;

    /**
     * @var CurrencyRepository
     */
    private $currencyRepository;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var string
     */
    private $apiUrl;

    /**
     * @var PriceHelper
     */
    private $priceHelper;

    /**
     * SberbankAcquiring constructor.
     * @param $merchantId
     * @param $merchantPassword
     * @param $sberbankApiUrl
     * @param $secret
     * @param CurrencyRepository $currencyRepository
     * @param OrderRepository $orderRepository
     * @param HttpClient $httpClient
     */
    public function __construct(
        $merchantId,
        $merchantPassword,
        $sberbankApiUrl,
        $secret,
        CurrencyRepository $currencyRepository,
        OrderRepository $orderRepository,
        HttpClient $httpClient,
        PriceHelper $priceHelper
    )
    {
        $this->merchantId = $merchantId;
        $this->merchantPassword = $merchantPassword;
        $this->secret = $secret;
        $this->httpClient = $httpClient;
        $this->currencyRepository = $currencyRepository;
        $this->orderRepository = $orderRepository;
        $this->apiUrl = $sberbankApiUrl;
        $this->priceHelper = $priceHelper;
    }

    /**
     * @param Request $request
     * @param $countryCode
     * @return PaymentDataBag
     * @throws WrongPaymentDataException
     * @throws \Shopen\AppBundle\Helpers\Exception\HelpersException
     * @throws \Shopen\AppBundle\Repository\Exception\DataNotFoundException
     * @throws \Shopen\AppBundle\Repository\Exception\DatabaseException
     */
    public function getPaymentData(Request $request, $countryCode)
    {
        $idParts = explode('/', $request->get('orderNumber'));

        $orderNumber = str_ireplace('r', '', $idParts[0]);
        $hash = !empty($idParts[1]) ? $idParts[1] : null;
        $orderId = $request->get('mdOrder');

        $response = $this->callSberbankApi('getOrderStatusExtended.do', [
            'userName' => $this->merchantId,
            'password' => $this->merchantPassword,
            'orderId' => $orderId,
        ]);

        if (empty($response['orderNumber']) || empty($response['orderStatus']) || empty($response['amount'])) {
            throw new WrongPaymentDataException(
                'Sberbank API returns wrong response. Order #' . $orderId . '. ' . json_encode($response, JSON_UNESCAPED_UNICODE)
            );
        } elseif ($orderNumber != str_ireplace('r', '', explode('/', $response['orderNumber'])[0])) {
            throw new WrongPaymentDataException(
                'Fraud detected. Order #' . $orderNumber . '. ' . json_encode($response, JSON_UNESCAPED_UNICODE)
            );
        }

        $currency = $this->getAvailableCurrency();

        $price = new Price();
        $price->setCurrency($currency);
        $price = PriceHelper::setFormatValue($price, $response['amount'] / 100);

        $orderStatus = 'deposited' == $request->get('operation') && $request->get('status') ? 'success' : 'failed';

        $contract = $request->get('contract');
        if (!$contract && false === stripos($idParts[0], 'r')) {
            $order = $this->orderRepository->load($orderNumber);
            $contract = !empty($order['contract']) ? $order['contract'] : null;
        }

        $paymentData = new PaymentDataBag();
        $paymentData
            ->setOrderId($orderNumber)
            ->setContract($contract)
            ->setPaymentId($orderId)
            ->setLanguageCode($request->getLocale())
            ->setPrice($price)
            ->setStatus($orderStatus)
            ->setHash($hash);

        return $paymentData;
    }


    /**
     * @param Request $request
     * @return bool
     */
    public function validateRequest(Request $request)
    {
        $acceptIps = [
            '127.0.0.1',
        ];
        $clientIp = $request->getClientIp();
        if (in_array($clientIp, $acceptIps)) {
            return true;
        }

        $checksum = $request->get('checksum');
        if (!$checksum) {
            return false;
        }

        parse_str($request->getQueryString(), $params);
        ksort($params);
        unset($params['checksum'], $params['paysys'], $params['XDEBUG_SESSION_START'], $params['order_id'], $params['contract']);

        $params_prepared = '';
        reset($params);
        while (list($key, $val) = each($params)) {
            $params_prepared .= "$key;$val;";
        }

        $controlValue = hash_hmac('sha256', $params_prepared, $this->secret);
        if (strtolower($checksum) == strtolower($controlValue)) {
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
        $codeMap = [
            PaymentResponse::STATUS_INVALID_REQUEST => 300,
            PaymentResponse::STATUS_ORDER_NOT_EXISTS => 5,
            PaymentResponse::STATUS_LESS_SUM => 241,
            PaymentResponse::STATUS_MORE_SUM => 242,
            PaymentResponse::STATUS_ORDER_EXECUTION_ERROR => 300,
            PaymentResponse::STATUS_ALREADY_PAYED => 0,
            PaymentResponse::STATUS_NOT_SUCCESS => 300,
        ];

        $status = 500;
        if ($paymentResponse->isSuccess()) {
            $responseCode = 0;
            $status = 200;
        } elseif (isset($codeMap[$paymentResponse->getMessageCode()])) {
            $responseCode = $codeMap[$paymentResponse->getMessageCode()];
        } else {
            $responseCode = 300;
        }

        $responseContent = json_encode(['result' => $responseCode, 'comment' => $paymentResponse->getMessage()], JSON_UNESCAPED_UNICODE);

        return new Response($responseContent, $status);
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
     * @param Price|null $tax
     * @param array $items
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
        /**
         * Необходимо пометить оплату регистрации
         */
        if (count($items) && 'REGISTRATION' == $items[0]->getSku()) {
            $orderId = 'r' . $orderId;
        }

        /**
         * @TODO ДОЛБАНЫЙ СБЕР НЕ УМЕЕТ В ЯЗЫКИ
         */
        $languageCode = 'ru';

        $response = $this->callSberbankApi('register.do', [
            'userName' => $this->merchantId,
            'password' => $this->merchantPassword,
            'orderNumber' => $orderId . '/' . strtoupper(substr(md5(openssl_random_pseudo_bytes(64)), 0, 8)),
            'clientId' => $user->getContract(),
            'amount' => round($price->getValue() * pow(10, $price->getCurrency()->getDecimals())), //мультипликатор надо получать для конкретной валюты,  * 10 ** $price->getCurrency()->getDecimals()
            'currency' => $price->getCurrency()->getOkvCode(),
            'returnUrl' => $successUrl,
            'failUrl' => $failUrl,
            'description' => $description,
            'language' => $languageCode,
        ]);

        if (empty($response['orderId']) || empty($response['formUrl'])) {
            throw new WrongPaymentDataException(
                'Sberbank API returns wrong response. Order #' . $orderId . '. ' . json_encode($response, JSON_UNESCAPED_UNICODE)
            );
        }

        $redirectData = new RedirectDataBag();
        $redirectData
            ->setMethod(RedirectDataBag::METHOD_GET)
            ->setParams([])
            ->setUrl($response['formUrl']);

        return $redirectData;
    }

    /**
     * Return PaymentSystem string code
     *
     * @return string
     */
    public function getAlias()
    {
        return 'sberbank_acquiring';
    }

    /**
     * Return list of supported country codes
     *
     * @return array
     */
    public function getCountryCodes()
    {
        return ['ru', 'cn'];
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
            PaymentMethod::BANK_CARD
        ];
    }

    /**
     * Check is payment success and get data for success-page
     *
     * @param $requestArray
     * @return array
     * @throws UnsuccessfulPaymentException
     * @throws WrongPaymentDataException
     */
    public function getSuccessPaymentData($requestArray)
    {
        $response = $this->callSberbankApi('getOrderStatusExtended.do', [
            'userName' => $this->merchantId,
            'password' => $this->merchantPassword,
            'orderId' => $requestArray['orderId'],
        ]);

        if (empty($response['orderNumber']) || empty($response['orderStatus'])) {
            throw new WrongPaymentDataException(
                'Sberbank API returns wrong response. Order #' . $requestArray['order_id'] . '. ' . json_encode($response, JSON_UNESCAPED_UNICODE)
            );
        }  elseif ($requestArray['order_id'] != explode('/', $response['orderNumber'])[0]) {
            throw new WrongPaymentDataException(
                'Fraud detected. Order #' . $requestArray['order_id'] . '. ' . json_encode($response, JSON_UNESCAPED_UNICODE)
            );
        } elseif ($response['orderStatus'] != self::PAYMENT_STATUS_SUCCESSFUL) {
            throw new UnsuccessfulPaymentException(
                'Payment fails but TYP was called: SberBank/Card #' . $requestArray['orderId']
                . '(order ' . $requestArray['order_id'] . ')'
            );
        }

        return [
            'order_id' => $requestArray['order_id'],
            'client_contract' => $requestArray['contract'],
        ];
    }

    /**
     * @return bool
     */
    public function canPrintBill()
    {
        return false;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentNotificationWay()
    {
        return PaymentNotificationWayEnum::create(PaymentNotificationWayEnum::GATEWAY_TO_SHOP);
    }

    /**
     * Обращение к API Сбербанка
     *
     * @param $operation
     * @param $params
     * @return array
     * @throws WrongPaymentDataException
     */
    protected function callSberbankApi($operation, $params)
    {
        $sbApiResponse = $this->httpClient->post(
            $this->getOperationUrl($operation),
            $params
        );
        $response = json_decode($sbApiResponse, 1);

        if (!$response) {
            throw new WrongPaymentDataException('Sberbank API call fails. ' . json_encode($params, JSON_UNESCAPED_UNICODE));
        } elseif (!empty($response['errorCode'])) {
            throw new WrongPaymentDataException('Sberbank API call returns an error: ' . $sbApiResponse);
        }

        return $response;
    }

    /**
     * @param $operationName
     * @return string
     * @throws WrongPaymentDataException
     */
    private function getOperationUrl($operationName)
    {
        $authenticatedMethods = [
            'getOrderStatusExtended.do',
            'register.do',
            'getLastOrdersForMerchants.do',
        ];

        if (in_array($operationName, $authenticatedMethods)) {
            return rtrim($this->apiUrl, '/') . '/' . rtrim($operationName, '/');
        }

        throw new WrongPaymentDataException('Недопустимый метод API');
    }

    /**
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     * @return PaymentTransaction[]
     * @throws WrongPaymentDataException
     */
    public function getTransactions(\DateTime $dateFrom, \DateTime $dateTo)
    {
        $params = [
            'from' => $dateFrom->format('YmdHis'),
            'to' => $dateTo->format('YmdHis'),
            'size' => 200,
            'transactionStates' => 'DEPOSITED,REFUNDED',
            'merchants' => '',
            'userName' => $this->merchantId,
            'password' => $this->merchantPassword,
        ];

        $transactions = [];

        $page = 0;
        while (true) {
            $params['page'] = $page;

            $apiResponse = $this->callSberbankApi('getLastOrdersForMerchants.do', $params);

            if (!$apiResponse || !is_array($apiResponse) || !isset($apiResponse['orderStatuses']) || !isset($apiResponse['totalCount']) || empty($apiResponse['orderStatuses'])) {
                break;
            }

            foreach ($apiResponse['orderStatuses'] as $order) {
                if ($order['paymentAmountInfo']['depositedAmount'] == 0) {
                    continue;
                }

                $orderId = preg_replace('~^r?(.+?)~', '$1', $order['orderNumber']);
                $sum = round($order['paymentAmountInfo']['depositedAmount'] / 100, 2);

                $transaction = new PaymentTransaction();

                $transaction
                    ->setId($orderId)
                    ->setSum($sum)
                    ->setDate(new \DateTime(date('Y-m-d H:i:s', $order['authDateTime'] / 1000)));

                $transactions[] = $transaction;
            }

            $page++;
        }

        return $transactions;
    }

    /**
     * Возвращает алиас для процедуры БД
     *
     * @return string
     */
    public function getDbAlias()
    {
        return 'SBERBANK';
    }

    /**
     * @return \Shopen\AppBundle\Entity\Currency
     * @throws \Shopen\AppBundle\Repository\Exception\DataNotFoundException
     * @throws \Shopen\AppBundle\Repository\Exception\DatabaseException
     */
    public function getAvailableCurrency()
    {
        return $this->currencyRepository->load(self::CURRENCY_RUB);
    }

    /**
     * @param PaymentDataBag $paymentDataBag
     * @return PaymentTransaction
     */
    public function buildTransaction(PaymentDataBag $paymentDataBag)
    {
        $transaction = new PaymentTransaction();

        $transaction
            ->setId($paymentDataBag->getOrderId() . '/' . $paymentDataBag->getHash())
            ->setSum($paymentDataBag->getPrice()->getValue())
            ->setDate(new \DateTime('now'));

        return $transaction;
    }
}
