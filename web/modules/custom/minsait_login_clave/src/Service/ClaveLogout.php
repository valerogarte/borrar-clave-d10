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

    try {
      $paths = $this->locateSimpleSamlPaths();
    }
    catch (\Throwable $e) {
      $this->logger->error('No se pudo localizar SimpleSAMLphp para el logout de Cl@ve: @msg', ['@msg' => $e->getMessage()]);
      return;
    }

    foreach ($paths['autoloads'] as $autoload) {
      if (file_exists($autoload)) {
        require_once $autoload;
      }
    }

    if (!file_exists($paths['lib_autoload'])) {
      $this->logger->error('No se pudo cargar el autoloader principal de SimpleSAML para realizar el logout.');
      return;
    }

    require_once $paths['lib_autoload'];

    $oldEnv = getenv('SIMPLESAMLPHP_CONFIG_DIR');
    putenv('SIMPLESAMLPHP_CONFIG_DIR=' . $paths['config_dir']);

    try {
      $sspConfig = Configuration::getConfig('config.php');
      $spId = $this->resolveSpId($config, $sspConfig);

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

  protected function locateSimpleSamlPaths(): array {
    $potentialBases = [
      DRUPAL_ROOT . '/../clave/simplesamlphp',
      DRUPAL_ROOT . '/../vendor/simplesamlphp/simplesamlphp',
    ];

    $basePath = NULL;
    foreach ($potentialBases as $candidate) {
      if (is_dir($candidate) && is_dir($candidate . '/config')) {
        $basePath = $candidate;
        break;
      }
    }

    if (!$basePath) {
      throw new \RuntimeException('No se pudo localizar la instalación de SimpleSAMLphp para Cl@ve.');
    }

    $kitBase = DRUPAL_ROOT . '/../clave';
    $autoloads = [];
    if (is_dir($kitBase)) {
      $autoloads[] = $kitBase . '/vendor/autoload.php';
    }
    $autoloads[] = $basePath . '/vendor/autoload.php';

    return [
      'base_path' => $basePath,
      'config_dir' => $basePath . '/config',
      'lib_autoload' => $basePath . '/lib/_autoload.php',
      'autoloads' => $autoloads,
    ];
  }

  protected function resolveSpId($config, Configuration $sspConfig): string {
    $spId = $config->get('sp_id');

    if (is_array($spId)) {
      $spId = reset($spId);
    }

    $spId = trim((string) $spId);

    if ($spId === '' || strtolower($spId) === 'null') {
      $spId = (string) $sspConfig->getString('DEFAULT_SPID');
    }

    if ($spId === '' || strtolower($spId) === 'null') {
      throw new \RuntimeException('No se pudo determinar el SPID para el logout de Cl@ve.');
    }

    return $spId;
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
