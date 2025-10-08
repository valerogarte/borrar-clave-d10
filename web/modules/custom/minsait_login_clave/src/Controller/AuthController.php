<?php

namespace Drupal\minsait_login_clave\Controller;

use DOMException;
use Exception;
use SAML2;
use SimpleSAML;
use SimpleSAML\Auth\Simple;
use SimpleSAML\Configuration;
use SimpleSAML\Error\AuthSource;

/**
 * Clase encargada de la autenticación con SimpleSAML
 */
class AuthController
{

  /**
   *
   * @var Simple
   */
  private Simple $auth;

  /**
   *
   * @var string|mixed
   */
  private string $assertionUrl;

  /**
   *
   * @var Configuration
   */
  private Configuration $config;

  /**
   *
   * @throws Exception
   */
  public function __construct()
  {
    $config = Configuration::getConfig('config.php');

    $this->auth = $this->getAuth();
    $this->assertionUrl = $config->getValue('ASSERTION_URL');
    $this->config = $config;
  }

  /**
   * Redirige al usuario a Pasarela Clave para iniciar sesión o que le manda a su perfil si ya se encuentra loggeado.
   *
   * @return void
   * @throws DOMException
   */
  public function login($request): void
  {
    if ($this->isAuthenticated()) {
      header('Location: /profile');
      exit();
    }

    $extension = $this->generateAttributes($request);
    $eidasLoA = $this->getEidasLoA($request);
    $force = $this->getForceAuthn($request);

    // Si no se le requiere loggearse contra el IdP
    $this->auth->requireAuth([
      'saml:Extensions' => $extension,
      'saml:AuthnContextClassRef' => $eidasLoA,
      'saml:AuthnContextComparison' => 'minimum',
      'ForceAuthn' => $force,
      'ReturnTo' => $this->assertionUrl,
      'ErrorURL' => $this->assertionUrl
    ]);
  }

  /**
   * Ciera la sesión del usuario en el SP y en Pasarela Clave
   *
   * @return void
   * @throws AuthSource
   * @throws Exception
   */
  public function logout(): void
  {
    if ($this->isAuthenticated()) {
      $this->auth->logout([
        'saml:logout:NameID' => $this->getLogoutNameID($this->auth->getAuthSource()
          ->getAuthId()),
        'ReturnTo' => $this->assertionUrl,
        'ErrorURL' => $this->assertionUrl . '/error'
      ]);

      SimpleSAML\Session::getSessionFromRequest()->cleanup();
    }
  }

  /**
   * Comprueba que el usuario esté autenticado
   *
   * @return bool
   */
  public function isAuthenticated(): bool
  {
    return $this->auth->isAuthenticated();
  }

  /**
   * Devuelve el Auth del SP
   *
   * @throws Exception
   */
  private function getAuth(): Simple
  {
    $config = Configuration::getConfig('config.php');
    $spID = $config->getValue('DEFAULT_SPID');

    $session = SimpleSAML\Session::getSessionFromRequest();

    // Comprueba la sesión por si se ha seleccionado un SP distinto al de por defecto
    if ($session->getData('string', 'spid')) {
      $spID = $session->getData('string', 'spid');
    }

    return new Simple($spID);
  }

  /**
   * Obtiene y limpia los atributos del usuario logueado
   *
   * @return array
   */
  public function getUserAttributes(): array
  {
    $attributes = $this->auth->getAttributes();
    $cleanAttributes = [];

    $keys = array_keys($attributes);
    foreach ($keys as $key) {
      $value = array_search($key, $this->config->getArray('attrs'));
      $indexKey = explode('.', $value);
      $cleanAttributes[$indexKey[0]] = $attributes[$key][0];
    }

    return $cleanAttributes;
  }

  /**
   * Genera los atributos que manda al IdP, donde se especifica que formas de login se quiere excluir se proporcionan
   * los valores para el login con eIDAS
   *
   * @param
   *            $request
   * @return array
   * @throws DOMException
   */
  private function generateAttributes($request): array
  {
    $extensions = [];
    $attributes_array = [];
    $ns_eidas = $this->config->getString('NS_EIDAS');
    $ns_eidas_natural = $this->config->getString('NS_EIDAS_NATURAL');
    $include_relaystate = $this->config->getBoolean('INCLUDE_RELAYSTATE_ATTR');
    $idpAttrs = $this->config->getArray('attrs');

    $dom = SAML2\DOMDocumentFactory::create();
    $ce_type = $dom->createElementNS($ns_eidas, 'eidas:SPType', 'public');
    $ce_type->setAttribute('xmlns:eidas', $ns_eidas);
    $extensions['eidas:SPType'] = new SAML2\XML\Chunk($ce_type);

    if ($include_relaystate) {
      $relayState = ((new SimpleSAML\Utils\Random())->generateID());

      $ce0 = $dom->createElement('eidas:AttributeValue', $relayState);
      $ce0->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
      $ce0->setAttribute('xsi:type', 'eidas-natural:PersonIdentifierType');

      $ce1 = $dom->createElementNS($ns_eidas, 'eidas:RequestedAttribute');
      $ce1->setAttribute('FriendlyName', $idpAttrs['RelayState.name']);
      $ce1->setAttribute('Name', $idpAttrs['RelayState.uri']);
      $ce1->setAttribute('NameFormat', $idpAttrs['RelayState.nameFormat']);
      $ce1->setAttribute('isRequired', 'false');

      $ce1->appendChild($ce0);
      $attributes_array[] = $ce1;
    }

    if (sizeof($attributes_array)) {
      $ce_requested_attributes = $dom->createElementNS($ns_eidas, 'eidas:RequestedAttributes');
      $ce_requested_attributes->setAttribute('xmlns:eidas', $ns_eidas);

      $ce_requested_attributes->setAttributeNS($ns_eidas, 'eidas:tmp', 'tmp');
      $ce_requested_attributes->removeAttributeNS($ns_eidas, 'tmp');

      $ce_requested_attributes->setAttributeNS($ns_eidas_natural, 'eidas-natural:tmp', 'tmp');
      $ce_requested_attributes->removeAttributeNS($ns_eidas_natural, 'tmp');

      foreach ($attributes_array as $attribute) {
        $ce_requested_attributes->appendChild($attribute);
      }

      $extensions['RequestedAttributes'] = new SAML2\XML\Chunk($ce_requested_attributes);
    }

    return $extensions;
  }

  /**
   * Obtiene el parámetro eIDAS LOA del formulario
   *
   * @return mixed|string
   */
  private function getEidasLoA($request)
  {
    return 'http://eidas.europa.eu/LoA/low'; // NOSONAR excluir norma de https
  }

  /**
   * Obtiene el parámetro forceauthn del formulario
   * 
   * @return bool
   */
  private function getForceAuthn($request): bool
  {
    return true;
  }

  /**
   * Obtiene los campos requeridos por Pasarela Clave para realizar el logout
   *
   * @param
   *            $authSource
   * @return SAML2\XML\saml\NameID
   */
  private function getLogoutNameID($authSource): SAML2\XML\saml\NameID
  {
    $nameID = new SAML2\XML\saml\NameID();
    $nameID->setValue($authSource);
    $nameID->setSPNameQualifier($this->assertionUrl);
    $nameID->setFormat(SAML2\Constants::NAMEID_UNSPECIFIED);
    return $nameID;
  }
}
