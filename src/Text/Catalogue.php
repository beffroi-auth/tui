<?php

declare(strict_types=1);

namespace Beffroi\Tui\Text;

use function sprintf;
use Symfony\Component\Translation\Loader\XliffFileLoader;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Translator;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every string the screen and the page of the loopback interface show, in English and in French
 * (`translations/`, XLIFF in the ICU message format, the domain `tui`), English when a string is missing.
 */
final readonly class Catalogue
{
    public const string DOMAIN = 'tui';

    public static function directory(): string
    {
        return __DIR__ . '/../../translations';
    }

    public static function translator(string $locale): TranslatorInterface
    {
        $translator = new Translator($locale);
        $translator->setFallbackLocales(['en']);
        $translator->addLoader('xlf', new XliffFileLoader());
        foreach (TerminalLocale::SUPPORTED as $supported) {
            $translator->addResource(
                'xlf',
                sprintf('%s/%s%s.%s.xlf', self::directory(), self::DOMAIN, MessageCatalogue::INTL_DOMAIN_SUFFIX, $supported),
                $supported,
                self::DOMAIN . MessageCatalogue::INTL_DOMAIN_SUFFIX,
            );
        }

        return $translator;
    }
}
