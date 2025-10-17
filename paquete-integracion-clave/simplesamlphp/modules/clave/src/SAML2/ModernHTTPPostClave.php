<?php
namespace SimpleSAML\Module\clave\SAML2;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use SimpleSAML\SAML2\Binding\HTTPPost;
use SimpleSAML\SAML2\XML\samlp\AbstractMessage;
use SimpleSAML\SAML2\XML\samlp\AbstractRequest;
use SimpleSAML\SAML2\XML\samlp\LogoutRequest;
use SimpleSAML\SAML2\Utils;

use function base64_encode;

class ModernHTTPPostClave extends HTTPPost
{
    public function send(AbstractMessage $message): ResponseInterface
    {
        $destination = $this->getDestination() ?? $message->getDestination();
        if ($destination === null) {
            throw new \Exception('Cannot send message, no destination set.');
        }

        $relayState = $this->getRelayState();

        $xml = $message->toXML();
        Utils::getContainer()->debugMessage($xml, 'out');

        $encoded = base64_encode($xml->ownerDocument?->saveXML($xml) ?? $xml->C14N());

        if ($message instanceof LogoutRequest) {
            $msgType = 'logoutRequest';
        } elseif ($message instanceof AbstractRequest) {
            $msgType = 'SAMLRequest';
        } else {
            $msgType = 'SAMLResponse';
        }

        $post = [$msgType => $encoded];
        if ($relayState !== null) {
            $post['RelayState'] = $relayState;
        }

        $container = Utils::getContainer();

        return new Response(303, ['Location' => $container->getPOSTRedirectURL($destination, $post)]);
    }
}
