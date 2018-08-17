<?php

namespace Shopen\AppBundle\Components\Payment;

use Shopen\AppBundle\Components\Cache\RedisCacheDB1;
use Shopen\AppBundle\Components\Delivery\Exception\DeliveryException;
use Shopen\AppBundle\Components\L10n\L10n;
use Shopen\AppBundle\Components\L10n\Translator;
use Shopen\AppBundle\Components\Payment\Exception\UndefinedNotificationTypeException;
use Shopen\AppBundle\Components\Payment\Exception\UndefinedPaymentSystemException;
use Shopen\AppBundle\Components\Payment\Exception\WrongPaymentDataException;
use Shopen\AppBundle\Components\Payment\Payments\SberbankAcquiring;
use Shopen\AppBundle\Entity\Cart;
use Shopen\AppBundle\Entity\Client;
use Shopen\AppBundle\Entity\Currency;
use Shopen\AppBundle\Entity\Header;
use Shopen\AppBundle\Entity\Price;
use Shopen\AppBundle\Entity\RegistrationAdditionalInfo;
use Shopen\AppBundle\Helpers\ClientHelper;
use Shopen\AppBundle\Helpers\DeliveryHelper;
use Shopen\AppBundle\Helpers\Exception\HelpersException;
use Shopen\AppBundle\Helpers\KitHelper;
use Shopen\AppBundle\Helpers\PaymentHelper;
use Shopen\AppBundle\Helpers\PriceHelper;
use Shopen\AppBundle\Helpers\RegistrationHelper;
use Shopen\AppBundle\Helpers\RouteHelper;
use Shopen\AppBundle\Helpers\UtilHelper;
use Shopen\AppBundle\Repository\ActionRepository;
use Shopen\AppBundle\Repository\CartRepository;
use Shopen\AppBundle\Repository\ClientRepository;
use Shopen\AppBundle\Repository\CountryRepository;
use Shopen\AppBundle\Repository\CurrencyRepository;
use Shopen\AppBundle\Repository\Exception\DatabaseException;
use Shopen\AppBundle\Repository\Exception\DataNotFoundException;
use Shopen\AppBundle\Repository\HeaderRepository;
use Shopen\AppBundle\Repository\OrderRepository;
use Shopen\AppBundle\Repository\ProductRepository;
use Shopen\AppBundle\Security\User\User;
use Shopen\AppBundle\Utils\Client\CashRegisterQueue;
use Shopen\AppBundle\Utils\Client\Util;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\HttpFoundation\Request;
use Shopen\AppBundle\Utils\Client\Order as OrderModel;
use Symfony\Component\Security\Acl\Exception\Exception;


/**
 * Class PaymentManager
 * @package Shopen\AppBundle\Components\Payment
 */
class PaymentManager
{
    const NOTIFICATION_TYPE_ORDER = 1;

    const NOTIFICATION_TYPE_REGISTRATION = 2;

    /**
     * @var PaymentSystemInterface[]
     */
    private $paymentSystems = [];

    /**
     * @var array
     */
    private $paymentSystemInCountry = [];

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var PaymentHelper
     */
    private $paymentHelper;

    /**
     * @var PriceHelper
     */
    private $priceHelper;

    /**
     * @var DeliveryHelper
     */
    private $deliveryHelper;

    /**
     * @var CartRepository
     */
    private $cartRepository;

    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var Translator
     */
    private $translator;

    /**
     * @var ClientHelper
     */
    private $clientHelper;

    /**
     * @var KitHelper
     */
    private $kitHelper;

    /**
     * @var HeaderRepository
     */
    private $headerRepository;

    /**
     * @var ClientRepository
     */
    private $clientRepository;

    /**
     * @var CashRegister
     */
    private $cashRegister;

    /**
     * @var CashRegisterQueue
     */
    private $cashRegisterQueue;

    /**
     * @var ProductRepository
     */
    private $productRepository;

    /**
     * @var ActionRepository
     */
    private $actionRepository;

    /**
     * @var L10n
     */
    private $l10n;

    /**
     * @var OrderModel
     */
    private $orderModel;

    /**
     * @var array
     */
    private $exchangeRates;

    /**
     * @var Util
     */
    private $utilModel;

    /**
     * @var RouteHelper
     */
    private $routeHelper;

    /**
     * @var RegistrationHelper
     */
    private $registrationHelper;

    /**
     * @var CountryRepository
     */
    private $countryRepository;

    /**
     * @var CurrencyRepository
     */
    private $currencyRepository;

    /**
     * @var RedisCacheDB1
     */
    private $redisCacheDb1;

