<?php

namespace Shopen\AppBundle\Components\Payment\Payments;

use GoPay\Http\Response as GoPayResponse;
use GoPay\Definition\Payment\BankSwiftCode;
use GoPay\Definition\Payment\PaymentInstrument;
use GoPay\Payments;
use Shopen\AppBundle\Components\L10n\L10n;
use Shopen\AppBundle\Components\Payment\Enum\PaymentNotificationWayEnum;
use Shopen\AppBundle\Components\Payment\Exception\WrongPaymentDataException;
use Shopen\AppBundle\Components\Payment\Exception\UnsuccessfulPaymentException;
use Shopen\AppBundle\Components\Payment\PaidItem;
use Shopen\AppBundle\Components\Payment\PaymentDataBag;
use Shopen\AppBundle\Components\Payment\PaymentMethod;
use Shopen\AppBundle\Components\Payment\PaymentResponse;
use Shopen\AppBundle\Components\Payment\PaymentSystemInterface;
use Shopen\AppBundle\Components\Payment\RedirectDataBag;
use Shopen\AppBundle\Entity\Price;
use Shopen\AppBundle\Repository\CurrencyRepository;
use Shopen\AppBundle\Security\User\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class GoPay implements PaymentSystemInterface
{
    /**
     * @var string
     */
    private $goid;

    /**
     * @var string
     */
    private $clientId;

    /**
     * @var string
     */
    private $clientSecret;

    /**
     * @var Payments
     */
    private $gopayClient;

    /**
     * @var L10n
     */
    private $l10n;

    /**
     * @var CurrencyRepository
     */
    private $currencyRepository;

    /**
     * @var array
     */
    private $languageMap = [
        'ru' => 'RU',
        'en' => 'EN',
        'cz' => 'CS',
        'de' => 'DE',
        'sk' => 'SK',
        'ro' => 'RO',
    ];

    public function __construct(L10n $l10n, $goid, $clientId, $clientSecret, CurrencyRepository $currencyRepository)
    {
        $this->l10n = $l10n;
        $this->goid = $goid;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->currencyRepository = $currencyRepository;
    }

    /**
     * @param Request $request
     * @param $countryCode
     * @return PaymentDataBag
     */
    public function getPaymentData(Request $request, $countryCode)
    {
        $this->init('EN');

        $gopayResponse = $this->gopayClient->getStatus($request->get('id'));

        $currency = $this->currencyRepository->load($gopayResponse->json['currency']);
        $price = new Price();
        $price
            ->setCurrency($currency)
            ->setValue(round($gopayResponse->json['amount'] / 100, 2));

        $data = new PaymentDataBag();
        $data
            ->setOrderId($gopayResponse->json['order_number'])
            ->setContract($gopayResponse->json['additional_params'][0]['value'])
            ->setPaymentId($gopayResponse->json['id'])
            ->setLanguageCode($request->getLocale())
            ->setPrice($price)
            ->setStatus($gopayResponse->json['state']);

        return $data;
    }

    /**
     * @param Request $request
     * @return bool
     */
    public function validateRequest(Request $request)
    {
        $this->init('EN');

        $paymentId = $request->get('id');
        $gopayResponse = $this->gopayClient->getStatus($paymentId);

        return $gopayResponse->hasSucceed() && $gopayResponse->json['state'] == 'PAID';
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
        $this->init($languageCode);

        $paymentMethodMap = [
            PaymentMethod::BANK_CARD => PaymentInstrument::PAYMENT_CARD,
            PaymentMethod::PAYPAL => PaymentInstrument::PAYPAL,
            PaymentMethod::BANK_TRANSFER => PaymentInstrument::BANK_ACCOUNT,
        ];

        if (isset($paymentMethodMap[$paymentTypeAlias])) {
            $paymentMethod = $paymentMethodMap[$paymentTypeAlias];
        } else {
            $paymentMethod = PaymentInstrument::PAYMENT_CARD;
        }

        $itemsArray = [];

        foreach ($items as $item) {
            $itemsArray = array_merge(
                $itemsArray,
                array_fill(
                    0,
                    $item->getCount(),
                    [
                        'name' => $item->getName(),
                        'amount' => round($item->getPrice()->getValue() * 100)
                    ]
                )
            );
        }

        $params = [
            'payer' => [
                'default_payment_instrument' => $paymentMethod,
                'allowed_payment_instruments' => [$paymentMethod],
                'default_swift' => BankSwiftCode::CESKA_SPORITELNA,
                'contact' => [
                    'first_name' => $user->getFullName(),
                    'last_name' => '',
                    'email' => $user->getEmail(),
                    'phone_number' => '',
                    'city' => '',
                    'street' => '',
                    'postal_code' => '',
                    'country_code' => '',
                ]
            ],
            'eet' => [
                'celk_trzba' => round($price->getValue() * 100),
                'zakl_dan1' => round($tax->getValue() * 100),
                'dan1' => round(($price->getValue() - $tax->getValue()) * 100),
                'mena' => $price->getCurrency()->getExternalIso(),
            ],
            'amount' => round($price->getValue() * 100),
            'currency' => $price->getCurrency()->getExternalIso(),
            'order_number' => $orderId,
            'order_description' => 'The E-shop order',
            'items' => $itemsArray,
            'additional_params' => [
                [
                    'name' => 'contract',
                    'value' => $user->getContract()
                ]
            ],
            'callback' => [
                'return_url' => $successUrl,
                'notification_url' => $handleNotificationUrl,
            ],
            'lang' => $this->getExternalLanguageCode($languageCode),
        ];

        $response = $this->gopayClient->createPayment($params);

        if (!$response->hasSucceed()) {
            throw new WrongPaymentDataException();
        }

        $url = $response->json['gw_url'];

        $redirectData = new RedirectDataBag();
        $redirectData->setUrl($url);

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
        return 'gopay';
    }

    /**
     * @return array
     */
    public function getCountryCodes()
    {
        return [
            'at',
            'be',
            'cz',
            'uk',
            'hu',
            'gr',
            'dk',
            'ie',
            'es',
            'it',
            'cy',
            'lv',
            'lt',
            'lu',
            'mt',
            'nl',
            'no',
            'pt',
            'ro',
            'sk',
            'si',
            'fi',
            'fr',
            'hr',
            'se',
            'ee',
            'pl',
        ];
    }

    /**
     * @return string
     */
    public function getSuccessStatusCode()
    {
        return 'PAID';
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
        /**
         * GoPay не имеет отдельной настройки переадресации для неудавшихся платежей,
         * поэтому всё обрабатывается здесь.
         */
        $this->init('EN');
        $gopayResponse = $this->gopayClient->getStatus($requestArray['id']);
        if (
            !($gopayResponse instanceof GoPayResponse)
            || empty($gopayResponse->json['state'])
            || 'PAID' != $gopayResponse->json['state']
        ) {
            $state = !empty($gopayResponse->json['state']) ? $gopayResponse->json['state'] : 'Empty response at';
            throw new UnsuccessfulPaymentException(
                $state . ' GoPay #' . $requestArray['id'] . '(order ' . $requestArray['order_id'] . ')'
            );
        }

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
        return [PaymentMethod::BANK_CARD, PaymentMethod::PAYPAL, PaymentMethod::BANK_TRANSFER];
    }

    /**
     * @return bool
     */
    public function canPrintBill()
    {
        return true;
    }

    /**
     * Initiate connection to GoPay server
     * @param string $languageCode
     */
    private function init($languageCode)
    {
        if (!is_null($this->gopayClient)) {
            return;
        }

        $language = $this->getExternalLanguageCode($languageCode);

        $this->gopayClient = \GoPay\payments(
            [
                'goid' => $this->goid,
                'clientId' => $this->clientId,
                'clientSecret' => $this->clientSecret,
                'isProductionMode' => true,
                'language' => $language,
                'timeout' => 30,
            ]
        );
    }

    /**
     * @inheritdoc
     */
    public function getPaymentNotificationWay()
    {
        return PaymentNotificationWayEnum::create(PaymentNotificationWayEnum::GATEWAY_TO_SHOP);
    }

    /**
     * @param $languageCode
     * @return string
     */
    private function getExternalLanguageCode($languageCode)
    {
        if (isset($this->languageMap[$languageCode])) {
            $language = $this->languageMap[$languageCode];
        } else {
            $language = 'EN';
        }

        return $language;
    }

}
