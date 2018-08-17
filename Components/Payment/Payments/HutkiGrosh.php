<?php

namespace Shopen\AppBundle\Components\Payment\Payments;

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
use Shopen\AppBundle\Helpers\PriceHelper;
use Shopen\AppBundle\Repository\CurrencyRepository;
use Shopen\AppBundle\Repository\OrderRepository;
use Shopen\AppBundle\Security\User\User;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class HutkiGrosh implements PaymentSystemInterface, DeferredBillInterface, OnlyNotificationUrlInterface
{
    /**
     * @var Router
     */
    private $router;

    /**
     * @var int
     */
    private $serviceId;

    /**
     * @var CurrencyRepository
     */
    private $currencyRepository;

    /**
     * @var int
     */
    private $step = 0;

    /**
     * @var PaymentTicket
     */
    private $ticket;

    /**
     * HutkiGrosh constructor.
     * @param Router $router
     * @param CurrencyRepository $currencyRepository
     * @param $serviceId
     */
    public function __construct(Router $router, CurrencyRepository $currencyRepository, $serviceId)
    {
        $this->router = $router;
        $this->currencyRepository = $currencyRepository;
        $this->serviceId = $serviceId;
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
        $currency = $this->currencyRepository->load('BYN');

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
        $type = $request->get('type');
        $serviceId = $request->get('serviceId');
        $account = $request->get('account');
        if (!$type || !$serviceId || !$account || $this->serviceId != $serviceId) {
            return false;
        }

        $acceptIps = [
            '31.130.201.2',
            '31.130.201.3',
            '31.130.201.4',
            '31.130.201.5',
            '194.158.197.194',
            '127.0.0.1',
        ];

        $acceptRanges = [
            '194.186.207.0' => '194.186.207.255',
            '194.54.14.0' => '194.54.14.255',
            '192.168.0.0' => '192.168.255.255',
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
        $responseContent = [];
        $responseContent['ticket'] = null;

        switch ($this->step) {
            case 1:
                /*
                 * ЗАПРОС ПОЛУЧЕНИЯ ИНФОРМАЦИИ О СЧЕТЕ ИЗ БИЛЛИНГОВОЙ СИСТЕМЫ ПОСТАВЩИКА УСЛУГ
                 */
                $responseContent['amount'] = round($this->ticket->getSum(), 2);
                $responseContent['editable'] = false;
                break;
            case 2:
                /*
                 * ЗАПРОС НАЧАЛА ОПЛАТЫ
                 */
                $responseContent['unipayTrxId'] = hexdec(bin2hex($this->ticket->getId()));
                break;
            case 3:
                /*
                 * ЗАПРОС ЗАВЕРШЕНИЯ ОПЛАТЫ
                 */
                $responseContent['ticket'] = [
                    "Благодарим за оплату счёта номер " . $this->ticket->getId()
                ];
                break;
            default:
                $responseContent['ticket'] = [
                    $paymentResponse->getMessage()
                ];
                break;
        }

        if ($paymentResponse->isSuccess()) {
            $responseContent['responseCode'] = 'allow';
        } else {
            $responseContent['responseCode'] = 'deny';
        }

        return new Response(json_encode($responseContent), 200);
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
        /**
         * Необходимо пометить оплату регистрации
         */
        if (count($items) && 'REGISTRATION' == $items[0]->getSku()) {
            $orderId = 'r' . $orderId;
        }

        $params = [
            'order_id' => $orderId,
            'contract' => $user->getContract(),
        ];

        $redirectUrl = $this->router->generate('shopen_app_cart_payment_success', ['paysys' => $this->getAlias()]);

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
        return 'hutkigrosh';
    }

    /**
     * Return list of supported country codes
     *
     * @return array
     */
    public function getCountryCodes()
    {
        return ['by'];
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
            PaymentMethod::HUTKIGROSH
        ];
    }

    /**
     * Check is payment success and get data for success-page
     *
     * @param $requestArray
     * @return array
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
     * @return bool|int
     */
    public function resolveDeferredPayment(Request $request, PaymentTicket $paymentTicket)
    {
        $this->ticket = $paymentTicket;
        if (!$paymentTicket->getSum() || $paymentTicket->getPaymentSystem() != $this->getAlias()) {
            return false;
        }

        switch ($request->get('type')) {
            case 'accountInfo':
                /*
                 * ЗАПРОС ПОЛУЧЕНИЯ ИНФОРМАЦИИ О СЧЕТЕ ИЗ БИЛЛИНГОВОЙ СИСТЕМЫ ПОСТАВЩИКА УСЛУГ
                 */
                $this->step = 1;
                return 3;
            case 'submitPayment':
                /**
                 * ЗАПРОС НАЧАЛА ОПЛАТЫ
                 * {"type":"submitPayment","serviceId":10000001,"account":"1","claimId":"0","amount":7500.0,
                 * "curAmount":974,"exRate":1.0,"amountBYR":7500,"raCode":739,"transactionId":434948746}
                 */
                $this->step = 2;
                return (int)(round($paymentTicket->getSum(), 2) == $request->get('amountBYR')) * 2;
            case 'confirmPayment':
                /*
                 * ЗАПРОС ЗАВЕРШЕНИЯ ОПЛАТЫ
                 */
                $this->step = 3;
                if (
                    hexdec(bin2hex($this->ticket->getId())) != $request->get('unipayTrxId')
                    || 'true' != $request->get('confirmed')
                ) {
                    return false;
                }
                return true;
            default:
                return false;
        }

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
        return 'amount';
    }

    /**
     * @return string
     */
    public function getPaymentIdParamName()
    {
        return 'transactionId';
    }

    /**
     * @inheritdoc
     */
    public function getPaymentNotificationWay()
    {
        return PaymentNotificationWayEnum::create(PaymentNotificationWayEnum::GATEWAY_TO_SHOP);
    }

}