    public function __construct(
        OrderRepository $orderRepository,
        PaymentHelper $paymentHelper,
        PriceHelper $priceHelper,
        DeliveryHelper $deliveryHelper,
        CartRepository $cartRepository,
        Logger $logger,
        Translator $translator,
        ClientHelper $clientHelper,
        KitHelper $kitHelper,
        HeaderRepository $headerRepository,
        OrderModel $orderModel,
        CashRegister $cashRegister,
        CashRegisterQueue $cashRegisterQueue,
        ClientRepository $clientRepository,
        ProductRepository $productRepository,
        ActionRepository $actionRepository,
        L10n $l10n,
        Util $util,
        RouteHelper $routeHelper,
        RegistrationHelper $registrationHelper,
        CountryRepository $countryRepository,
        CurrencyRepository $currencyRepository,
        RedisCacheDB1 $redisCacheDb1
    ) {
        $this->orderRepository = $orderRepository;
        $this->paymentHelper = $paymentHelper;
        $this->priceHelper = $priceHelper;
        $this->deliveryHelper = $deliveryHelper;
        $this->cartRepository = $cartRepository;
        $this->logger = $logger;
        $this->translator = $translator;
        $this->clientHelper = $clientHelper;
        $this->kitHelper = $kitHelper;
        $this->headerRepository = $headerRepository;
        $this->orderModel = $orderModel;
        $this->cashRegister = $cashRegister;
        $this->cashRegisterQueue = $cashRegisterQueue;
        $this->clientRepository = $clientRepository;
        $this->productRepository = $productRepository;
        $this->actionRepository = $actionRepository;
        $this->l10n = $l10n;
        $this->utilModel = $util;
        $this->routeHelper = $routeHelper;
        $this->registrationHelper = $registrationHelper;
        $this->countryRepository = $countryRepository;
        $this->currencyRepository = $currencyRepository;
        $this->redisCacheDb1 = $redisCacheDb1;
    }

    /**
     * @param Header $header
     * @param Price $price
     * @return array
     * @throws WrongPaymentDataException
     */
    public function getPaidItems(Header $header, Price $price)
    {
        $paidItems = [];

        $countryCode = $this->l10n->getCountryCode();
        $languageCode = $this->l10n->getLocale();

        foreach ($header->getPackages() as $package) {
            foreach ($package->getItems() as $item) {
                $paidItem = new PaidItem();
                try {
                    $product = $this->productRepository->load($item->getSku(), $countryCode, $languageCode);
                    $paidItem->setName($product->getName());
                } catch (\Exception $e) {
                    $paidItem->setName($item->getName());
                }

                $itemPrice = $this->getConvertedPrice(
                    $item->getPrice(),
                    $price->getCurrency(),
                    $this->getExchangeRateFromOrder($header->getId())
                );

                $paidItem
                    ->setSku($item->getSku())
                    ->setPrice($itemPrice)
                    ->setCount($item->getCount());

                $paidItems[] = $paidItem;
            }

            foreach ($package->getKits() as $kit) {
                $paidItem = new PaidItem();
                try {
                    $action = $this->actionRepository->loadActionByKitSku($kit->getSku(), $countryCode, $languageCode);
                    $paidItem->setName($action->getName());
                } catch (\Exception $e) {
                    $paidItem->setName($kit->getName());
                }

                $paidItem
                    ->setSku($kit->getSku())
                    ->setPrice($kit->getPrice())
                    ->setCount($kit->getCount());

                $paidItems[] = $paidItem;
            }
        }

        // Доставка добавляется также, как и обычная позиция
        if ($header->getDeliveryPrice() instanceof Price && $header->getDeliveryPrice()->getValue() > 0) {
            $deliveryPrice = $header->getDeliveryPrice();
        } else {
            $deliveryPrice = new Price();
            $deliveryPrice
                ->setValue(0)
                ->setCurrency($price->getCurrency());
        }

        $deliveryPrice = $this->getConvertedPrice(
            $deliveryPrice,
            $price->getCurrency(),
            $this->getExchangeRateFromOrder($header->getId())
        );

        $deliveryPaidItem = new PaidItem();
        $deliveryPaidItem
            ->setName($this->translator->t('Доставка'))
            ->setPrice($deliveryPrice)
            ->setSku('DELIVERY')
            ->setCount(1);

        $paidItems[] = $deliveryPaidItem;

        $nominalPaymentSum = 0;
        array_map(function(PaidItem $item) use (&$nominalPaymentSum){
            $nominalPaymentSum += $item->getPrice()->getValue() * $item->getCount();
        }, $paidItems);

        $ratio = $price->getValue() / $nominalPaymentSum;

        $discountedProductsSum = 0;
        array_map(function(PaidItem $item) use ($ratio, &$discountedProductsSum){
            $priceValue = round($item->getPrice()->getValue() * $ratio, $item->getPrice()->getCurrency()->getDecimals());
            $item->getPrice()->setValue($priceValue);
            $discountedProductsSum += $priceValue * $item->getCount();
        }, $paidItems);

        // Если из-за округления произошла неточность, то прибавим её к цене доставки
        $priceDifference = round($price->getValue() - $discountedProductsSum, $price->getCurrency()->getDecimals());

        if ($priceDifference > 0 || $deliveryPrice->getValue() > abs($priceDifference)) {
            $deliveryPriceValue = $deliveryPrice->getValue() + $priceDifference;
            $deliveryPrice->setValue($deliveryPriceValue);
        } else {
            $firstItem = $paidItems[0];

            if ($firstItem->getPrice()->getValue() < abs($priceDifference)) {
                foreach ($paidItems as $item) {
                    if ($item->getPrice()->getValue() >= abs($priceDifference)) {
                        $firstItem = $item;
                        break;
                    }
                }
            }

            if ($firstItem->getCount() == 1) {
                $correctedPrice = $firstItem->getPrice()->getValue() + $priceDifference;
                $firstItem->getPrice()->setValue($correctedPrice);
            } else {
                $firstItem->setCount($firstItem->getCount() - 1);

                $discountedFirstItem = clone $firstItem;
                $discountedFirstItem->setCount(1);

                /** @var Price $discountedPrice */
                $discountedPrice = clone $firstItem->getPrice();
                $discountedPrice->setValue($discountedFirstItem->getPrice()->getValue() + $priceDifference);
                $discountedFirstItem->setPrice($discountedPrice);
                array_unshift($paidItems, $discountedFirstItem);
            }
        }

        return $paidItems;
    }

