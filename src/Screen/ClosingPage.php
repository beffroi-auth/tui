<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen;

use Beffroi\Tui\Text\Catalogue;
use const ENT_HTML5;
use const ENT_QUOTES;
use function htmlspecialchars;
use function sprintf;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The page the browser is answered on the loopback interface once it brought the authorization response back:
 * it says to go back to the terminal, which is where the outcome is shown, and nothing of the response.
 */
final readonly class ClosingPage
{
    public static function html(TranslatorInterface $translator, string $locale): string
    {
        return sprintf(
            "<!doctype html>\n<html lang=\"%s\"><meta charset=\"utf-8\"><title>%s</title><p>%s</p></html>\n",
            htmlspecialchars($locale, ENT_QUOTES | ENT_HTML5),
            htmlspecialchars($translator->trans('page.title', domain: Catalogue::DOMAIN), ENT_QUOTES | ENT_HTML5),
            htmlspecialchars($translator->trans('page.back_to_the_terminal', domain: Catalogue::DOMAIN), ENT_QUOTES | ENT_HTML5),
        );
    }
}
