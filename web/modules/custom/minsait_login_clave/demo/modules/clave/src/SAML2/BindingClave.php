<?php
namespace SimpleSAML\Module\clave\SAML2;

use SAML2\Binding;
use SAML2\HTTPArtifact;
use SAML2\HTTPPost;
use SAML2\SOAP;

abstract class BindingClave extends Binding
{

    /**
     * Este método se ha sobreescrito ya que Pasarela Clave al hacer logout devuelve un parámetro 'logoutResponse' en
     * el POST y SimpleSAML solo permite que se devuelvan 'SAMLRequest' o 'SAMLResponse'
     *
     * {@inheritdoc}
     */
    public static function getCurrentBinding(): Binding
    {
        switch ($_SERVER['REQUEST_METHOD']) {
            case 'POST':
                if (isset($_SERVER['CONTENT_TYPE'])) {
                    $contentType = $_SERVER['CONTENT_TYPE'];
                    $contentType = explode(';', $contentType);
                    $contentType = $contentType[0]; /* Remove charset. */
                } else {
                    $contentType = null;
                }

                // Aquí se permite que SimpleSAML recoja el parámetro 'logoutResponse' de Pasarela Cl@ve
                if (array_key_exists('SAMLRequest', $_POST) || array_key_exists('SAMLResponse', $_POST) || array_key_exists('logoutResponse', $_POST)) {
                    return new HTTPPostClave();
                } elseif (array_key_exists('SAMLart', $_POST)) {
                    return new HTTPArtifact();
                } elseif (/**
                 * The registration information for text/xml is in all respects the same
                 * as that given for application/xml (RFC 7303 - Section 9.1)
                 */
                ($contentType === 'text/xml' || $contentType === 'application/xml') || 
                // See paragraph 3.2.3 of Binding for SAML2 (OASIS)
                (isset($_SERVER['HTTP_SOAPACTION']) && $_SERVER['HTTP_SOAPACTION'] === 'https://www.oasis-open.org/committees/security')) {
                    return new SOAP();
                }
                break;
            default:
                break;
        }

        return parent::getCurrentBinding();
    }
}