    /**
     * @param PaymentSystemInterface $paymentSystem
     * @param $paymentTypeAlias
     * @param Price $price - orderPaymentSum
     * @param User $user
     * @param $orderId
     * @param $successUrl
     * @param $failUrl
     * @param $handleNotificationUrl
     * @param $description
     * @param $countryCode
     * @param $languageCode
     * @return RedirectDataBag
     * @throws DataNotFoundException
     * @throws DatabaseException
     * @throws WrongPaymentDataException
     */
    public function getRedirectDataForOrder(
        PaymentSystemInterface $paymentSystem,
        $paymentTypeAlias,
        Price $price,
        User $user,
        $orderId,
        $successUrl,
        $failUrl,
        $handleNotificationUrl,
        $description,
        $countryCode,
        $languageCode
    ) {
        $handleNotificationUrl = preg_replace('/^http:/i', 'https:', $handleNotificationUrl);

        $header = $this->headerRepository->load($user, $orderId, $countryCode, $languageCode);
        $paidItems = $this->getPaidItems($header, $price);

        try {
            $paymentData = $this->orderModel->getPaymentInfo($orderId);
        } catch (DatabaseException $e) {
            //
        }

        // Нормально: $paymentData = array of 1
        // Поэтому условие в if (!empty($paymentData) && isset($paymentData['vat'])) никогда не срабатывало
        if ($paymentData) {
            $paymentData = $paymentData[0];
        }

        if (!empty($paymentData) && isset($paymentData['vat'])) {
            $tax = $this->priceHelper->denormalizePrice($paymentData['vat'], true);
        } else {
            $tax = new Price();
            $tax->setValue(0)->setCurrency($price->getCurrency());
        }

        /**
         * Пламенный привет Государственной Думе РФ, запретившей выставлять счета в иностранной валюте
         */
        if ($paymentSystem instanceof CurrencyConstraintInterface) {
            $price = $this->getConvertedPrice(
                $price,
                $paymentSystem->getAvailableCurrency(),
                $this->getExchangeRateFromOrder($orderId)
            );
            $tax = $this->getConvertedPrice(
                $tax,
                $paymentSystem->getAvailableCurrency(),
                $this->getExchangeRateFromOrder($orderId)
            )->setValue(0);
        }

        return $paymentSystem->getRedirectData(
            $paymentTypeAlias,
            $price,
            $user,
            $orderId,
            $successUrl,
            $failUrl,
            $handleNotificationUrl,
            $description,
            $languageCode,
            $tax,
            $paidItems
        );
    }

    /**
     * @param PaymentSystemInterface $paymentSystem
     * @param Price $price
     * @param User $user
     * @param string $successUrl
     * @param string $failUrl
     * @param string $handleNotificationUrl
     * @param string $description
     * @param string $languageCode
     * @param string $paymentTypeAlias
     * @return RedirectDataBag
     */
    public function getRedirectDataForRegistration(
        PaymentSystemInterface $paymentSystem,
        Price $price,
        User $user,
        $successUrl,
        $failUrl,
        $handleNotificationUrl,
        $description,
        $languageCode,
        $paymentTypeAlias
    ) {
        $handleNotificationUrl = preg_replace('/^http:/i', 'https:', $handleNotificationUrl);

        $paidItem = new PaidItem();

        $paidItem
            ->setSku('REGISTRATION')
            ->setName($this->translator->t('Регистрация Консультанта'))
            ->setPrice($price)
            ->setCount(1);

        return $paymentSystem->getRedirectData(
            $paymentTypeAlias,
            $price,
            $user,
            $user->getContract(),
            $successUrl,
            $failUrl,
            $handleNotificationUrl,
            $description,
            $languageCode,
            null,
            [$paidItem]
        );
    }

    /**
     * @param Request $request
     * @param $countryCode
     * @param int $type
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws DataNotFoundException
     * @throws DatabaseException
     * @throws UndefinedNotificationTypeException
     * @throws UndefinedPaymentSystemException
     * @throws WrongPaymentDataException
     * @throws \Shopen\AppBundle\Helpers\Exception\HelpersException
     */
    public function handlePaymentNotification(Request $request, $countryCode, $type = self::NOTIFICATION_TYPE_ORDER)
    {
        $paymentSystemAlias = $this->paymentHelper->getPaymentSystemAlias($request);
        /** @var PaymentSystemInterface $paymentSystem */
        $paymentSystem = $this->getPaymentSystem($paymentSystemAlias);

        /**
         * Некоторые платёжные системы не умеют принимать разные notificationUrl,
         * поэтому оплату регистрации надо обработать здесь
         */
        if (
            $paymentSystem instanceof OnlyNotificationUrlInterface
            && false !== stripos((string) $request->get('orderNumber'), 'r')
        ) {
            $type = self::NOTIFICATION_TYPE_REGISTRATION;
        }

        switch ($type) {
            case self::NOTIFICATION_TYPE_ORDER:
                $paymentResponse = $this->processOrderPayment($paymentSystem, $request, $countryCode);
                break;
            case self::NOTIFICATION_TYPE_REGISTRATION:
                $paymentResponse = $this->processRegistrationNotification($paymentSystem, $request, $countryCode);
                break;
            default:
                throw new UndefinedNotificationTypeException();
        }

        if (!$paymentResponse->isSuccess() && $paymentResponse->getMessage()) {
            $this->logger->error($paymentResponse->getMessage());
        }

        if ($paymentResponse->isSuccess() && $paymentSystem instanceof PaymentConfirmInterface) {
            $paymentSystem->confirmPayment($request);
        }

        $paymentResponse->setRequest($request);

        return $paymentSystem->buildResponse($paymentResponse);
    }

