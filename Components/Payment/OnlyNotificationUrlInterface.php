<?php

namespace Shopen\AppBundle\Components\Payment;

/**
 * Интерфейс платёжной системы, умеющей использовать только один адрес для уведомлений сервера о статусе оплаты.
 *
 * Для такой системы нельзя задать разные адреса для уведомлений, поэтому уведомления об оплате регистрации
 * будут обработаны там же, где обрабатываются уведомления об оплате заказов.
 *
 * @package Shopen\AppBundle\Components\Payment
 */
interface OnlyNotificationUrlInterface
{

}
