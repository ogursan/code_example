<?php

namespace Shopen\AppBundle\Components\Payment\Payments;


use Shopen\AppBundle\Components\Cache\RedisCache;
use Shopen\AppBundle\Components\Payment\Enum\PaymentNotificationWayEnum;
use Shopen\AppBundle\Components\Payment\Exception\WrongPaymentDataException;
use Shopen\AppBundle\Components\Payment\OnlyNotificationUrlInterface;
use Shopen\AppBundle\Components\Payment\PaidItem;
use Shopen\AppBundle\Components\Payment\PaymentDataBag;
use Shopen\AppBundle\Components\Payment\PaymentMethod;
use Shopen\AppBundle\Components\Payment\PaymentResponse;
use Shopen\AppBundle\Components\Payment\PaymentSystemInterface;
use Shopen\AppBundle\Components\Payment\DeferredBillInterface;
use Shopen\AppBundle\Components\Payment\PaymentTicket;
use Shopen\AppBundle\Components\Payment\RedirectDataBag;
use Shopen\AppBundle\Entity\Price;
use Shopen\AppBundle\Helpers\KostylHelper;
use Shopen\AppBundle\Helpers\PriceHelper;
use Shopen\AppBundle\Repository\CurrencyRepository;
use Shopen\AppBundle\Repository\OrderRepository;
use Shopen\AppBundle\Security\User\User;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class Sberbank implements PaymentSystemInterface, DeferredBillInterface, OnlyNotificationUrlInterface
{
    /**
     * @var Router
     */
    private $router;

    /**
     * @var CurrencyRepository
     */
    private $currencyRepository;

    /**
     * @var KostylHelper
     */
    private $kostylHelper;

    public function __construct(Router $router, CurrencyRepository $currencyRepository, KostylHelper $kostylHelper)
    {
        $this->router = $router;
        $this->currencyRepository = $currencyRepository;
        $this->kostylHelper = $kostylHelper;
    }

    /**
     * @param Request $request
     * @param string $countryCode
     * @return PaymentDataBag
     * @throws \Shopen\AppBundle\Repository\Exception\DataNotFoundException
     * @throws \Shopen\AppBundle\Repository\Exception\DatabaseException
     */
    public function getPaymentData(Request $request, $countryCode)
    {
        $currency = $this->currencyRepository->load('RUB');

        $price = new Price();
        $price->setCurrency($currency);

        $price = PriceHelper::setFormatValue($price, $request->get('sum'));

        $paymentData = new PaymentDataBag();
        $paymentData
            ->setOrderId($request->get('account'))
            ->setContract($request->get('contract'))
            ->setPaymentId($request->get('txn_id'))
            ->setLanguageCode($request->getLocale())
            ->setPrice($price)
            ->setStatus('success');

        return $paymentData;
    }

    /**
     * @param Request $request
     * @return bool
     */
    public function validateRequest(Request $request)
    {
        $acceptIps = [
            '94.51.87.80',
            '94.51.87.83',
            '94.51.87.85',
            '127.0.0.1',
            '194.226.174.3',
        ];

        $acceptRanges = [
            '194.186.207.0' => '194.186.207.255',
            '194.54.14.0' => '194.54.14.255',
        ];

        $clientIp = $request->getClientIp();

        if (in_array($clientIp, $acceptIps)) {
            return true;
        }

        foreach ($acceptRanges as $min => $max) {
            if (ip2long($clientIp) > ip2long($min) && ip2long($clientIp) < ip2long($max)) {
                return true;
            }
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

        if ($paymentResponse->isSuccess()) {
            $responseCode = 0;
        } elseif (isset($codeMap[$paymentResponse->getMessageCode()])) {
            $responseCode = $codeMap[$paymentResponse->getMessageCode()];
        } else {
            $responseCode = 300;
        }

        $responseContent = '<?xml version="1.0" encoding="UTF-8" ?>'
            . '<response>'
            . '<osmp_txn_id>' . $paymentResponse->getRequest()->get('txn_id') . '</osmp_txn_id>'
            . '<prv_txn>' . $paymentResponse->getRequest()->get('account') . '</prv_txn>'
            . '<sum>' . $paymentResponse->getRequest()->get('sum') . '</sum>'
            . '<result>' . $responseCode . '</result>'
            . '<comment>' . $paymentResponse->getMessage() . '</comment>'
            . '</response>';

        return new Response($responseContent, 200);
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
        $params = [
            'order_id' => $orderId,
            'contract' => $user->getContract(),
        ];

        try {
            if(!$this->kostylHelper->getMobileTyp($user->getContract())) {
                $redirectUrl = $this->router->generate('shopen_app_cart_payment_success', ['paysys' => $this->getAlias()]);
            } else {
                $redirectUrl = $successUrl;
            }
        } catch (\Exception $e) {
            $redirectUrl = $this->router->generate('shopen_app_cart_payment_success', ['paysys' => $this->getAlias()]);
        }

        $redirectData = new RedirectDataBag();

        $redirectData
            ->setMethod(RedirectDataBag::METHOD_POST)
            ->setParams($params)
            ->setUrl($redirectUrl);

        return $redirectData;
    }

    /**
     * Return PaymentSystem string code
     *
     * @return string
     */
    public function getAlias()
    {
        return 'sberbank';
    }

    /**
     * Return list of supported country codes
     *
     * @return array
     */
    public function getCountryCodes()
    {
        return ['ru'];
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
            PaymentMethod::SBERBANK_TERMINAL
        ];
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
     * @return bool
     */
    public function canPrintBill()
    {
        return true;
    }

    /**
     * @param Request $request
     * @param PaymentTicket $paymentTicket
     * @return bool
     */
    public function resolveDeferredPayment(Request $request, PaymentTicket $paymentTicket)
    {
        return $this->getAlias() == $paymentTicket->getPaymentSystem() && $request->get('sum') == $paymentTicket->getSum();
    }

    /**
     * @return string
     */
    public function getAccountParamName()
    {
        return 'account';
    }

    /**
     * @return string
     */
    public function getAmountParamName()
    {
        return 'sum';
    }

    /**
     * @return string
     */
    public function getPaymentIdParamName()
    {
        return 'txn_id';
    }

    /**
     * @inheritdoc
     */
    public function getPaymentNotificationWay()
    {
        return PaymentNotificationWayEnum::create(PaymentNotificationWayEnum::GATEWAY_TO_SHOP);
    }

}