    /**
     * @param PaymentSystemInterface $paymentSystem
     * @param Request $request
     * @param $countryCode
     * @return PaymentResponse
     * @throws DataNotFoundException
     * @throws DatabaseException
     * @throws WrongPaymentDataException
     * @throws \Shopen\AppBundle\Helpers\Exception\HelpersException
     */
    private function processOrderPayment(PaymentSystemInterface $paymentSystem, Request $request, $countryCode)
    {
        $paymentResponse = new PaymentResponse();

        if (!$paymentSystem->validateRequest($request)) {
            // Incorrect request from payment system
            $paymentResponse
                ->setSuccess(false)
                ->setMessageCode(PaymentResponse::STATUS_INVALID_REQUEST)
                ->setMessage($this->translator->t('Неверная подпись от платежной системы'));

            return $paymentResponse;
        }

        try {
            $paymentData = $paymentSystem->getPaymentData($request, $countryCode);
        } catch (WrongPaymentDataException $e) {
            $paymentResponse
                ->setSuccess(false)
                ->setMessageCode(PaymentResponse::STATUS_INVALID_REQUEST)
                ->setMessage($e->getMessage());
            return $paymentResponse;
        }

        // Запись транзакции в кэш
        $dataForCache = [
            'url' => $request->getUri(),
            'post' => $request->request->all(),
            'get' => $request->query->all(),
        ];

        $cacheKey = "transaction:order:{$paymentSystem->getAlias()}:{$paymentData->getOrderId()}:" . md5(serialize($paymentData));
        $this->redisCacheDb1->set($cacheKey, serialize($dataForCache));

        if ($paymentData->getStatus() != $paymentSystem->getSuccessStatusCode()) {
            $paymentResponse
                ->setSuccess(false)
                ->setMessageCode(PaymentResponse::STATUS_NOT_SUCCESS);
            return $paymentResponse;
        }

        /**
         * Обработка отложенных счетов
         * @TODO Подключить конвертацию валют
         */
        if($paymentSystem instanceof DeferredBillInterface) {
            return $this->completeDeferredPayment($request, $paymentData, $paymentSystem, $paymentResponse, $countryCode);
        }

        $orderInfo = $this->checkOrder($paymentData->getOrderId());
        $realOrderPrice = $this->priceHelper->denormalizePrice($orderInfo['paymentSum'], true);

        if (!isset($orderInfo['exists']) || !$orderInfo['exists']) {
            // Non-existent order
            $paymentResponse
                ->setSuccess(false)
                ->setMessageCode(PaymentResponse::STATUS_ORDER_NOT_EXISTS)
                ->setMessage(
                    $this->translator->t(
                        'Заказ ~orderId~ не найден',
                        ['orderId' => $paymentData->getOrderId()]
                    )
                );

            return $paymentResponse;
        }

        if (
            $paymentSystem instanceof CurrencyConstraintInterface
            && $paymentData->getPrice()->getCurrency()->getId() != $realOrderPrice->getCurrency()->getId()
        ) {
            $paymentData->setPrice(
                $this->getRevertedPrice(
                    $paymentData->getPrice(),
                    $realOrderPrice->getCurrency(),
                    $this->getExchangeRateFromOrder($paymentData->getOrderId())
                )
            );
        }
        $correctSumPayed = false;
        try{
            $correctSumPayed = (PriceHelper::compare($paymentData->getPrice(), $realOrderPrice) === 0);
        } catch ( HelpersException $exception) {
            $rubCurrency = $this->currencyRepository->load('RUB');
            $paymentPriceInRub = $this->priceHelper->convert($paymentData->getPrice(), $rubCurrency);
            $realPriceInRub = $this->priceHelper->convert($realOrderPrice, $rubCurrency);
            try {
                $correctSumPayed = (0 === PriceHelper::compare($paymentPriceInRub, $realPriceInRub));
            } catch ( Exception $exception){}

        }


        if (!$correctSumPayed) {
            // Incorrect sum payed
            $statusCode = $paymentData->getPrice()->getValue() < $realOrderPrice->getValue()
                ? PaymentResponse::STATUS_LESS_SUM
                : PaymentResponse::STATUS_MORE_SUM;

            $paymentResponse
                ->setSuccess(false)
                ->setMessageCode($statusCode)
                ->setMessage(
                    $this->translator->t(
                        'Неверно задана сумма для оплаты: ~orderId~: ~paidSum~ вместо ~realSum~',
                        [
                            'orderId' => $paymentData->getOrderId(),
                            'paidSum' => $paymentData->getPrice()->getValue(),
                            'realSum' => $realOrderPrice->getValue(),
                        ]
                    )
                );

            return $paymentResponse;
        }


        if ($paymentData->getPrice()->getValue() > 0 && intval($paymentData->getOrderId()) > 0) {
            if ($orderInfo['exists'] && $orderInfo['status'] != Header::STATUS_PAYED) {
                $paidCart = $this->cartRepository->loadFromDatabaseById(
                    $orderInfo['cartId'],
                    $paymentData->getLanguageCode()
                );
                $paidCart->setHeaderId($paymentData->getOrderId());

                $params = [
                    'headerId' => $paymentData->getOrderId(),
                    'cartId' => $orderInfo['cartId'],
                    'contract' => $paidCart->getClient()->getContract(),
                    'countryCode' => $orderInfo['countryCode'],
                    'languageCode' => $paymentData->getLanguageCode(),
                    'amount' => PriceHelper::normalizePrice($realOrderPrice, true),
                    'paymentId' => $paymentData->getPaymentId(),
                    'status' => 1,
                    'paymentSystemName' => $paymentSystem->getAlias(),
                ];

                try {
                    $this->orderRepository->executeOrder($params);
                } catch (\Exception $e) {
                    // Order execution error
                    $paymentResponse
                        ->setSuccess(false)
                        ->setMessageCode(PaymentResponse::STATUS_ORDER_EXECUTION_ERROR)
                        ->setMessage($e->getMessage());
                }

                // Creating delivery request
                if ($paidCart instanceof Cart) {
                    $this->createDeliveryRequest($paidCart, $paymentData, $paymentSystem, $params);
                }

                /**
                 * Пробиваем кассовый чек, если требуется
                 */
                if ($paymentSystem instanceof CurrencyConstraintInterface) {
                    $paymentData->setPrice(
                        $this->getConvertedPrice(
                            $paymentData->getPrice(),
                            $paymentSystem->getAvailableCurrency(),
                            $this->getExchangeRateFromOrder($paymentData->getOrderId())
                        )
                    );
                }
                $this->registerPayment($paymentSystem, $paymentData, $countryCode);

                if ($paymentSystem instanceof PaymentReportInterface) {
                    $transaction = $paymentSystem->buildTransaction($paymentData);

                    $this->utilModel->addPaySystem(
                        $paymentSystem->getDbAlias(),
                        $transaction->getId(),
                        $transaction->getOrderId(),
                        $transaction->getSum(),
                        $transaction->getType(),
                        $transaction->getDate()->format('d.m.Y'),
                        null,
                        $transaction->getCurrency()
                    );
                }
            } else {
                // Order is already payed
                $paymentResponse
                    ->setSuccess(false)
                    ->setMessageCode(PaymentResponse::STATUS_ALREADY_PAYED)
                    ->setMessage(
                        $this->translator->t(
                            'Заказ ~orderId~ был успешно оплачен ранее',
                            ['orderId' => $paymentData->getOrderId()]
                        )
                    );
            }
        }

        $paymentResponse->setSuccess(true);

        return $paymentResponse;
    }

