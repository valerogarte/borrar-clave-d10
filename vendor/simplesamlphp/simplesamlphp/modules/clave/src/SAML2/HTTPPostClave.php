<?php
namespace SimpleSAML\Module\clave\SAML2;

use SAML2\DOMDocumentFactory;
use SAML2\HTTPPost;
use SAML2\LogoutRequest;
use SAML2\Message;
use SAML2\Request;
use SAML2\Utils;
use Webmozart\Assert\Assert;

class HTTPPostClave extends HTTPPost
{

    /**
     * Este método se ha sobreescrito ya que Clave espera el parámetro de request de manera diferente a lo que espera SimpleSAML
     *
     * {@inheritdoc}
     */
    public function send(Message $message): void
    {
        if ($this->destination === null) {
            $destination = $message->getDestination();
            if ($destination === null) {
                throw new \Exception('Cannot send message, no destination set.');
            }
        } else {
            $destination = $this->destination;
        }
        $relayState = $message->getRelayState();

        $msgStr = $message->toSignedXML();

        Utils::getContainer()->debugMessage($msgStr, 'out');
        $msgStr = $msgStr->ownerDocument->saveXML($msgStr);

        $msgStr = base64_encode($msgStr);

        // Si el SAML que se envía es de tipo LogoutRequest hay que mandarlo a Clave como parámetro 'logoutRequest'
        if ($message instanceof LogoutRequest) {
            $msgType = 'logoutRequest';
        } else if ($message instanceof Request) {
            $msgType = 'SAMLRequest';
        } else {
            $msgType = 'SAMLResponse';
        }

        $post = [];
        $post[$msgType] = $msgStr;

        if ($relayState !== null) {
            $post['RelayState'] = $relayState;
        }

        Utils::getContainer()->postRedirect($destination, $post);
    }

    /**
     * Este método se ha sobreescrito ya que Clave manda el parámetro de respuesta de manera diferente a lo que espera SimpleSAML
     *
     * @inheritDoc
     */
    public function receive(): Message
    {
        // Se reasigna el parámetro 'logoutResponse' que se recibe de Clave al parámetro 'SAMLRequest' que espera SimpleSAML
        if (array_key_exists('SAMLRequest', $_POST)) {
            $msgStr = $_POST['SAMLRequest'];
        } else if (array_key_exists('logoutResponse', $_POST)) {
            $msgStr = $_POST['logoutResponse'];
            $_POST['SAMLRequest'] = $msgStr;
        } elseif (array_key_exists('SAMLResponse', $_POST)) {
            $msgStr = $_POST['SAMLResponse'];
        } else {
            throw new \Exception('Missing SAMLRequest or SAMLResponse parameter.');
        }

        $msgStr = base64_decode($msgStr, true);

        $xml = new \DOMDocument();
        $xml->loadXML($msgStr);
        $msgStr = $xml->saveXML();

        $document = DOMDocumentFactory::fromString($msgStr);
        Utils::getContainer()->debugMessage($document->documentElement, 'in');
        if (! $document->firstChild instanceof \DOMElement) {
            throw new \Exception('Malformed SAML message received.');
        }

        $msg = Message::fromXML($document->firstChild);

        /**
         * 3.5.5.2 - SAML Bindings
         *
         * If the message is signed, the Destination XML attribute in the root SAML element of the protocol
         * message MUST contain the URL to which the sender has instructed the user agent to deliver the
         * message.
         */
        if ($msg->isMessageConstructedWithSignature()) {
            Assert::notNull($msg->getDestination()); // Validation of the value must be done upstream
        }

        if (array_key_exists('RelayState', $_POST)) {
            $msg->setRelayState($_POST['RelayState']);
        }

        return $msg;
    }
}
