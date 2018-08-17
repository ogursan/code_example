<?php

/**
 * Class contains aliases of payment methods
 */
namespace Shopen\AppBundle\Components\Payment;


use Shopen\AppBundle\Components\L10n\Translator;

class PaymentMethod
{
    const BANK_CARD = 'card';

    const BANK_CARD_MD = 'card_md';

    const BANK_CARD_RU = 'card_ru';

    const BANK_CARD_CN = 'card_cn';

    const BANK_CARD_VN = 'card_vn';

    const YANDEX_MONEY = 'yandex';

    const WEBMONEY = 'webmoney';

    const QIWI = 'qiwi';

    const ALFABANK = 'alfabank';

    const VTB24 = 'vtb24';

    const RSB = 'rsb';

    const PSB = 'psb';

    const SBERBANK_TERMINAL = 'sberbank_terminal';

    const PAYPAL = 'paypal';

    const HUTKIGROSH = 'hutkigrosh';

    const BANK_TRANSFER = 'bank_transfer';

    const SOFORT = 'sofort';

    /**
     * @var Translator
     */
    private $translator;

    public function __construct(Translator $translator)
    {
        $this->translator = $translator;
    }

    /**
     * Return method real name
     *
     * @param string $methodAlias
     * @return string
     */
    public function getTranslatedName($methodAlias)
    {
        $translationMap = [
            self::BANK_CARD => $this->translator->t('Банковская карта'),
            self::BANK_CARD_MD => $this->translator->t('Банковская карта'),
            self::BANK_CARD_RU => $this->translator->t('Банковская карта'),
            self::BANK_CARD_CN => $this->translator->t('Банковская карта'),
            self::BANK_CARD_VN => $this->translator->t('Банковская карта'),
            self::YANDEX_MONEY => $this->translator->t('Яндекс.Деньги'),
            self::WEBMONEY => $this->translator->t('WebMoney'),
            self::SBERBANK_TERMINAL => $this->translator->t('В отделении Сбербанка'),
            self::QIWI => $this->translator->t('QIWI кошелек'),
            self::ALFABANK => $this->translator->t('Альфа-Банк'),
            self::VTB24 => $this->translator->t('ВТБ24'),
            self::RSB => $this->translator->t('Банк Русский Стандарт'),
            self::PSB => $this->translator->t('ПромСвязьБанк'),
            self::PAYPAL => $this->translator->t('PayPal'),
            self::HUTKIGROSH => $this->translator->t('Расчет (ЕРИП)'),
            self::BANK_TRANSFER => $this->translator->t('Банковский перевод'),
            self::SOFORT => $this->translator->t('Банковский перевод'),
        ];

        if (isset($translationMap[$methodAlias])) {
            return $translationMap[$methodAlias];
        }

        return $methodAlias;
    }
}