    /**
     * @param string $id
     * @return PaymentTicket
     */
    private function getPaymentTicket($id)
    {
        $ticket = new PaymentTicket();
        $data = $this->orderRepository->getInfoByTicket($id);

        $ticket->setId($id);
        $ticket->setCartId(isset($data['basket_id']) ? $data['basket_id'] : null);
        $ticket->setContract(isset($data['contract']) ? $data['contract'] : null);
        $ticket->setSum(isset($data['payment_sum']) ? $data['payment_sum'] : null);
        $ticket->setPaymentSystem(isset($data['pmt_system']) ? $data['pmt_system'] : null);

        return $ticket;
    }

    /**
     * @param Request $request
     * @param PaymentDataBag $paymentData
     * @param DeferredBillInterface $paymentSystem
     * @param PaymentResponse $paymentResponse
     * @param $countryCode
     * @return PaymentResponse
     * @throws DatabaseException
     */
    private function completeDeferredPayment(
        Request $request,
        PaymentDataBag $paymentData,
        DeferredBillInterface $paymentSystem,
        PaymentResponse $paymentResponse,
        $countryCode
    )
    {
        $account = $request->get($paymentSystem->getAccountParamName());
        $ticket = $this->getPaymentTicket(strtoupper($account));
        $cartId = $ticket->getCartId();
        if (empty($cartId)) {
            /**
             * В качестве account может быть передан не только номер тикета, но и номер контракта
             * для пополнения счёта пользователя.
             */
            if (preg_match('/^[a-z]/i', $account)) {
                $paymentResponse
                    ->setSuccess(false)
                    ->setMessageCode(PaymentResponse::STATUS_ORDER_NOT_EXISTS)
                    ->setMessage(
                        $this->translator->t(
                            'Не найдена соответствующая заказу ~orderId~ корзина',
                            ['orderId' => $paymentData->getOrderId()]
                        )
                    );
            } else {
                try {
                    $client = $this->clientRepository->load($account, $countryCode);
                    if ($client instanceof Client) {
                        if (
                            $this->orderRepository->deferredPaymentCompleted(
                                $account,
                                1,
                                $request->get($paymentSystem->getAmountParamName()),
                                $request->get($paymentSystem->getPaymentIdParamName())
                            )
                        ) {
                            $paymentResponse->setSuccess(true);
                            $this->clientRepository->clearClientCache($account);
                        } else {
                            $paymentResponse
                                ->setSuccess(false)
                                ->setMessageCode(PaymentResponse::STATUS_NOT_SUCCESS)
                                ->setMessage(
                                    $this->translator->t(
                                        'Не удалось пополнить счет ~contract~',
                                        ['contract' => $account]
                                    )
                                );
                        }
                    }
                } catch (DataNotFoundException $e) {
                    $paymentResponse
                        ->setSuccess(false)
                        ->setMessageCode(PaymentResponse::STATUS_NOT_SUCCESS)
                        ->setMessage(
                            $this->translator->t(
                                'Контракт ~contract~ не найден',
                                ['contract' => $account]
                            )
                        );
                }
            }
        } else {
            try {
                $cart = $this->cartRepository->loadFromDatabaseById(
                    $cartId,
                    $paymentData->getLanguageCode()
                );
                $headerStatus = $this->headerRepository->load(
                    $cart->getClient(),
                    $cart->getHeaderId(),
                    $countryCode,
                    $paymentData->getLanguageCode()
                )->getStatusId();
            } catch (\Exception $e) {
                $paymentResponse
                    ->setSuccess(false)
                    ->setMessageCode(PaymentResponse::STATUS_NOT_SUCCESS)
                    ->setMessage($e->getMessage());
                return $paymentResponse;
            }

            switch ($headerStatus) {
                case(Header::STATUS_CREATED):
                    $permit = $paymentSystem->resolveDeferredPayment($request, $ticket);
                    $paymentResponse->setSuccess((bool) $permit);
                    if (true === $permit) {
                        if ($this->orderRepository->deferredPaymentCompleted($ticket->getId(), 1, $ticket->getSum())) {
                            /**
                             * @var PaymentSystemInterface $paymentSystem
                             */
                            $this->createDeliveryRequest($cart, $paymentData, $paymentSystem, []);
                        } else {
                            $paymentResponse->setSuccess(false);
                        }
                    }
                    break;
                case(Header::STATUS_PAYED):
                case(Header::STATUS_DELIVERED):
                    $paymentResponse
                        ->setSuccess(false)
                        ->setMessageCode(PaymentResponse::STATUS_ALREADY_PAYED)
                        ->setMessage(
                            $this->translator->t(
                                'Заказ ~orderId~ был успешно оплачен ранее',
                                ['orderId' => $paymentData->getOrderId()]
                            )
                        );
                    break;
                default:
                    $paymentResponse
                        ->setSuccess(false)
                        ->setMessageCode(PaymentResponse::STATUS_ORDER_EXECUTION_ERROR)
                        ->setMessage(
                            $this->translator->t(
                                'Заказ ~orderId~ отменён',
                                ['orderId' => $paymentData->getOrderId()]
                            )
                        );
                    break;
            }

        }

        return $paymentResponse;
    }

