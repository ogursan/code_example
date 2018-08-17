<?php

namespace Shopen\AppBundle\Components\Payment\Payments;

use Shopen\AppBundle\Components\HttpClient\HttpClient;
use Shopen\AppBundle\Components\Payment\Enum\PaymentNotificationWayEnum;
use Shopen\AppBundle\Components\Payment\Exception\WrongPaymentDataException;
use Shopen\AppBundle\Components\Payment\Exception\UnsuccessfulPaymentException;
use Shopen\AppBundle\Components\Payment\PaymentDataBag;
use Shopen\AppBundle\Components\Payment\PaymentMethod;
use Shopen\AppBundle\Components\Payment\PaymentResponse;
use Shopen\AppBundle\Components\Payment\PaymentSystemInterface;
use Shopen\AppBundle\Components\Payment\PaymentTransaction;
use Shopen\AppBundle\Components\Payment\RedirectDataBag;
use Shopen\AppBundle\Entity\Price;
use Shopen\AppBundle\Helpers\PriceHelper;
use Shopen\AppBundle\Repository\CurrencyRepository;
use Shopen\AppBundle\Repository\OrderRepository;
use Shopen\AppBundle\Security\User\User;
use Sofort\SofortLib\Notification;
use Sofort\SofortLib\Sofortueberweisung;
use Sofort\SofortLib\TransactionData;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class Sofort implements PaymentSystemInterface
{
    const PAYMENT_STATUS_SUCCESSFUL = 2;

    /**
     * @var HttpClient
     */
    protected $httpClient;

    /**
     * @var string
     */
    private $apiUserId;

    /**
     * @var string
     */
    private $apiKey;

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
     * @var string
     */
    private $verificationUrl;

    /**
     * @var PriceHelper
     */
    private $priceHelper;

    /**
     * Sofort constructor.
     * @param $apiUserId
     * @param $apiKey
     * @param $apiUrl
     * @param $verificationUrl
     * @param CurrencyRepository $currencyRepository
     * @param OrderRepository $orderRepository
     * @param HttpClient $httpClient
     */
    public function __construct(
        $apiUserId,
        $apiKey,
        $apiUrl,
        $verificationUrl,
        CurrencyRepository $currencyRepository,
        OrderRepository $orderRepository,
        HttpClient $httpClient,
        PriceHelper $priceHelper
    )
    {
        $this->apiUserId = $apiUserId;
        $this->apiKey = $apiKey;
        $this->httpClient = $httpClient;
        $this->currencyRepository = $currencyRepository;
        $this->orderRepository = $orderRepository;
        $this->apiUrl = $apiUrl;
        $this->verificationUrl = $verificationUrl;
        $this->priceHelper = $priceHelper;
    }

    /**
     * @param Request $request
     * @param $countryCode
     * @return PaymentDataBag
     * @throws WrongPaymentDataException
     * @throws \Shopen\AppBundle\Repository\Exception\DataNotFoundException
     * @throws \Shopen\AppBundle\Repository\Exception\DatabaseException
     * @todo
     */
    public function getPaymentData(Request $request, $countryCode)
    {
        $orderId = $request->get('order_id');
        $contract = $request->get('contract');

        $order = $this->orderRepository->load($orderId);
        if (empty($order['contract']) || $order['contract'] != $contract) {
            throw new WrongPaymentDataException(
                'Fraud detected. Order: ' . $orderId . '. Contract: ' . $contract
            );
        }

        $sofortNotification = new Notification();
        $transactionId = $sofortNotification->getNotification(file_get_contents('php://input'));
        if (!$transactionId) {
            throw new WrongPaymentDataException(
                'Bad Sofort transaction ID. Order #' . $orderId . '. ' . json_encode($sofortNotification, JSON_UNESCAPED_UNICODE)
            );
        }

        $sofortTransactionData = new TransactionData($this->apiKey);
        $sofortTransactionData
            ->addTransaction($transactionId)
            ->setApiVersion('2.0')
            ->sendRequest();

        if($sofortTransactionData->isError()) {
            throw new WrongPaymentDataException(
                'Sofort API error. Order #' . $orderId . '. ' . $sofortTransactionData->getError()
            );
        }

        $price = new Price();
        $price->setCurrency($this->currencyRepository->load($sofortTransactionData->getCurrency()));
        $price = PriceHelper::setFormatValue($price, $sofortTransactionData->getAmount());

        $paymentData = new PaymentDataBag();
        $paymentData
            ->setOrderId($orderId)
            ->setContract($contract)
            ->setPaymentId($transactionId)
            ->setLanguageCode($request->getLocale())
            ->setPrice($price)
            ->setStatus($sofortTransactionData->getStatus())
            ->setHash(null);

        return $paymentData;
    }

    /**
     * @param Request $request
     * @return bool
     */
    public function validateRequest(Request $request)
    {
        $clientIp = $request->getClientIp();
        if (in_array($clientIp, $this->getSofortIpList())) {
            return true;
        }

        return false;
    }

    /**
     * @param PaymentResponse $paymentResponse
     * @return Response
     * @todo
     */
    public function buildResponse(PaymentResponse $paymentResponse)
    {
        $status = 500;
        $responseCode = 'Request failed';
        if ($paymentResponse->isSuccess() || PaymentResponse::STATUS_NOT_SUCCESS == $paymentResponse->getMessageCode()) {
            $responseCode = 'ok';
            $status = 200;
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
        $sofortPayment = new Sofortueberweisung($this->apiKey);
        $sofortPayment
            ->setAmount($price->getValue())
            ->setCurrencyCode($price->getCurrency()->getExternalIso())
            ->setSuccessUrl($successUrl, true)
            ->setAbortUrl($failUrl)
            ->setNotificationUrl($handleNotificationUrl)
            ->setLanguageCode($languageCode)
            ->setReason($description);

        $sofortPayment->sendRequest();
        if($sofortPayment->isError()) {
            throw new WrongPaymentDataException(
                'Sofort API returns wrong response. Order #' . $orderId . '. ' . $sofortPayment->getError()
            );
        }

//        $transactionId = $sofortPayment->getTransactionId();

        $redirectData = new RedirectDataBag();
        $redirectData
            ->setMethod(RedirectDataBag::METHOD_GET)
            ->setParams([])
            ->setUrl($sofortPayment->getPaymentUrl());

        return $redirectData;
    }


    /**
     * Check is payment success and get data for success-page
     *
     * @param array $requestArray
     * @return array
     * @throws WrongPaymentDataException
     */
    public function getSuccessPaymentData($requestArray)
    {
        if (empty($requestArray['contract']) || empty($requestArray['order_id'])) {
            throw new WrongPaymentDataException(
                'Wrong response from Sofort API. ' . json_encode($requestArray, JSON_UNESCAPED_UNICODE)
            );
        }

        $order = $this->orderRepository->load($requestArray['order_id']);
        if (!empty($order['contract']) && $order['contract'] == $requestArray['contract']) {
            return [
                'order_id' => $requestArray['order_id'],
                'client_contract' => $requestArray['contract'],
            ];
        }

        throw new WrongPaymentDataException(
            'Fraud detected. Order #' . $requestArray['order_id'] . '. ' . json_encode($requestArray, JSON_UNESCAPED_UNICODE)
        );
    }


    /**
     * Return PaymentSystem string code
     *
     * @return string
     */
    public function getAlias()
    {
        return 'sofort';
    }

    /**
     * Return list of supported country codes
     *
     * @return array
     */
    public function getCountryCodes()
    {
        return ['de'];
    }

    /**
     * @return string
     */
    public function getSuccessStatusCode()
    {
        return "untraceable";
    }

    /**
     * Return array of supported payment types
     *
     * @return array
     */
    public function getSupportedPaymentTypes()
    {
        return [
            PaymentMethod::SOFORT
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
     * Возвращает перечень доверенных адресов, данные с которых должны быть приняты
     *
     * @return array
     */
    private function getSofortIpList()
    {
        $list = [];
        foreach (file($this->verificationUrl) as $row) {
            $row = trim($row);
            if (filter_var($row, FILTER_VALIDATE_IP)) {
                array_push($list, $row);
            }
        }

        /**
         * Добавим адреса для отладки
         */
        return array_merge($list, [
            '127.0.0.1',
            '192.168.6.6', // @todo удалить перед выводом в продакшен!!!
        ]);
    }
}