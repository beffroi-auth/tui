<?php

declare(strict_types=1);

namespace Beffroi\Tui\Profile;

use function is_string;
use function parse_url;
use function sprintf;
use function str_contains;

/**
 * The administration API of an instance, by its resource identifier (RFC 8707 §2): the audience the provider reserves
 * to the administrators of the tenant (decision 0038), which this program asks its tokens for, and which tells it
 * whether the person signed in may administer the instance at all. The deployment names it
 * (BEFFROI_TENANT_ADMINISTRATION_RESOURCE) and the provider announces it nowhere, so whoever registers this program is
 * told it. It is an absolute HTTPS URI without a fragment (RFC 8707 §2), and the address the API answers at, the path
 * of a resource of the API following it: it carries no query either, which §2 says it should not.
 */
final readonly class AdministrationApi
{
    /**
     * @throws ProfileException when the value is not an absolute HTTPS URI, or carries a query or a fragment
     */
    public static function identifier(string $value): string
    {
        $parts = parse_url($value);
        $host = $parts === false ? null : $parts['host'] ?? null;
        if (
            $parts === false
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($host)
            || $host === ''
            || str_contains($value, '#')
            || str_contains($value, '?')
        ) {
            throw new ProfileException(sprintf(
                '"%s" names no administration API: it is named by an absolute HTTPS URI without a query or a fragment (RFC 8707 section 2).',
                $value,
            ));
        }

        return $value;
    }
}
