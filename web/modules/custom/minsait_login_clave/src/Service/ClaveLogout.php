<?php

namespace Drupal\minsait_login_clave\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use SAML2\Constants;
use SAML2\XML\saml\NameID;
use SimpleSAML\Auth\Simple;
use SimpleSAML\Configuration;
use SimpleSAML\Session;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

class ClaveLogout {

  /**
   * El canal de log.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * Constructor.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory,
    RequestStack $request_stack
  ) {
    $this->logger = $logger_factory->get('minsait_login_clave');
    $this->configFactory = $config_factory;
    $this->requestStack = $request_stack;
  }

  /**
   * Ejecuta la lógica de logout para Cl@ve.
   */
  public function logout() {

    $config = $this->configFactory->get('minsait_login_clave.settings');

    if (!$config->get('enable_clave')) {
      $this->logger->error('Cl@ve no habilitada.');
      throw new ServiceUnavailableHttpException(NULL, 'Error en la configuración de SAML. Clave no habilitada.');
    }

    try {
      $sspConfig = Configuration::getConfig('config.php');
      $sspSession = $this->loadSimpleSamlSession();
      $spId = $this->determineSpId($sspConfig, $config, $sspSession);
      $auth = new Simple($spId);

      if (!$auth->isAuthenticated()) {
        return;
      }

      $assertionUrl = $this->getAssertionUrl($sspConfig);
      if ($assertionUrl === '') {
        throw new \RuntimeException('No se pudo determinar ASSERTION_URL para el logout de Cl@ve.');
      }

      $returnTo = $this->getReturnUrl();
      $logoutOptions = [
        'saml:logout:NameID' => $this->buildLogoutNameId($auth, $assertionUrl),
        'ReturnTo' => $returnTo,
        'ErrorURL' => $returnTo,
      ];

      $auth->logout($logoutOptions);

      if ($sspSession instanceof Session) {
        $sspSession->cleanup();
      }
      else {
        Session::getSessionFromRequest()->cleanup();
      }
    }
    catch (\Throwable $e) {
      $this->logger->error('Error al ejecutar el logout en Cl@ve: @msg', ['@msg' => $e->getMessage()]);
    }
  }

  private function getReturnUrl() {
    $request = $this->requestStack->getCurrentRequest();
    if ($request) {
      return $request->getSchemeAndHttpHost();
    }

    global $base_url;
    if (!empty($base_url)) {
      return $base_url;
    }

    return '/';
  }

  /**
   * Carga la sesión de SimpleSAMLphp si existe.
   */
  protected function loadSimpleSamlSession(): ?Session {
    try {
      return Session::getSessionFromRequest();
    }
    catch (\Throwable $exception) {
      $this->logger->warning('No se pudo obtener la sesión de SimpleSAMLphp: @msg', [
        '@msg' => $exception->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Determina el SPID a utilizar durante el logout.
   */
  protected function determineSpId(Configuration $sspConfig, $config, ?Session $session): string {
    $configuredSpId = $config->get('sp_id');
    if (is_array($configuredSpId)) {
      $configuredSpId = reset($configuredSpId);
    }
    $configuredSpId = trim((string) $configuredSpId);

    $defaultSpId = '';
    try {
      $defaultSpId = trim((string) $sspConfig->getString('DEFAULT_SPID'));
    }
    catch (\Throwable $exception) {
      $this->logger->warning('No se pudo recuperar DEFAULT_SPID de SimpleSAMLphp: @msg', [
        '@msg' => $exception->getMessage(),
      ]);
    }

    $spId = $configuredSpId !== '' ? $configuredSpId : $defaultSpId;

    if ($session instanceof Session) {
      $storedSpId = $session->getData('string', 'spid');
      if (is_string($storedSpId) && $storedSpId !== '') {
        $spId = $storedSpId;
      }
    }

    if ($spId === '') {
      throw new \RuntimeException('No se pudo determinar el SPID para Cl@ve.');
    }

    return $spId;
  }

  /**
   * Recupera el valor de ASSERTION_URL desde la configuración de SimpleSAMLphp.
   */
  protected function getAssertionUrl(Configuration $sspConfig): string {
    try {
      return trim((string) $sspConfig->getString('ASSERTION_URL'));
    }
    catch (\Throwable $exception) {
      $this->logger->warning('No se pudo obtener ASSERTION_URL de SimpleSAMLphp: @msg', [
        '@msg' => $exception->getMessage(),
      ]);
      return '';
    }
  }

  /**
   * Construye el NameID requerido para cerrar sesión en Pasarela Cl@ve.
   */
  protected function buildLogoutNameId(Simple $auth, string $assertionUrl): NameID {
    $authSource = $auth->getAuthSource();
    $authId = method_exists($authSource, 'getAuthId') ? $authSource->getAuthId() : '';

    $nameID = new NameID();
    $nameID->setValue($authId);
    $nameID->setSPNameQualifier($assertionUrl);
    $nameID->setFormat(Constants::NAMEID_UNSPECIFIED);

    return $nameID;
  }
}
