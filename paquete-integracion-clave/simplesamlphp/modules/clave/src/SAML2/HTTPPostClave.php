<?php
namespace SimpleSAML\Module\clave\SAML2;

use Nyholm\Psr7\ServerRequest;
use SAML2\Binding;
use SAML2\Message;
use SimpleSAML\Utils\HTTP as HttpUtils;

class HTTPPostClave extends Binding
{
    /**
     * Este método se ha sobreescrito ya que Clave espera el parámetro de request de manera diferente a lo que espera SimpleSAML
     *
     * {@inheritdoc}
     */
    public function send(Message $message): void
    {
        $modernMessage = LegacyMessageConverter::toModern($message);
        $destination = $this->destination ?? $modernMessage->getDestination();
        if ($destination === null) {
            throw new \Exception('Cannot send message, no destination set.');
        }

        $relayState = $message->getRelayState();

        $binding = new ModernHTTPPostClave();
        $binding->setDestination($destination);
        $binding->setRelayState($relayState);

        $response = $binding->send($modernMessage);

        $httpUtils = new HttpUtils();
        $httpUtils->redirectTrustedURL($response->getHeaderLine('Location'));
    }

    /**
     * Este método se ha sobreescrito ya que Clave manda el parámetro de respuesta de manera diferente a lo que espera SimpleSAML
     *
     * @inheritDoc
     */
    public function receive(): Message
    {
        $parsedBody = $_POST;
        if (isset($parsedBody['logoutResponse']) && !isset($parsedBody['SAMLRequest'])) {
            $parsedBody['SAMLRequest'] = $parsedBody['logoutResponse'];
        }

        $request = new ServerRequest(
            $_SERVER['REQUEST_METHOD'] ?? 'POST',
            $_SERVER['REQUEST_URI'] ?? '/',
            [],
            null,
            '1.1',
            $_SERVER,
        );
        $request = $request->withParsedBody($parsedBody);

        $binding = new \SimpleSAML\SAML2\Binding\HTTPPost();
        $modernMessage = $binding->receive($request);

        $legacy = LegacyMessageConverter::toLegacy($modernMessage);

        if ($binding->getRelayState() !== null) {
            $legacy->setRelayState($binding->getRelayState());
        }

        return $legacy;
    }

}
