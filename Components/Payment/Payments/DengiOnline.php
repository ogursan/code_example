<?php

namespace Shopen\AppBundle\Components\Payment\Payments;

use Shopen\AppBundle\Components\Payment\Enum\PaymentNotificationWayEnum;
use Shopen\AppBundle\Components\Payment\Exception\WrongPaymentDataException;
use Shopen\AppBundle\Components\Payment\PaidItem;
use Shopen\AppBundle\Components\Payment\PaymentDataBag;
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
 * Class DengiOnline
 * @package Shopen\AppBundle\Components\Payment
 */
class DengiOnline implements PaymentSystemInterface
{

    const SECRET = '';
    const DENGIONLINE_SUCCESS_STATUS = 'success';
    const DENGIONLINE_FAIL_STATUS = 'fail';
    const DENGIONLINE_IN_PROGRESS_STATUS = 'in_progress';

    /**
     * @var CurrencyRepository
     */
    private $currencyRepository;

    /**
     * @var bool
     */
    private $billPrintingCapability = false;

    /**
     * @return bool
     */
    public function canPrintBill()
    {
        return $this->billPrintingCapability;
    }

    function __construct(
        CurrencyRepository $currencyRepository
    ) {
        $this->currencyRepository = $currencyRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function getSuccessPaymentData($requestArray)
    {
        if (empty($requestArray)) {
            return false;
        }

        $secretString = md5(
            'amount=' . $requestArray['amount'] .
            'amount_rub=' . $requestArray['amount_rub'] .
            'mode_type=' . $requestArray['mode_type'] .
            'nick_extra=' . $requestArray['nick_extra'] .
            'nickname=' . $requestArray['nickname'] .
            'order_id=' . $requestArray['order_id'] .
            'paymentid=' . $requestArray['paymentid'] .
            'project=' . $requestArray['project'] .
            self::SECRET
        );

        if ($secretString != $requestArray['DOL_SIGN']) {
            throw new WrongPaymentDataException('Dengionline: Wrong signature');
        }

        return [
            'order_id' => $requestArray['order_id'],
            'client_contract' => $requestArray['nickname'],
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
        $currency = $this->currencyRepository->load('RUB');
        $price->setCurrency($currency);
        $price = PriceHelper::setFormatValue($price, $request->request->get('amount'));

        $paymentData = new PaymentDataBag();

        $paymentData
            ->setOrderId($request->request->get('orderid'))
            ->setContract($request->request->get('userid'))
            ->setPaymentId($request->request->get('paymentid'))
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
        $expectedHash = md5(
            $request->request->get('amount')
            . $request->request->get('userid')
            . $request->request->get('paymentid')
            . self::SECRET
        );

        return $expectedHash === $request->request->get('key');
    }

    /**
     * @param PaymentResponse $paymentResponse
     * @return Response
     */
    public function buildResponse(PaymentResponse $paymentResponse)
    {
        $status = $paymentResponse->isSuccess() ? 'YES' : 'NO';

        return $this->getXmlResponse($status, $paymentResponse->getMessage());
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
        $redirectData = [
            'project' => '8348',
            'mode_type' => $paymentTypeAlias,
            'nickname' => $user->getContract(),
            'amount' => PriceHelper::getFormatValue($price, PriceHelper::VALUE_FORMAT_FLOAT),
            'order_id' => $orderId,
            'nick_extra' => 'dengionline',
            'paymentCurrency' => $price->getCurrency()->getInternalIso(),
            'return_url_success' => $successUrl,
            'return_url_fail' => $failUrl,
        ];

        $url = 'https://www.onlinedengi.ru/wmpaycheck.php?' . http_build_query($redirectData);

        $redirectData = new RedirectDataBag();
        $redirectData->setUrl($url);

        return $redirectData;
    }

    /**
     * @return string
     */
    public function getAlias()
    {
        return 'dengionline';
    }

    /**
     * @return array
     */
    public function getCountryCodes()
    {
        return ['ru'];
    }

    public function getSuccessStatusCode()
    {
        return 'success';
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
        $responseText .= '<comment>' . $message . '</comment>' . "\n";
        $responseText .= '</result>';

        $response = new Response($responseText);
        $response->headers->set('Content-Type', 'text/xml');

        return $response;
    }

    /**
     * Return array of supported payment types
     *
     * @return array
     */
    public function getSupportedPaymentTypes()
    {
        return ['card'];
    }

    /**
     * @inheritdoc
     */
    public function getPaymentNotificationWay()
    {
        return PaymentNotificationWayEnum::create(PaymentNotificationWayEnum::GATEWAY_TO_SHOP);
    }

}
