<?php

namespace Drupal\minsait_login_clave\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
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
    $this->logger->info('El servicio claveLogout ha sido invocado durante el logout del usuario.');

    $config = $this->configFactory->get('minsait_login_clave.settings');

    if (!$config->get('enable_clave') ?: false) {
      $this->logger->error('Cl@ve no habilitada.');
      throw new ServiceUnavailableHttpException(NULL, 'Error en la configuración de SAML. Clave no habilitada.');
    }

    $kitBasePath = DRUPAL_ROOT . '/../clave';
    $sspBasePath = $kitBasePath . '/simplesamlphp';
    $sspConfigDir = $sspBasePath . '/config';

    if (!is_dir($kitBasePath) || !is_dir($sspBasePath) || !is_dir($sspConfigDir)) {
      $this->logger->error('No se encontró la instalación del kit Cl@ve durante el logout. Base: @base, SimpleSAML: @ssp, Config: @config', [
        '@base' => $kitBasePath,
        '@ssp' => $sspBasePath,
        '@config' => $sspConfigDir,
      ]);
      return;
    }

    $kitAutoload = $kitBasePath . '/vendor/autoload.php';
    if (file_exists($kitAutoload)) {
      require_once $kitAutoload;
    }

    $sspAutoload = $sspBasePath . '/vendor/autoload.php';
    if (file_exists($sspAutoload)) {
      require_once $sspAutoload;
    }

    $sspLibAutoload = $sspBasePath . '/lib/_autoload.php';
    if (!file_exists($sspLibAutoload)) {
      $this->logger->error('No se pudo cargar el autoloader principal de SimpleSAML para realizar el logout.');
      return;
    }

    require_once $sspLibAutoload;

    $oldEnv = getenv('SIMPLESAMLPHP_CONFIG_DIR');
    putenv('SIMPLESAMLPHP_CONFIG_DIR=' . $sspConfigDir);

    try {
      $sspConfig = Configuration::getConfig('config.php');
      $spId = $config->get('sp_id');
      if (is_array($spId)) {
        $spId = reset($spId);
      }
      $spId = trim((string) $spId);
      if ($spId === '') {
        $spId = $sspConfig->getString('DEFAULT_SPID');
      }
      if ($spId === '') {
        throw new \RuntimeException('No se pudo determinar el SPID para el logout de Cl@ve.');
      }

      $auth = new Simple($spId);
      if (!$auth->isAuthenticated()) {
        return;
      }

      $returnTo = $this->getReturnUrl();
      $logoutOptions = [
        'ReturnTo' => $returnTo,
        'ErrorURL' => $returnTo,
      ];

      $auth->logout($logoutOptions);
      Session::getSessionFromRequest()->cleanup();
    }
    catch (\Throwable $e) {
      $this->logger->error('Error al ejecutar el logout en Cl@ve: @msg', ['@msg' => $e->getMessage()]);
    }
    finally {
      if ($oldEnv !== false && $oldEnv !== NULL) {
        putenv('SIMPLESAMLPHP_CONFIG_DIR=' . $oldEnv);
      }
      else {
        putenv('SIMPLESAMLPHP_CONFIG_DIR');
      }
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
}
