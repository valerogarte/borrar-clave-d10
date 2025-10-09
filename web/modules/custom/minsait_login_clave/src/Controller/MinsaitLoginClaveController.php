<?php

namespace Drupal\minsait_login_clave\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use \Drupal\Component\Utility\Crypt;
use SimpleSAML\Auth\Simple;
use SimpleSAML\Configuration;
use SimpleSAML\Utils\Random;
use SAML2\DOMDocumentFactory;
use SAML2\XML\Chunk;
class MinsaitLoginClaveController extends ControllerBase {

  protected $messenger;
  protected $logger;

  public function __construct(MessengerInterface $messenger, LoggerChannelFactoryInterface $loggerFactory) {
    $this->messenger = $messenger;
    $this->logger = $loggerFactory->get('minsait_login_clave');
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger'),
      $container->get('logger.factory')
    );
  }

  public function startLogin(Request $request) {
    $config = $this->config('minsait_login_clave.settings');

    if (!$config->get('enable_clave')) {
      $this->logger->error('Cl@ve no habilitada.');
      throw new ServiceUnavailableHttpException(NULL, 'Error en la configuración de SAML. Clave no habilitada.');
    }

    $oldEnv = NULL;

    try {
      [$auth, $sspConfig, $oldEnv] = $this->bootstrapSimpleSaml($config);

      if ($auth->isAuthenticated()) {
        $query = [];
        $destination = $this->extractDestination($request);
        if ($destination) {
          $query['destination'] = $destination;
        }

        $callbackUrl = Url::fromRoute('minsait_login_clave.clave_callback', [], [
          'absolute' => TRUE,
          'query' => $query,
        ])->toString();

        return new TrustedRedirectResponse($callbackUrl);
      }

      $loginOptions = $this->buildLoginOptions($sspConfig, $config, $request);
      $auth->requireAuth($loginOptions);
    }
    catch (\Throwable $e) {
      $this->logger->error('Error al iniciar el proceso de autenticación de Cl@ve: @msg', ['@msg' => $e->getMessage()]);
      $this->messenger->addError($this->t('No se pudo iniciar el proceso de autenticación con Cl@ve.'));
      return $this->redirect('user.login');
    }
    finally {
      $this->restoreSimpleSamlEnvironment($oldEnv ?? NULL);
    }

    return NULL;
  }

  public function processSamlResponse(Request $request) {
    $config = $this->config('minsait_login_clave.settings');

    if (!$config->get('enable_clave')) {
      $this->logger->error('Cl@ve no habilitada.');
      $this->messenger->addError($this->t('Cl@ve no está habilitada.'));
      return $this->redirect('user.login');
    }

    $oldEnv = NULL;

    try {
      [$auth, $sspConfig, $oldEnv] = $this->bootstrapSimpleSaml($config);

      if (!$auth->isAuthenticated()) {
        $loginOptions = $this->buildLoginOptions($sspConfig, $config, $request);
        $auth->requireAuth($loginOptions);
      }

      $attributes = $this->extractUserAttributes($auth, $sspConfig);

      $personIdentifier = $attributes['PersonIdentifier'] ?? '';
      $givenName = $attributes['CurrentGivenName']
        ?? $attributes['FirstName']
        ?? $attributes['FirstSurname']
        ?? '';
      $familyName = $attributes['CurrentFamilyName']
        ?? $attributes['FirstSurname']
        ?? $attributes['CurrentGivenName']
        ?? '';

      $user = $this->logInDrupalUser($personIdentifier, $givenName, $familyName);

      if (!$user) {
        $this->messenger->addError($this->t('No se pudo completar el inicio de sesión con Cl@ve.'));
        return $this->redirect('user.login');
      }

      $destination = $this->extractDestination($request);
      if ($destination && UrlHelper::isValid($destination, FALSE) && !UrlHelper::isExternal($destination)) {
        try {
          $response = new \Symfony\Component\HttpFoundation\RedirectResponse(Url::fromUserInput($destination)->toString());
        }
        catch (\InvalidArgumentException $e) {
          $response = $this->redirect('<front>');
        }
      }
      else {
        $response = $this->redirect('<front>');
      }

      $cookie = new Cookie('minsait_login_clave', json_encode($user->id()), time() + 86400, '/');

      $response->headers->setCookie($cookie);

      return $response;
    }
    catch (\Throwable $e) {
      $this->logger->error('Error al procesar la respuesta SAML de Cl@ve: @msg', ['@msg' => $e->getMessage()]);
      $this->messenger->addError($this->t('No se pudo completar el inicio de sesión con Cl@ve.'));
      return $this->redirect('user.login');
    }
    finally {
      $this->restoreSimpleSamlEnvironment($oldEnv ?? NULL);
    }
  }

  /**
   * Verifica si el usuario existe por su PersonIdentifier.
   * - Si existe, lo loguea.
   * - Si no existe, lo crea y lo loguea.
   */
  protected function logInDrupalUser($personIdentifier, $givenName, $familyName) {
    $config = $this->config('minsait_login_clave.settings');

    if (empty($personIdentifier)) {
      $this->logger->warning('No se recibió PersonIdentifier para el login.');
      return NULL;
    }

    $users = $this->entityTypeManager()
      ->getStorage('user')
      ->loadByProperties([$config->get('id_field') => $personIdentifier]);
    $user = reset($users);

    if ($user) {
      if ($user instanceof \Drupal\user\Entity\User && $user->isBlocked()) {
        $this->messenger->addError($this->t('El usuario está bloqueado.'));
        return NULL;
      }
      $roles_to_exclude = $config->get('exclude_login');
      if (!empty($roles_to_exclude) && is_array($roles_to_exclude)) {
        foreach ($roles_to_exclude as $role) {
          if ($user->hasRole($role)) {
            $this->messenger->addError($this->t('El usuario no puede logarse en el portal debido a su rol de @role.', [
              '@role' => $role,
            ]));
            return NULL;
          }
        }
      }
      $log_message = 'Usuario Drupal @uid logueado vía Cl@ve (@pid)';
    }
    else {
      if (!$config->get('register_usuario')) {
        $this->messenger->addError($this->t('Usuario no registrado.'));
        return false;
      }
      $user = $this->createInDrupalUser($personIdentifier, $givenName, $familyName);
      $log_message = 'Nuevo usuario Drupal @uid creado y logueado vía Cl@ve (@pid)';
    }

    if (!$user) {
      $this->logger->error('No se pudo crear el usuario Drupal.');
      return NULL;
    }

    if($config->get('sync_field')){
      $nombre_field = $config->get('nombre_field') ?? 'field_nombre';
      $apellido_field = $config->get('apellido_field') ?? 'field_apellido_1';
      $user->set($nombre_field, $givenName);
      $user->set($apellido_field, $familyName);
      $user->save();
    }

    user_login_finalize($user);
    $this->logger->info($log_message, [
      '@uid' => $user->id(),
      '@pid' => $personIdentifier,
    ]);
    
    return $user;
  }

  /**
   * Crea un usuario en Drupal con campos básicos y lo retorna.
   */
  protected function createInDrupalUser($personIdentifier, $givenName, $familyName) {
    $config = $this->config('minsait_login_clave.settings');
    $fakeDomainMail = $config->get('fake_domain_mail') ?? 'cl-ve.identity.user';
    $fakeMail = $personIdentifier . "@" . $fakeDomainMail;

    $newUser = User::create();
    if ($config->get('id_field') == 'name') {
      $newUser->setUsername($personIdentifier);
    }else{
      $fullDocName = $givenName . '.' . $familyName . '.' . microtime(true);
      $fullDocName = strtolower(str_replace(' ', '_', $fullDocName));
      $newUser->setUsername($fullDocName);
      $newUser->set($config->get('id_field'), $personIdentifier);
    }
    $newUser->enforceIsNew();
    $newUser->setEmail($fakeMail);
    $randomPwd = Crypt::randomBytesBase64(32);
    $newUser->setPassword($randomPwd);

    // Ajuste: usar campos configurables para nombre y apellido.
    $nombre_field = $config->get('nombre_field') ?? 'field_nombre';
    $apellido_field = $config->get('apellido_field') ?? 'field_apellido_1';
    $newUser->set($nombre_field, $givenName);
    $newUser->set($apellido_field, $familyName);

    $assign_roles = $config->get('assign_roles');
    if (!empty($assign_roles) && is_array($assign_roles)) {
      $newUser->set('roles', $assign_roles);
    }

    $newUser->activate();
    $newUser->save();

    return $newUser;
  }

  protected function bootstrapSimpleSaml($config) {
    $paths = $this->locateSimpleSamlPaths();

    foreach ($paths['autoloads'] as $autoload) {
      if (file_exists($autoload)) {
        require_once $autoload;
      }
    }

    if (!file_exists($paths['lib_autoload'])) {
      throw new \RuntimeException('No se encontró el autoload principal de SimpleSAMLphp.');
    }

    require_once $paths['lib_autoload'];

    $oldEnv = getenv('SIMPLESAMLPHP_CONFIG_DIR');
    putenv('SIMPLESAMLPHP_CONFIG_DIR=' . $paths['config_dir']);

    $sspConfig = Configuration::getConfig('config.php');
    $spId = $config->get('sp_id');

    if (is_array($spId)) {
      $spId = reset($spId);
    }

    $spId = trim((string) $spId);
    if ($spId === '') {
      $spId = (string) $sspConfig->getString('DEFAULT_SPID');
    }

    if ($spId === '') {
      throw new \RuntimeException('No se pudo determinar el SPID para Cl@ve.');
    }

    $auth = new Simple($spId);

    return [$auth, $sspConfig, $oldEnv];
  }

  protected function restoreSimpleSamlEnvironment($oldEnv) {
    if ($oldEnv === NULL) {
      return;
    }

    if ($oldEnv !== false) {
      putenv('SIMPLESAMLPHP_CONFIG_DIR=' . $oldEnv);
    }
    else {
      putenv('SIMPLESAMLPHP_CONFIG_DIR');
    }
  }

  protected function locateSimpleSamlPaths() {
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

  protected function buildLoginOptions(Configuration $sspConfig, $config, Request $request) {
    $query = [];
    $destination = $this->extractDestination($request);
    if ($destination) {
      $query['destination'] = $destination;
    }

    $returnTo = Url::fromRoute('minsait_login_clave.clave_callback', [], [
      'absolute' => TRUE,
      'query' => $query,
    ])->toString();

    $errorUrl = $returnTo;

    $loa = $request->request->get('loa');
    if (!is_string($loa) || $loa === '') {
      $loa = (string) ($config->get('loa') ?? 'http://eidas.europa.eu/LoA/low');
    }

    $forceAuthnValue = $config->get('force_login');
    $forceAuthnRequest = $request->request->get('forceauthn');
    if ($forceAuthnRequest !== NULL) {
      if (is_bool($forceAuthnRequest)) {
        $forceAuthnValue = $forceAuthnRequest;
      }
      else {
        $parsedForceAuthn = \filter_var($forceAuthnRequest, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($parsedForceAuthn !== NULL) {
          $forceAuthnValue = $parsedForceAuthn;
        }
      }
    }

    $options = [
      'saml:AuthnContextClassRef' => $loa,
      'saml:AuthnContextComparison' => 'minimum',
      'ForceAuthn' => (bool) $forceAuthnValue,
      'ReturnTo' => $returnTo,
      'ErrorURL' => $errorUrl,
    ];

    $extensions = $this->buildSamlExtensions($sspConfig, $config, $request);
    if (!empty($extensions)) {
      $options['saml:Extensions'] = $extensions;
    }

    return $options;
  }

  protected function buildSamlExtensions(Configuration $sspConfig, $config, Request $request) {
    $extensions = [];
    $attributesArray = [];

    $nsEidas = $sspConfig->getString('NS_EIDAS');
    $nsEidasNatural = $sspConfig->getString('NS_EIDAS_NATURAL');
    $includeRelaystate = $sspConfig->getBoolean('INCLUDE_RELAYSTATE_ATTR');
    $idpAttrs = $sspConfig->getArray('attrs');

    $dom = DOMDocumentFactory::create();

    $spType = $request->request->get('SPType');
    if (!is_string($spType) || $spType === '') {
      $spType = 'public';
    }

    $ceType = $dom->createElementNS($nsEidas, 'eidas:SPType', $spType);
    $ceType->setAttribute('xmlns:eidas', $nsEidas);
    $extensions['eidas:SPType'] = new Chunk($ceType);

    if (
      $includeRelaystate
      && isset($idpAttrs['RelayState.name'], $idpAttrs['RelayState.uri'], $idpAttrs['RelayState.nameFormat'])
    ) {
      $relayState = (new Random())->generateID();
      $ce0 = $dom->createElement('eidas:AttributeValue', $relayState);
      $ce0->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
      $ce0->setAttribute('xsi:type', 'eidas-natural:PersonIdentifierType');

      $ce1 = $dom->createElementNS($nsEidas, 'eidas:RequestedAttribute');
      $ce1->setAttribute('FriendlyName', $idpAttrs['RelayState.name']);
      $ce1->setAttribute('Name', $idpAttrs['RelayState.uri']);
      $ce1->setAttribute('NameFormat', $idpAttrs['RelayState.nameFormat']);
      $ce1->setAttribute('isRequired', 'false');
      $ce1->appendChild($ce0);
      $attributesArray[] = $ce1;
    }

    $idpConfigMap = [
      'AEATIdP' => 'AEATIdP',
      'EIDASIdP' => 'EIDASIdP',
      'CLVMOVILIdP' => 'CLVMOVILIdP',
      'AFirmaIdP' => 'AFirmaIdP',
      'GISSIdP' => 'GISSIdP',
    ];

    foreach ($idpConfigMap as $configKey => $attrKey) {
      $value = $config->get($configKey);
      $requestToggle = $request->request->get($attrKey);
      if ($requestToggle !== NULL) {
        $value = $requestToggle;
      }

      if ($value === 0 || $value === '0' || $value === FALSE || $value === 'off') {
        $name = $idpAttrs[$attrKey . '.name'] ?? NULL;
        $nameFormat = $idpAttrs[$attrKey . '.nameFormat'] ?? 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri';
        $uri = $idpAttrs[$attrKey . '.uri'] ?? NULL;

        if ($uri) {
          $ce1 = $dom->createElementNS($nsEidas, 'eidas:RequestedAttribute');
          if ($name) {
            $ce1->setAttribute('FriendlyName', $name);
          }
          $ce1->setAttribute('Name', $uri);
          $ce1->setAttribute('NameFormat', $nameFormat);
          $ce1->setAttribute('isRequired', 'false');
          $attributesArray[] = $ce1;
        }
      }
    }

    if (!empty($attributesArray)) {
      $requestedAttributes = $dom->createElementNS($nsEidas, 'eidas:RequestedAttributes');
      $requestedAttributes->setAttribute('xmlns:eidas', $nsEidas);
      $requestedAttributes->setAttributeNS($nsEidas, 'eidas:tmp', 'tmp');
      $requestedAttributes->removeAttributeNS($nsEidas, 'tmp');
      $requestedAttributes->setAttributeNS($nsEidasNatural, 'eidas-natural:tmp', 'tmp');
      $requestedAttributes->removeAttributeNS($nsEidasNatural, 'tmp');

      foreach ($attributesArray as $attribute) {
        $requestedAttributes->appendChild($attribute);
      }

      $extensions['RequestedAttributes'] = new Chunk($requestedAttributes);
    }

    return $extensions;
  }

  protected function extractUserAttributes(Simple $auth, Configuration $sspConfig) {
    $attributes = $auth->getAttributes();
    $cleanAttributes = [];
    $mapping = $sspConfig->getArray('attrs');

    foreach (array_keys($attributes) as $key) {
      $value = array_search($key, $mapping, TRUE);
      if ($value !== FALSE) {
        $indexKey = explode('.', $value);
        $cleanAttributes[$indexKey[0]] = $attributes[$key][0] ?? NULL;
      }
    }

    return $cleanAttributes;
  }

  protected function extractDestination(Request $request) {
    $destination = $request->get('destination');
    if (is_string($destination) && $destination !== '') {
      return $destination;
    }

    return NULL;
  }
}
