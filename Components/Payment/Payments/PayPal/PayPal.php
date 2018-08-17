<?php

namespace Shopen\AppBundle\Components\Payment\Payments\PayPal;

use Shopen\AppBundle\Components\Payment\Enum\PaymentNotificationWayEnum;
use Shopen\AppBundle\Components\Payment\Exception\WrongPaymentDataException;
use Shopen\AppBundle\Components\Payment\Exception\UnsuccessfulPaymentException;
use Shopen\AppBundle\Components\Payment\OnlyNotificationUrlInterface;
use Shopen\AppBundle\Components\Payment\PaidItem;
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
use Symfony\Component\HttpFoundation\HeaderBag;
use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Rest\ApiContext;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Api\PaymentExecution;
use PayPal\Api\VerifyWebhookSignature;
use Symfony\Component\HttpKernel\Log\LoggerInterface;


class PayPal implements PaymentSystemInterface, OnlyNotificationUrlInterface, PaymentReportInterface
{
    // Статус платежа
    const STATUS_APPROVED = 'approved';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_PARTIALLY_COMPLETED = 'partially_completed';
    const STATUS_IN_PROGRESS = 'in_progress';

    // Статус подтверждения
    const VERIF_STATUS_SUCCESS = 'SUCCESS';

    // Намерение взаимодействия
    const PAYMENT_INTENT_SALE = 'sale';
    const PAYMENT_INTENT_AUTHORIZE = 'authorize';
    const PAYMENT_INTENT_ORDER = 'order';

    // Клюси http-заголовков
    const HEADER_AUTH_ALGO = 'PAYPAL-AUTH-ALGO';
    const HEADER_TRANSMISSION_ID = 'PAYPAL-TRANSMISSION-ID';
    const HEADER_CERT_URL = 'PAYPAL-CERT-URL';
    const HEADER_TRANSMISSION_SIG = 'PAYPAL-TRANSMISSION-SIG';
    const HEADER_TRANSMISSION_TIME = 'PAYPAL-TRANSMISSION-TIME';

    // Способы платежа
    const PAYMENT_METHOD_CREDIT_CARD = 'credit_card';
    const PAYMENT_METHOD_BANK = 'bank';
    const PAYMENT_METHOD_ = '';
    const PAYMENT_METHOD_PAYPAL = 'paypal';
    const PAYMENT_METHOD_PAY_UNION_INVOICE = 'pay_upon_invoice';
    const PAYMENT_METHOD_CARRIER = 'carrier';
    const PAYMENT_METHOD_ALTERNATE_PAYMENT = 'alternate_payment';

    const PAYPAL_PAYMENT_LIST_REQUEST_ORDER_BY_CREATE_TIME = 'create_time';

    // События веб-хук. Их больше (https://developer.paypal.com/docs/integration/direct/webhooks/event-names/) , пока используется только это
    const WH_EVENT_PAYMENT_SALE_COMPLETED = 'PAYMENT.SALE.COMPLETED';

    /**
     * @var \PayPal\Rest\ApiContext
     */
    private $apiContext = null;

    /**
     * @var CurrencyRepository
     */
    private $currencyRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PayPalCountryConfiguratorInterface
     */
    private $payPalCountryConfigurator;

    /**
     * @var PayPalCurrencyEnum Базовая валюта, в которой наш магазин принимает все платежи
     */
    private $baseCurrency;

    /**
     * @var PriceHelper
     */
    private $priceHelper;

    /**
     * PayPal constructor.
     * @param CurrencyRepository $currencyRepository
     * @param LoggerInterface $logger
     * @param PayPalCountryConfiguratorInterface $payPalCountryConfigurator
     */
    public function __construct(CurrencyRepository $currencyRepository, LoggerInterface $logger, PayPalCountryConfiguratorInterface $payPalCountryConfigurator, PriceHelper $priceHelper)
    {
        $this->currencyRepository = $currencyRepository;
        $this->logger = $logger;
        $this->payPalCountryConfigurator = $payPalCountryConfigurator;
        $this->baseCurrency = PayPalCurrencyEnum::create(PayPalCurrencyEnum::RUB);
        $this->priceHelper = $priceHelper;
    }

    /**
     * @param string $id
     */
    public function setId($id)
    {
        $this->id = $id;
    }


    //PaymentSystemInterface
    /**
     * @param Request $request
     * @param $countryCode
     * @return PaymentDataBag
     * @throws \Shopen\AppBundle\Repository\Exception\DataNotFoundException
     * @throws \Shopen\AppBundle\Repository\Exception\DatabaseException
     */
    public function getPaymentData(Request $request, $countryCode)
    {
        $this->logger->info('PayPal->getPaymentData $request = '.$request);
        $content = $request->getContent();
        $requestObj = json_decode($content);

        $resource = $requestObj->resource;
        $amount = $resource->amount;
        $currency = $this->currencyRepository->load($amount->currency);
        $price = new Price();
        $price
            ->setCurrency($currency)
            ->setValue(round($amount->total, 2));

        $data = new PaymentDataBag();
        $data
            ->setOrderId($resource->invoice_number)
            ->setContract($resource->custom)
            ->setPaymentId($resource->id)
            ->setLanguageCode($request->getLocale())
            ->setPrice($price)
            ->setStatus($resource->state);

        return $data;
    }

