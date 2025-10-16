<?php
namespace SimpleSAML\Module\clave\Controller;

use Exception;
use SAML2\Binding;
use SAML2\Constants;
use SAML2\Exception\Protocol\UnsupportedBindingException;
use SAML2\HTTPPost;
use SAML2\HTTPRedirect;
use SAML2\LogoutRequest;
use SAML2\LogoutResponse;
use SAML2\SOAP;
use SAML2\XML\saml\Issuer;
use SimpleSAML\Auth;
use SimpleSAML\Error;
use SimpleSAML\HTTP\RunnableResponse;
use SimpleSAML\Logger;
use SimpleSAML\Module;
use SimpleSAML\Utils;
use Symfony\Component\HttpFoundation\ {
    Request,
    Response
};
use SimpleSAML\Module\clave\SAML2\BindingClave;

class ClaveServiceProvider extends \SimpleSAML\Module\saml\Controller\ServiceProvider
{

    /**
     * Se ha reescrito este método para que el ServiceProvider de SimpleSAML utilice el binding personalizado que acepta
     * los parámetros de Clave.
     *
     * {@inheritdoc}
     * @throws Error\Exception
     */
    public function singleLogoutService(string $sourceId): RunnableResponse
    {
        /** @var \SimpleSAML\Module\saml\Auth\Source\SP $source */
        $source = Auth\Source::getById($sourceId);

        if ($source === null) {
            throw new Error\Exception('No authentication source with id \'' . $sourceId . '\' found.');
        } elseif (! ($source instanceof \SimpleSAML\Module\saml\Auth\Source\SP)) {
            throw new Error\Exception('Source type changed?');
        }

        try {
            // Aquí se crea el BindingClave que modifica el original para aceptar el parámetro 'logoutRequest'
            $binding = BindingClave::getCurrentBinding();
        } catch (UnsupportedBindingException $e) {
            throw new Error\Error(Error\ErrorCodes::SLOSERVICEPARAMS, $e, 400);
        }
        $message = $binding->receive();

        $issuer = $message->getIssuer();
        if ($issuer instanceof Issuer) {
            $idpEntityId = $issuer->getValue();
        } else {
            $idpEntityId = $issuer;
        }

        if ($idpEntityId === null) {
            // Without an issuer we have no way to respond to the message.
            throw new Error\BadRequest('Received message on logout endpoint without issuer.');
        }

        $spEntityId = $source->getEntityId();

        $idpMetadata = $source->getIdPMetadata($idpEntityId);
        $spMetadata = $source->getMetadata();

        Module\saml\Message::validateMessage($idpMetadata, $spMetadata, $message);

        $httpUtils = new Utils\HTTP();
        $destination = $message->getDestination();
        if ($destination !== null && $destination !== $httpUtils->getSelfURLNoQuery()) {
            throw new Error\Exception('Destination in logout message is wrong.');
        }

        if ($message instanceof LogoutResponse) {
            $relayState = $message->getRelayState();
            if ($relayState === null) {
                // Somehow, our RelayState has been lost.
                throw new Error\BadRequest('Missing RelayState in logout response.');
            }

            if (! $message->isSuccess()) {
                Logger::warning('Unsuccessful logout. Status was: ' . Module\saml\Message::getResponseError($message));
            }

            $state = $this->authState::loadState($relayState, 'saml:slosent');
            $state['saml:sp:LogoutStatus'] = $message->getStatus();
            return new RunnableResponse([
                Auth\Source::class,
                'completeLogout'
            ], [
                &$state
            ]);
        } elseif ($message instanceof LogoutRequest) {
            Logger::debug('module/saml2/sp/logout: Request from ' . $idpEntityId);
            Logger::stats('saml20-idp-SLO idpinit ' . $spEntityId . ' ' . $idpEntityId);

            if ($message->isNameIdEncrypted()) {
                try {
                    $keys = Module\saml\Message::getDecryptionKeys($idpMetadata, $spMetadata);
                } catch (Exception $e) {
                    throw new Error\Exception('Error decrypting NameID: ' . $e->getMessage());
                }

                $blacklist = Module\saml\Message::getBlacklistedAlgorithms($idpMetadata, $spMetadata);

                $lastException = null;
                foreach ($keys as $i => $key) {
                    try {
                        $message->decryptNameId($key, $blacklist);
                        Logger::debug('Decryption with key #' . $i . ' succeeded.');
                        $lastException = null;
                        break;
                    } catch (Exception $e) {
                        Logger::debug('Decryption with key #' . $i . ' failed with exception: ' . $e->getMessage());
                        $lastException = $e;
                    }
                }
                if ($lastException !== null) {
                    throw $lastException;
                }
            }

            $nameId = $message->getNameId();
            $sessionIndexes = $message->getSessionIndexes();

            /**
             *
             * @psalm-suppress PossiblyNullArgument  This will be fixed in saml2 5.0
             */
            $numLoggedOut = Module\saml\SP\LogoutStore::logoutSessions($sourceId, $nameId, $sessionIndexes);
            if ($numLoggedOut === false) {
                // This type of logout was unsupported. Use the old method
                $source->handleLogout($idpEntityId);
                $numLoggedOut = count($sessionIndexes);
            }

            // Create and send response
            $lr = Module\saml\Message::buildLogoutResponse($spMetadata, $idpMetadata);
            $lr->setRelayState($message->getRelayState());
            $lr->setInResponseTo($message->getId());

            // If we set a key, we're sending a signed message
            $signedMessage = $lr->getSignatureKey() ? true : false;

            if ($numLoggedOut < count($sessionIndexes)) {
                Logger::warning('Logged out of ' . $numLoggedOut . ' of ' . count($sessionIndexes) . ' sessions.');
            }

            $dst = $idpMetadata->getEndpointPrioritizedByBinding('SingleLogoutService', [
                Constants::BINDING_HTTP_REDIRECT,
                Constants::BINDING_HTTP_POST
            ]);

            if (! ($binding instanceof SOAP)) {
                $binding = Binding::getBinding($dst['Binding']);
                if (isset($dst['ResponseLocation'])) {
                    $dst = $dst['ResponseLocation'];
                } else {
                    $dst = $dst['Location'];
                }
                $binding->setDestination($dst);

                if ($signedMessage && ($binding instanceof HTTPRedirect || $binding instanceof HTTPPost)) {
                    /**
                     * Bindings 3.4.5.2 - Security Considerations (HTTP-Redirect)
                     * Bindings 3.5.5.2 - Security Considerations (HTTP-POST)
                     *
                     * If the message is signed, the Destination XML attribute in the root SAML element of the protocol
                     * message MUST contain the URL to which the sender has instructed the user agent to deliver the
                     * message. The recipient MUST then verify that the value matches the location at which the message
                     * has been received.
                     */
                    $lr->setDestination($dst);
                }
            } else {
                $lr->setDestination($dst['Location']);
            }

            return new RunnableResponse([
                $binding,
                'send'
            ], [
                $lr
            ]);
        } else {
            throw new Error\BadRequest('Unknown message received on logout endpoint: ' . get_class($message));
        }
    }
}