    /**
     * @param Cart $paidCart
     * @param PaymentDataBag $paymentData
     * @param PaymentSystemInterface $paymentSystem
     * @param array $params
     * @return bool
     */
    private function createDeliveryRequest(Cart $paidCart, PaymentDataBag $paymentData, PaymentSystemInterface $paymentSystem, $params = [])
    {
        try {
            $serviceCompanyName = 'the service company';
            $serviceCompany = $paidCart->getDelivery()->getCompany();
            if (!$serviceCompany) {
                throw new DeliveryException('Cannot load delivery service.');
            }
            $serviceCompanyName = $serviceCompany->getId();

            $this->deliveryHelper->createDeliveryRequest(
                $serviceCompanyName,
                $paidCart,
                [
                    'orderId' => $paymentData->getOrderId(),
                    'contract' => $paidCart->getClient()->getContract(),
                ]
            );

            return true;

        } catch (DeliveryException $e) {
            $this->logger->error(
                "Can't set delivery request to $serviceCompanyName after {$paymentSystem->getAlias()} payment\n" .
                "Error: {$e->getMessage()} \n" .
                "Params: " . join(";\n", $params)
            );
        }

        return false;
    }

    /**
     * @param PaymentSystemInterface $paymentSystem
     * @param PaymentDataBag $paymentData
     * @param string $countryCode
     * @param bool $isRegistration
     */
    private function registerPayment($paymentSystem, $paymentData, $countryCode, $isRegistration = false)
    {
        $languageCode = $this->l10n->getLocale();

        try {
            if (!$paymentSystem->canPrintBill()) {
                $cashRegister = $this->cashRegister->forCountry($countryCode);
                if ($cashRegister) {
                    $client = $this->clientRepository->load($paymentData->getContract(), $countryCode);

                    if ($isRegistration) {
                        $item = new PaidItem();
                        $item
                            ->setSku('REGISTRATION')
                            ->setName($this->translator->t('Регистрация Консультанта'))
                            ->setPrice($paymentData->getPrice())
                            ->setCount(1);
                        $paidItems = [$item];
                    } else {
                        $header = $this->headerRepository->load($client, $paymentData->getOrderId(), $countryCode, $languageCode);
                        $paidItems = $this->getPaidItems($header, $paymentData->getPrice());
                    }

                    $cashRegister->createBill($paymentData, $paidItems, $client->getEmail());
                    $this->cashRegisterQueue->createTask(
                        $paymentData->getOrderId(),
                        $cashRegister
                    );
                }
            }
        } catch (\Exception $e) {
            $this->logger->error("CashRegister: " . $e->getMessage());
        }
    }

