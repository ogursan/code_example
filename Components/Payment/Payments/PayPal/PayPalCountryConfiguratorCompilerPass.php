<?php

namespace Shopen\AppBundle\Components\Payment\Payments\PayPal;

use Shopen\AppBundle\Components\L10n\L10n;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class PayPalCountryConfiguratorCompilerPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        $payPalCountryConfiguratorServiceAlias= 'shopen_app.components.pay_pal_country_configurator';
        $l10nServiceAlias= 'shopen_app.l10n';

        if (!$container->has($payPalCountryConfiguratorServiceAlias)) {
            throw new \RuntimeException($payPalCountryConfiguratorServiceAlias .' service definition is not defined');
        }

        if (!$container->has($l10nServiceAlias)) {
            throw new \RuntimeException($l10nServiceAlias . ' service definition is not defined');
        }

        /**
         * @var PayPal $payPalServiceDefinition
         */
        $payPalCountryConfiguratorServiceDefinition = $container->findDefinition($payPalCountryConfiguratorServiceAlias);
        /**
         * @var  L10n $l10nService
         */
        try {
            $l10nService = $container->get($l10nServiceAlias);
        } catch (\Exception $exception){
            //Только что выше проверили, что сервис определен, успкоим IDE трайкетчем...
        }

        $payPalId = $container->getParameter(\sprintf(PayPalCountryConfiguratorInterface::PAYPAL_CONFIG_KEY_ID,$l10nService->getCountryCode()));
        $payPalSecret = $container->getParameter(\sprintf(PayPalCountryConfiguratorInterface::PAYPAL_CONFIG_KEY_SECRET,$l10nService->getCountryCode()));
        $payPalWebhook = $container->getParameter(\sprintf(PayPalCountryConfiguratorInterface::PAYPAL_CONFIG_KEY_WEBHOOK_ID,$l10nService->getCountryCode(),$l10nService->getLocale()));

        $payPalCountryConfiguratorServiceDefinition->addMethodCall('setId',[$payPalId]);
        $payPalCountryConfiguratorServiceDefinition->addMethodCall('setSecret',[$payPalSecret]);
        $payPalCountryConfiguratorServiceDefinition->addMethodCall('setWebhook',[$payPalWebhook]);
    }

}