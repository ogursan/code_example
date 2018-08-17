<?php

namespace Shopen\AppBundle\Components\Payment\Payments\PayPal;


interface PayPalCountryConfiguratorInterface
{

    const PAYPAL_CONFIG_KEY_ID = 'paypal_id_%s';
    const PAYPAL_CONFIG_KEY_SECRET = 'paypal_secret_%s';
    const PAYPAL_CONFIG_KEY_WEBHOOK_ID = 'paypal_webhook_id_%s_%s';

    /**
     * Получение секретного ключа аккаунта PayPal
     * @return string|null
     */
    public function getPayPalId();

    /**
     * Получение секретного ключа аккаунта PayPal
     * @return string|null
     */
    public function getPayPalSecret();

    /**
     * Получение идентификатора веб-хука PayPal
     * @return string|null
     */
    public function getPayPalWebhookId();

    /**
     * Получение локали PayPal
     * @return PayPalLocaleEnum|null
     */
    public function getPayPalLocale();

    /**
     * Получение режима работы PayPal (песочница или продакшн)
     * @return PayPalModeEnum|null
     */
    public function getPayPalMode();
}