    /**
     * @param PaymentSystemInterface $paymentSystem
     * @param Request $request
     * @param $countryCode
     * @return PaymentResponse
     * @throws \Shopen\AppBundle\Helpers\Exception\HelpersException
     * @TODO Подключить конвертацию валют
     */
    public function processRegistrationNotification(
        PaymentSystemInterface $paymentSystem,
        Request $request,
        $countryCode
    ) {
        $paymentResponse = new PaymentResponse();

        if (!$paymentSystem->validateRequest($request)) {
            $paymentResponse
                ->setSuccess(false)
                ->setMessageCode(PaymentResponse::STATUS_INVALID_REQUEST)
                ->setMessage($this->translator->t('Неверная подпись от платежной системы'));

            return $paymentResponse;
        }

        /**
         * Обработка отложенных счетов
         * @TODO Подключить конвертацию валют
         */
        if($paymentSystem instanceof DeferredBillInterface) {
            $ticket = $this->getPaymentTicket(strtoupper($request->get($paymentSystem->getAccountParamName())));
            $permit = $paymentSystem->resolveDeferredPayment($request, $ticket);
            $paymentResponse->setSuccess((bool) $permit);
            if (
                true === $permit
                && !$this->orderRepository->deferredPaymentCompleted($ticket->getId(),1, $ticket->getSum())
            ) {
                $paymentResponse->setSuccess(false);
            }
            return $paymentResponse;
        }

        $paymentData = $paymentSystem->getPaymentData($request, $countryCode);

        if (!$paymentData->getContract()) {
            $paymentData->setContract($paymentData->getOrderId());
        }

        $params = $this->registrationHelper->getAdditionalRegistrationInfo($paymentData->getContract());
        if (!($params instanceof RegistrationAdditionalInfo)) {
            $params = new RegistrationAdditionalInfo($paymentData->getContract());
        }
        $correctPrice = $this->clientHelper->getConsultantRegistrationPrice($countryCode, $params->getRegistrationType());
        $this->registrationHelper->removeAdditionalRegistrationInfo($paymentData->getContract()); //TODO не факт что это надо делать тут.

        if (PriceHelper::compare($paymentData->getPrice(), $correctPrice) != 0) {
            $statusCode = $paymentData->getPrice()->getValue() < $correctPrice->getValue()
                ? PaymentResponse::STATUS_LESS_SUM
                : PaymentResponse::STATUS_MORE_SUM;

            $paymentResponse
                ->setSuccess(false)
                ->setMessageCode($statusCode)
                ->setMessage(
                    $this->translator->t(
                        'Неверная сумма оплаты регистрации контракта ~contract~: ~paidSum~ вместо ~realSum~',
                        [
                            'contract' => $paymentData->getContract(),
                            'paidSum' => $paymentData->getPrice()->getValue(),
                            'realSum' => $correctPrice->getValue(),
                        ]
                    )
                );

            return $paymentResponse;
        }

        if (!$this->clientHelper->confirmRegistrationPay($paymentData->getContract())) {
            $paymentResponse
                ->setSuccess(false)
                ->setMessageCode(PaymentResponse::STATUS_NOT_SUCCESS)
                ->setMessage(
                    $this->translator->t(
                        'Не удалось подтвердить оплату регистрации пользователя ~contract~',
                        ['contract' => $paymentData->getContract()]
                    )
                );

            return $paymentResponse;
        }

        if ($paymentSystem instanceof PaymentReportInterface) {
            $transaction = $paymentSystem->buildTransaction($paymentData);

            $this->utilModel->addPaySystem(
                $paymentSystem->getDbAlias(),
                $transaction->getId(),
                $transaction->getOrderId(),
                $transaction->getSum(),
                $transaction->getType(),
                $transaction->getDate()->format('d.m.Y'),
                null,
                $transaction->getCurrency()
            );
        }

        $paymentResponse->setSuccess(true);

        /**
         * Пробиваем кассовый чек, если требуется
         */
        $this->registerPayment($paymentSystem, $paymentData, $countryCode, true);

        if ($paymentSystem instanceof SberbankAcquiring) {
            $nowDateTime = new \DateTime('now');

            $this->utilModel->addPaySystem(
                $paymentSystem->getDbAlias(),
                $paymentData->getOrderId() . '/' . $paymentData->getHash(),
                null,
                $paymentData->getPrice()->getValue(),
                null,
                $nowDateTime->format('d.m.Y'),
                null
            );
        }

        return $paymentResponse;
    }

    /**
     * @param string $alias
     * @return bool
     */
    public function hasPaymentSystem($alias)
    {
        return isset($this->paymentSystems[$alias]);
    }

    /**
     * @param $alias
     * @return PaymentSystemInterface
     * @throws UndefinedPaymentSystemException
     */
    public function getPaymentSystem($alias)
    {
        if (!$this->hasPaymentSystem($alias)) {
            throw new UndefinedPaymentSystemException();
        }

        return $this->paymentSystems[$alias];
    }

