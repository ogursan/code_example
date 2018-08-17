<?php

namespace Shopen\AppBundle\Components\Payment\Payments;

use Shopen\AppBundle\Components\Payment\Enum\PaymentNotificationWayEnum;
use Shopen\AppBundle\Components\Payment\Exception\WrongPaymentDataException;
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
use Symfony\Component\VarDumper\VarDumper;


class Paymaster implements PaymentSystemInterface, PaymentReportInterface
{
    /**
     * @var string
     */
    private $merchantId;

    /**
     * @var string
     */
    private $secret;

    /**
     * @var string
     */
    private $login;

    /**
     * @var string
     */
    private $password;

    /**
     * @var CurrencyRepository
     */
    private $currencyRepository;

    public function __construct(
        $merchantId,
        $secret,
        $login,
        $password,
        CurrencyRepository $currencyRepository
    ) {
        $this->merchantId = $merchantId;
        $this->secret = $secret;
        $this->login = $login;
        $this->password = $password;
        $this->currencyRepository = $currencyRepository;
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
     * @param Request $request
     * @param $countryCode
     * @return PaymentDataBag
     */
    public function getPaymentData(Request $request, $countryCode)
    {
        $currency = $this->currencyRepository->load('RUB');

        $price = new Price();
        $price->setCurrency($currency);

        $price = PriceHelper::setFormatValue($price, $request->get('LMI_PAID_AMOUNT'));

        $paymentData = new PaymentDataBag();

        $paymentData
            ->setOrderId($request->get('LMI_PAYMENT_NO'))
            ->setContract($request->get('contract'))
            ->setPaymentId($request->get('LMI_SYS_PAYMENT_ID'))
            ->setLanguageCode($request->getLocale())
            ->setPrice($price)
            ->setStatus('success')
            ->setPaymentMethod($request->get('LMI_PAYMENT_METHOD'));

        return $paymentData;
    }

    /**
     * @param Request $request
     * @return bool
     */
    public function validateRequest(Request $request)
    {
        $hashParts = [
            $request->get('LMI_MERCHANT_ID'),
            $request->get('LMI_PAYMENT_NO'),
            $request->get('LMI_SYS_PAYMENT_ID'),
            $request->get('LMI_SYS_PAYMENT_DATE'),
            $request->get('LMI_PAYMENT_AMOUNT'),
            $request->get('LMI_CURRENCY'),
            $request->get('LMI_PAID_AMOUNT'),
            $request->get('LMI_PAID_CURRENCY'),
            $request->get('LMI_PAYMENT_SYSTEM'),
            $request->get('LMI_SIM_MODE'),
            $this->secret,
        ];

        $expectedHash = base64_encode(md5(implode(';', $hashParts), true));

        return $expectedHash === $request->get('LMI_HASH');
    }

    /**
     * @param PaymentResponse $paymentResponse
     * @return Response
     */
    public function buildResponse(PaymentResponse $paymentResponse)
    {
        $statusCode = $paymentResponse->isSuccess() ? 200 : 500;

        return new Response('', $statusCode);
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
     * @param PaidItem[] $languageCode
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
        $paymentMethodMap = [
            PaymentMethod::BANK_CARD => 'BankCard',
            PaymentMethod::YANDEX_MONEY => 'Yandex',
            PaymentMethod::WEBMONEY => 'WebMoney',
            PaymentMethod::PSB => 'PSB',
            PaymentMethod::RSB => 'RSB',
            PaymentMethod::ALFABANK => 'AlfaBank',
            PaymentMethod::VTB24 => 'VTB24',
            PaymentMethod::QIWI => 'Qiwi',
        ];

        $params = [
            'LMI_MERCHANT_ID' => $this->merchantId,
            'LMI_PAYMENT_AMOUNT' => $price->getValue(),
            'LMI_CURRENCY' => 643,
            'LMI_PAYMENT_NO' => $orderId,
            'LMI_PAYMENT_DESC_BASE64' => base64_encode($description),
            'LMI_SUCCESS_URL' => $successUrl,
            'LMI_FAILURE_URL' => $failUrl,
            'LMI_PAYMENT_NOTIFICATION_URL' => $handleNotificationUrl,
            'contract' => $user->getContract(),
            'order_id' => $orderId,
        ];

        if (isset($paymentMethodMap[$paymentTypeAlias])) {
            $params['LMI_PAYMENT_METHOD'] = $paymentMethodMap[$paymentTypeAlias];
        }

        $i = 0;
        foreach ($items as $item) {
            $indexPrefix = 'LMI_SHOPPINGCART.ITEMS[' . $i . ']';
            $params[$indexPrefix . '.NAME'] = htmlspecialchars($item->getName());
            $params[$indexPrefix . '.QTY'] = $item->getCount();
            $params[$indexPrefix . '.PRICE'] = $item->getPrice()->getValue();
            $params[$indexPrefix . '.TAX'] = 'vat118';
            $i++;
        }

        $url = 'https://paymaster.ru/Payment/Init';

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
        return 'paymaster';
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
     * Return array of supported payment types
     *
     * @return array
     */
    public function getSupportedPaymentTypes()
    {
        return [
            PaymentMethod::QIWI,
            PaymentMethod::YANDEX_MONEY,
            PaymentMethod::WEBMONEY,
            PaymentMethod::ALFABANK,
            PaymentMethod::VTB24,
            PaymentMethod::RSB,
            PaymentMethod::PSB,
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
     * @param \DateTime $dateFrom
     * @param \DateTime $dateTo
     * @return PaymentTransaction[]
     */
    public function getTransactions(\DateTime $dateFrom, \DateTime $dateTo)
    {
        $dateTo->add(new \DateInterval('P1D')); // Костыль, ибо апи пэймастера работает неадекватно

        $nonce = $this->getNonce();

        $postFields = [
            'login' => $this->login,
            'password' => $this->password,
            'nonce' => $nonce,
            'accountID' => '',
            'siteAlias' => $this->merchantId,
            'periodFrom' => $dateFrom->format('Y-m-d'),
            'periodTo' => $dateTo->format('Y-m-d'),
            'invoiceID' => '',
            'state' => 'COMPLETE',
        ];

        $hash = $this->getHash(implode(';', $postFields));

        $postFields['hash'] = $hash;

        unset($postFields['password'], $postFields['accountID'], $postFields['invoiceID']);

        $ch = curl_init('https://paymaster.ru/partners/rest/listPaymentsFilter');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $result = json_decode(curl_exec($ch), true);

        if (empty($result)) {
            return [];
        }

        if (isset($result['Response']['Payments'])) {
            $transactions = [];

            foreach ($result['Response']['Payments'] as $payment) {
                $transaction = new PaymentTransaction();

                $transaction
                    ->setId($payment['PaymentID'])
                    ->setOrderId($payment['SiteInvoiceID'])
                    ->setSum($payment['PaymentAmount'])
                    ->setType($payment['PaymentSystemID'])
                    ->setDate(new \DateTime($payment['LastUpdateTime']));

                $transactions[] = $transaction;
            }

            return $transactions;
        }

        return [];
    }

    /**
     * Возвращает алиас для процедуры БД
     *
     * @return string
     */
    public function getDbAlias()
    {
        return 'PAYMASTER';
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
            ->setType($paymentDataBag->getPaymentMethod());

        return $transaction;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentNotificationWay()
    {
        return PaymentNotificationWayEnum::create(PaymentNotificationWayEnum::GATEWAY_TO_SHOP);
    }

    /**
     * Возвращает случайную строку для генерации запроса
     *
     * @return string
     */
    private function getNonce()
    {
        return base64_encode(openssl_random_pseudo_bytes(mt_rand(10, 180)));
    }

    /**
     * Возвращает хэш строки
     *
     * @param $string
     * @return string
     */
    private function getHash($string)
    {
        return base64_encode(sha1($string, true));
    }
}
