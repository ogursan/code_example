<?php

namespace Shopen\AppBundle\Components\Payment\Payments\PayPal;


use Shopen\AppBundle\Components\CountryConfigurator\CountryConfigurator;
use Shopen\AppBundle\Helpers\LocaleConverter\LocaleEnum;
use Shopen\AppBundle\Helpers\LocaleConverter\PayPalLocaleConverterHelper;

class PayPalCountryConfigurator extends CountryConfigurator implements PayPalCountryConfiguratorInterface
{

    const ENVIRONMENT_DEV= 'dev';

    /**
     * @var PayPalLocaleConverterHelper Конвертер локалей сайта в локали PayPal
     */
    private $payPalLocaleConverterHelper;
    /**
     * @var string Среда исполнения
     */
    private $environment;

    /**
     * @var string
     */
    private $id;

    /**
     * @var string
     */
    private $secret;

    /**
     * @var string
     */
    private $webhook;

    /**
     * @param PayPalLocaleConverterHelper $payPalLocaleConverterHelper
     */
    public function setPayPalLocaleConverterHelper(PayPalLocaleConverterHelper $payPalLocaleConverterHelper)
    {
        $this->payPalLocaleConverterHelper = $payPalLocaleConverterHelper;
    }

    /**
     * @param string $environment
     */
    public function setEnvironment($environment)
    {
        $this->environment = $environment;
    }

    /**
     * @param $payPalId
     */
    public function setId($payPalId)
    {
        $this->id = $payPalId;
    }

    /**
     * @param $payPalSecret
     */
    public function setSecret($payPalSecret)
    {
        $this->secret = $payPalSecret;
    }

    /**
     * @param $payPalWebhook
     */
    public function setWebhook($payPalWebhook)
    {
        $this->webhook = $payPalWebhook;
    }

    /**
     * @inheritdoc
     */
    public function getPayPalId()
    {
        return $this->id;
    }

    /**
     * @inheritdoc
     */
    public function getPayPalSecret()
    {
        return $this->secret;
    }

    /**
     * @inheritdoc
     */
    public function getPayPalWebhookId()
    {
        return $this->webhook;
    }

    /**
     * @inheritdoc
     */
    public function getPayPalLocale()
    {
        try {
            $currentLocale = LocaleEnum::create($this->languageCode);
            $paypalLocale = $this->payPalLocaleConverterHelper->convert($currentLocale);
        } catch (\Exception $exception){
            $paypalLocale = PayPalLocaleEnum::create(PayPalLocaleEnum::DEFLT);
        }
        return $paypalLocale;
    }

    /**
     * @inheritdoc
     */
    public function getPayPalMode()
    {
        return PayPalModeEnum::create(PayPalModeEnum::LIVE);
    }

}