    /**
     * @param PaymentSystemInterface $paymentSystem
     */
    public function addPaymentSystem(PaymentSystemInterface $paymentSystem)
    {
        $this->paymentSystems[$paymentSystem->getAlias()] = $paymentSystem;

        $supportedCountries = $paymentSystem->getCountryCodes();

        foreach ($supportedCountries as $countryCode) {
            if (!isset($this->paymentSystemInCountry[$countryCode])) {
                $this->paymentSystemInCountry[$countryCode] = [];
            }

            $this->paymentSystemInCountry[$countryCode][] = $paymentSystem->getAlias();
        }
    }

    /**
     * @param string $countryCode
     * @return PaymentSystemInterface[]
     * @throws UndefinedPaymentSystemException
     */
    public function getPaymentSystemsByCountryCode($countryCode)
    {
        $paymentSystemAliases = $this->paymentSystemInCountry[$countryCode];

        $paymentSystems = [];
        foreach ($paymentSystemAliases as $alias) {
            $paymentSystems[$alias] = $this->getPaymentSystem($alias);
        }

        return $paymentSystems;
    }

    /**
     * @return PaymentSystemInterface[]
     */
    public function getAllPaymentSystems()
    {
        return $this->paymentSystems;
    }

    /**
     * @param $orderId
     * @return array
     */
    private function checkOrder($orderId)
    {
        $response = [
            'exists' => false,
            'status' => null,
        ];

        //make a call to a webservice
        try {
            $orderInfo = $this->orderRepository->load($orderId);
        } catch (DataNotFoundException $e) {
            return $response;
        }

        $response['exists'] = true;
        $response['status'] = $orderInfo['status_header'];
        $response['cartId'] = $orderInfo['id_basket'];
        $response['deliveryAddressId'] = $orderInfo['id_adress'];
        $response['countryCode'] = $orderInfo['country'];
        $response['paymentSum'] = $orderInfo['payment_sum'];

        return $response;
    }

    /**
     * @param Price $price
     * @param Currency $currencyTo
     * @param float $rate
     * @return Price
     */
    public function getConvertedPrice(Price $price, Currency $currencyTo, $rate)
    {
        if ($price->getCurrency()->getId() != $currencyTo->getId()) {
            return $this->priceHelper->convert($price, $currencyTo, $rate);
        }

        return $price;
    }

    /**
     * @param Price $price
     * @param Currency $currencyTo
     * @param float $rate
     * @return Price
     */
    public function getRevertedPrice(Price $price, Currency $currencyTo, $rate)
    {
        $rate = $rate ?: 1;
        if ($price->getCurrency()->getId() != $currencyTo->getId()) {
            return $this->priceHelper->convert($price, $currencyTo, 1 / $rate);
        }

        return $price;
    }

    /**
     * @param int $orderId
     * @return float
     * @throws WrongPaymentDataException
     */
    public function getExchangeRateFromOrder($orderId)
    {
        if (empty($this->exchangeRates[$orderId])) {
            $orderInfo = $this->orderRepository->load($orderId);
            if (empty($orderInfo['rate'])) {
                throw new WrongPaymentDataException($this->translator->t('Не удалась конвертация валют'));
            }
            $this->exchangeRates[$orderId] = $orderInfo['rate'];
        }

        return $this->exchangeRates[$orderId];
    }

    /**
     * Формирование данных для редиректа на платежный шлюз
     *
     * @param User $user
     * @param Request $request
     * @param string $oldContract
     * @param bool $forcePayment
     * @return array
     * @throws DataNotFoundException
     */
    public function getRedirectDataForPayment(User $user, Request $request, $oldContract = '',$forcePayment = false)
    {
        $quickPayPageUrl = $this->routeHelper->generate('shopen_app_cart_delivery_with_registration');
        $method = RedirectDataBag::METHOD_GET;
        $params = [];
        if (UtilHelper::isReferrerFromUrl($quickPayPageUrl, $request)) {
            $redirectUrl = $this->routeHelper->generate('shopen_app_cart_payment');
        } else {
            $redirectUrl = $this->routeHelper->generate('shopen_app_user_registration_success');
        }

        $redirectArray = [
            'redirect' => $redirectUrl,
            'method' => $method,
            'params' => $params,
        ];

        $registrationNotFree = $this->registrationHelper->isTollRegistration($user) &&
            (trim($oldContract) == '' || strlen($oldContract) == 10);

        if ($forcePayment || $registrationNotFree) {

            $countryCode = $user->getCountry();
            $country = $this->countryRepository->load($countryCode, null, null);
            $scheme = $request->getScheme();
            $host = $request->getHost();
            $host = preg_replace('/^((?:dev|test)?\.)?[a-z]{2}\.(.+)/i', '$1' . $country->getCntr() . '.$2', $host);
            $hash = $this->paymentHelper->getRegistrationPaymentHash(
                $user,
                $user->getCountry()
            );
            $url = $this->routeHelper->generate(
                'shopen_app.payment.registration',
                [
                    'contract' => $user->getContract(),
                    'paymentId' => $hash,
                    'oldContract' => $oldContract,
                    '_locale' => $country->getDefaultLanguage()->getLocaleCode()
                ]
            );

            $redirectArray =  [
                'method' => RedirectDataBag::METHOD_GET,
                'redirect' => $scheme . '://' . $host . $url,
                'params' => []
            ];
        }

        return $redirectArray;

    }
}
