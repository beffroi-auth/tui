<?php

declare(strict_types=1);

namespace Beffroi\Tui\SignIn;

use Beffroi\Tui\Key\SigningKey;
use Beffroi\Tui\Profile\Profile;
use Beffroi\Tui\Protocol\AuthorizationRequest;
use Beffroi\Tui\Protocol\DpopProofs;
use Beffroi\Tui\Protocol\PrivateKeyJwt;
use Beffroi\Tui\Protocol\ProtocolException;
use Beffroi\Tui\Protocol\ProviderEndpoint;
use Beffroi\Tui\Protocol\PushedAuthorization;
use Beffroi\Tui\Provider\ProviderMetadataReader;
use Beffroi\Tui\Provider\UnsuitableProviderException;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * An administrator signs in through the provider of the instance (decision 0038): the authorization code flow
 * of a confidential client held to FAPI 2.0 Security Profile §5.3.3, from the browser of this machine back to
 * its loopback interface.
 *
 * Starting it reads the metadata, draws the key the tokens of this run will be bound to, binds the port the
 * client registered before anything is asked of the provider, pushes the request and opens the browser on the
 * URL it ends in. What follows is `PendingSignIn`'s.
 */
final readonly class SignIn
{
    /**
     * @param string $page the HTML the browser is answered once it brought the response back
     */
    public function __construct(
        private ProviderMetadataReader $metadata,
        private HttpClientInterface $http,
        private ClockInterface $clock,
        private BrowserInterface $browser,
        private string $page,
    ) {
    }

    /**
     * @throws UnsuitableProviderException when the provider does not announce what this sign-in is held to
     * @throws CannotListenException when the port the client registered cannot be listened on
     * @throws ProtocolException when the provider refuses the pushed request
     */
    public function start(Profile $profile, string $locale): PendingSignIn
    {
        $provider = $this->metadata->read($profile->issuer);
        $proofs = new DpopProofs(SigningKey::generate(), $this->clock);
        $endpoint = new ProviderEndpoint($this->http, new PrivateKeyJwt($profile->clientId, $profile->clientKey, $provider->issuer, $this->clock), $proofs);
        $listener = LoopbackListener::on($profile->redirectUri, $this->page);

        try {
            $request = AuthorizationRequest::draw($profile->clientId, $profile->redirectUri, $locale, $profile->administration);
            $authorizationUrl = new PushedAuthorization($endpoint, $provider)->push($request);
        } catch (Throwable $exception) {
            $listener->close();

            throw $exception;
        }
        $this->browser->open($authorizationUrl);

        return new PendingSignIn($authorizationUrl, $listener, $request, $profile, $provider, $endpoint, $proofs, $this->http, $this->clock);
    }
}
