<?php
namespace SimpleSAML\Module\clave\SAML2;

use SAML2\Message as LegacyMessage;
use SimpleSAML\SAML2\XML\samlp\AbstractMessage as ModernMessage;
use SimpleSAML\SAML2\XML\samlp\MessageFactory;
use SimpleSAML\XML\DOMDocumentFactory;

final class LegacyMessageConverter
{
    /**
     * Convert a legacy SimpleSAMLphp message into a modern saml2 message.
     */
    public static function toModern(LegacyMessage $message): ModernMessage
    {
        $element = $message->toSignedXML();
        $xml = $element->ownerDocument?->saveXML($element) ?? $element->C14N();
        $document = DOMDocumentFactory::fromString($xml);

        return MessageFactory::fromXML($document->documentElement);
    }

    /**
     * Convert a modern saml2 message into the legacy representation used by SimpleSAMLphp.
     */
    public static function toLegacy(ModernMessage $message): LegacyMessage
    {
        $element = $message->toXML();
        $xml = $element->ownerDocument?->saveXML($element) ?? $element->C14N();
        $document = DOMDocumentFactory::fromString($xml);

        return LegacyMessage::fromXML($document->documentElement);
    }
}