    /**
     * @param Request $request
     * @return bool
     */
    public function validateRequest(Request $request)
    {
        $content = $request->getContent();
        $this->init();

        $signatureVerification = new VerifyWebhookSignature();
        try {
            $signatureVerification->setAuthAlgo($this->getHeader(self::HEADER_AUTH_ALGO, $request->headers));
            $signatureVerification->setTransmissionId($this->getHeader(self::HEADER_TRANSMISSION_ID, $request->headers));
            $signatureVerification->setCertUrl($this->getHeader(self::HEADER_CERT_URL, $request->headers));
            $signatureVerification->setWebhookId($this->payPalCountryConfigurator->getPayPalWebhookId());
            $signatureVerification->setTransmissionSig($this->getHeader(self::HEADER_TRANSMISSION_SIG, $request->headers));
            $signatureVerification->setTransmissionTime($this->getHeader(self::HEADER_TRANSMISSION_TIME, $request->headers));
            $signatureVerification->setRequestBody($content);

            $output = $signatureVerification->post($this->apiContext);
            $verified = json_decode($output);
            return $verified->verification_status === self::VERIF_STATUS_SUCCESS;
        } catch (\Exception $e) {
            $this->logger->error('PayPal->validateRequest() '.$e->getMessage());
            return false;
        }
    }

    private function getHeader($key, HeaderBag $headers) {
        if ($headers->has($key)) {
            return $headers->get($key);
        } else
            throw new \Exception('PayPal header missing: key='.$key);
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
     * @param Price $price - orderPaymentSum
     * @param User $user
     * @param int $orderId;
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
        $this->logger->info('PayPal getRedirectData() $orderId='.$orderId);
        $this->init();

        $profileId = null;

        try {
            $presentation = (new \PayPal\Api\Presentation())
                ->setLocaleCode((string)$this->payPalCountryConfigurator->getPayPalLocale())
                ->setBrandName('Siberian Health');

            $webProfile = (new \PayPal\Api\WebProfile())
                ->setName(uniqid())
                ->setPresentation($presentation)
                ->setTemporary(true);

            $createProfileResponse = $webProfile->create($this->apiContext);
            $profileId = $createProfileResponse->getId();
        } catch (\Exception $e) {
            $this->logger->warn('PayPal getRedirectData() web profile creation failed, order checkout page was not localized: ' . $e->getMessage());
        }

        $payer = new Payer();
        $payer->setPaymentMethod(self::PAYMENT_METHOD_PAYPAL);
        $rubCurrency = $this->currencyRepository->load('RUB');

        $itemsArray = [];
        foreach ($items as $item) {
            if (!$item->getPrice()->getValue()) {
                continue;
            }
            $theItem = new Item();
            $itemsArray[] = $theItem->setName($item->getName())
                ->setCurrency((string) PayPalCurrencyEnum::create(PayPalCurrencyEnum::RUB))
                ->setQuantity($item->getCount())
                ->setSku($item->getSku())
                ->setPrice($this->priceHelper->convert($item->getPrice(), $rubCurrency)->getValue());
        }

        $itemList = new ItemList();
        $itemList->setItems($itemsArray);

        $totalPriceFromItems = $this->calculateTotalFromDetails($itemsArray);
        $totalPriceFromOrder = $this->priceHelper->convert($price, $rubCurrency)->getValue();
        $orderItemsPricesIsValid = $this->roughFloatCompare($totalPriceFromItems,$totalPriceFromOrder);

        if(! $orderItemsPricesIsValid){
            throw new WrongPaymentDataException(
                'PayPal create payment exception: Стоимость состовляющих заказа не равна полной стоимости заказа',
                $e->getCode(),
                $e
            );
        }

        $details = new Details();
        $details
            //->setShipping(0)  // неиспользуемая опция API
            //->setTax(0)       // неиспользуемая опция API
            ->setSubtotal($totalPriceFromItems);

        $amount = new Amount();
        $amount->setCurrency((string) PayPalCurrencyEnum::create(PayPalCurrencyEnum::RUB))
            ->setTotal($totalPriceFromItems)
            ->setDetails($details);

        $transaction = new Transaction();
        $transaction
            ->setAmount($amount)
            ->setItemList($itemList)
            ->setDescription($description)
//            ->setPurchaseOrder($orderId)    // к сожалению, отсутствует в возвращаемых данных
            ->setCustom($user->getContract())
            ->setInvoiceNumber($orderId);   // возможно, единственный путь получить orderId в возвращаемых данных

        $redirectUrls = new RedirectUrls();
        $redirectUrls->setReturnUrl($successUrl)
            ->setCancelUrl($failUrl);

        $payment = new Payment();
        $payment->setIntent(self::PAYMENT_INTENT_SALE)
            ->setPayer($payer)
            ->setRedirectUrls($redirectUrls)
            ->setTransactions(array($transaction));

        if ($profileId != null){
            $payment->setExperienceProfileId($profileId);
        }

        try {
            $payment = $payment->create($this->apiContext);
        } catch (\Exception $e) {
            throw new WrongPaymentDataException(
                'PayPal create payment exception: '.$e->getMessage(),
                $e->getCode(),
                $e
            );
        }

        $redirectData = new RedirectDataBag();
        $redirectData->setUrl($payment->getApprovalLink());

        return $redirectData;
    }

