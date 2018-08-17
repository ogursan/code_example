<?php

namespace Shopen\AppBundle\Components\Payment;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Reference;


class PaymentNotificationCompilerPass implements CompilerPassInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(ContainerBuilder $container)
    {
        $paymentManagerAlias = 'shopen_app.payment.payment_manager';

        if (!$container->has($paymentManagerAlias)) {
            throw new RuntimeException('Payment manager is not defined');
        }

        $managerDefinition = $container->findDefinition($paymentManagerAlias);

        $partnerTaggedServices = $container->findTaggedServiceIds('shopen.payment');

        foreach ($partnerTaggedServices as $id => $tags) {
            $managerDefinition->addMethodCall('addPaymentSystem', [new Reference($id)]);
        }
    }
}
