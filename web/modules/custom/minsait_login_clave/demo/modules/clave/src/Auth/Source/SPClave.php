<?php
namespace SimpleSAML\Module\clave\Auth\Source;

use Exception;
use SAML2\Binding;
use SAML2\DOMDocumentFactory;
use SAML2\LogoutRequest;
use SAML2\XML\saml\Issuer;
use SimpleSAML\Assert\Assert;
use SimpleSAML\Configuration;
use SimpleSAML\Module;
use SimpleSAML\Module\saml\Auth\Source\SP;

class SPClave extends SP
{

    /**
     * Al hacer logout, Pasarela Clave devuelve 'Clave' como entityID y la clase SP solamente recoge el primero
     * que encuentra en metadata, así que si lleva 'Clave' se sobreescribe con el IdP de Pasarela.
     *
     * {@inheritdoc}
     */
    public function getIdPMetadata(string $entityId): Configuration
    {
        $entityId = $entityId === 'Cl@ve' ? 'https://se-pasarela.clave.gob.es' : $entityId;

        return parent::getIDPMetadata($entityId);
    }

    /**
     * SimpleSAML asigna en $state['\SimpleSAML\Auth\Source.ReturnURL'] el valor que le va a dar a RelayState.
     * Ese valor originalmente contiene una URL de postredirect y el ID del RelayState como tal en el parámetro RedirId.
     * El problema es que el IdP de eIDAS no permite que RelayState se mayor a 80 bytes y esta función se encarga de que
     * solamente se mande el parámetro RedirId que luego SimpleSAML interpreta en al respuesta y redirige al usuario a
     * donde se le ha indicado.
     *
     * {@inheritdoc}
     */
    public function startSSO(string $idp, array $state): void
    {
        if (isset($state['\SimpleSAML\Auth\Source.ReturnURL'])) {
            $parsedUrl = parse_url($state['\SimpleSAML\Auth\Source.ReturnURL']);
            parse_str($parsedUrl['query'], $queryParams);
            $state['\SimpleSAML\Auth\Source.ReturnURL'] = $queryParams['RedirId'] ?? null;
        }

        parent::startSSO($idp, $state);
    }

    /**
     * Se reasigna el issuer a la url de logout que maneja SimpleSAML.
     * En este caso se devuelve a una url propia que extiende
     * de la lógica base de SimpleSAML para poder modificar los parámetros custom que manda Clave.
     *
     * {@inheritdoc}
     * @throws Exception
     */
    public function sendSAML2LogoutRequest(Binding $binding, LogoutRequest $lr): void
    {
        $dom = DOMDocumentFactory::create();
        $isuerXml = $dom->createElement('saml:Issuer', Module::getModuleURL('clave/sp/clave-logout/' . $this->getAuthId()));
        $issuer = new Issuer($isuerXml);
        $lr->setIssuer($issuer);

        (new Module\clave\SAML2\HTTPPostClave())->send($lr);

        Assert::true(false);
    }
}