    /**
     * @return string
     */
    public function getAlias()
    {
        return self::PAYMENT_METHOD_PAYPAL;
    }

    /**
     * @return array
     */
    public function getCountryCodes()
    {
        return [
            'ru',
            'cn',
        ];
    }

    /**
     * @return string
     */
    public function getSuccessStatusCode()
    {
        return self::STATUS_COMPLETED;
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
        $this->init();

        $this->executePaymentAfterUserConfirmation($requestArray);

        return [
            'order_id' => $requestArray['order_id'],
            'client_contract' => $requestArray['contract'],
        ];
    }

    /**
     * Return array of supported payment types
     *
     * @return array
     */
    public function getSupportedPaymentTypes()
    {
        return [PaymentMethod::PAYPAL];
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

    //PaymentReportInterface

    /**
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     * @return PaymentTransaction[]
     * @deprecated
     */
    public function getTransactions(\DateTime $dateFrom, \DateTime $dateTo)
    {
        /**
         * @var PaymentTransaction[] $result
         */
        $result = [];

        $this->init();

        $params = array(
            'count' => 20,
            'start_id' => null,
            'start_time' => $dateFrom,
            'end_time' => $dateTo,
            'sort_by' => self::PAYPAL_PAYMENT_LIST_REQUEST_ORDER_BY_CREATE_TIME,
        );

        while(true){
            $payments = Payment::all($params,$this->apiContext);

            foreach ($payments->getPayments() as $payment){
                $paymentTransactioin = new PaymentTransaction();
                $paymentTransactioin
                    ->setId($payment->getId())
                    ->setSum($payment->getTransactions()[0]->getAmount()->getTotal())
                    ->setOrderId($payment->getTransactions()[0]->getInvoiceNumber())
                    ->setDate(new \DateTime($payment->getCreateTime()))
                    ->setCurrency($payment->getTransactions()[0]->getAmount()->getCurrency());

                $result[] = $paymentTransactioin;
            }

            if($payments->getCount() < 20){
                break;
            }

            $params['start_id'] = $payments->getNextId();
        }

        return $result;
    }

    /**
     * Возвращает алиас для процедуры БД
     *
     * @return string
     */
    public function getDbAlias()
    {
        return 'PAYPAL';
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
     * Initiate connection to PayPal server
     */
    private function init()
    {
        if (!is_null($this->apiContext)) {
            return;
        }

        $this->apiContext = new ApiContext(
            new OAuthTokenCredential(
                $this->payPalCountryConfigurator->getPayPalId(),
                $this->payPalCountryConfigurator->getPayPalSecret()
            ),
            \uniqid()
        );
        $this->apiContext->setConfig(
            array(
                'mode' => (string)$this->payPalCountryConfigurator->getPayPalMode(),
            )
        );

        /**
         * При переполнении списка хранимых web-профилей
         * новые не создаются и страницы оплаты не локализуются
         */
        $this->cleanUpWebProfiles();
    }

    /**
     *
     *  Сравнивает целочисленные части двух чисел
     *
     * @param $numberOne
     * @param $numberTwo
     * @return bool
     */
    private function roughFloatCompare($numberOne, $numberTwo)
    {
        return round($numberOne) === round($numberTwo);
    }

    /**
     * @param Item[] $items
     * @return float
     */
    private function calculateTotalFromDetails(array $items)
    {
        $result = (float)0;

        foreach ($items as $item){
            $result += (float)$item->getPrice() * $item->getQuantity();
        }

        return $result;
    }

    /**
     * Удаляет все web-профили в пайпале.
     */
    private function cleanUpWebProfiles()
    {
        $profiles = \PayPal\Api\WebProfile::get_list($this->apiContext);
        foreach ($profiles as $profile){
            try{
                $profile->delete($this->apiContext);
            } catch ( \Exception $exception){
                $this->logger->warn('PayPal cleanUpWebProfiles() failed: ' . $exception->getMessage());
            }
        }
    }

    private function executePaymentAfterUserConfirmation(array $requestArray)
    {
        try {
            $payerId = $requestArray['PayerID'];
            $paymentId = $requestArray['paymentId'];
            $payment = Payment::get($paymentId, $this->apiContext);

            $execution = new PaymentExecution();
            $execution->setPayerId($payerId);

            $payment->execute($execution, $this->apiContext);
        } catch (\Exception $exception){
            
        }
    }
}
