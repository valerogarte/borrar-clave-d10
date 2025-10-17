<?php

namespace Drupal\minsait_login_clave\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use \Drupal\Component\Utility\Crypt;
use SimpleSAML\Auth\Simple;
use SimpleSAML\Auth\State;
use SimpleSAML\Configuration;
use SimpleSAML\Session;
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
      [$auth, $sspConfig, $oldEnv, $sspSession] = $this->bootstrapSimpleSaml($config, $request);

      $this->rememberSimpleSamlSession($request, $sspSession);

      if ($auth->isAuthenticated()) {
        $destination = $this->extractDestination($request);
        $query = [];

        if ($destination && UrlHelper::isValid($destination, FALSE) && !UrlHelper::isExternal($destination)) {
          $query['destination'] = $destination;
        }

        return $this->redirect('minsait_login_clave.clave_callback', [], [
          'absolute' => TRUE,
          'query' => $query,
        ]);
      }

      $loginOptions = $this->buildLoginOptions($sspConfig, $config, $request);
      $auth->requireAuth($loginOptions);
    }
    catch (\Throwable $e) {
      $this->logger->error('Error iniciando autenticación con Cl@ve: @msg', ['@msg' => $e->getMessage()]);
      $this->messenger->addError($this->t('No se pudo iniciar sesión con Cl@ve.'));
      return $this->redirect('user.login');
    }
    finally {
      $this->restoreSimpleSamlEnvironment($oldEnv ?? NULL);
    }

    return $this->redirect('<front>');
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
      [$auth, $sspConfig, $oldEnv, $sspSession] = $this->bootstrapSimpleSaml($config, $request, FALSE);

      if ($auth->isAuthenticated()) {
        $this->confirmSimpleSamlSession($request, $sspSession);
      }
      else {
        $this->rememberSimpleSamlSession($request, $sspSession);
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

      $cookie = new Cookie(
        'minsait_login_clave', 
        json_encode($user->id()), 
        time() + 86400, 
        '/',
        null,
        true, // secure (HTTPS only)
        true, // httpOnly (not accessible via JavaScript)
        false,
        Cookie::SAMESITE_STRICT // CSRF protection
      );

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

  public function processSamlError(Request $request) {
    $config = $this->config('minsait_login_clave.settings');

    if (!$config->get('enable_clave')) {
      $this->logger->error('Cl@ve no habilitada.');
      $this->messenger->addError($this->t('Cl@ve no está habilitada.'));
      return $this->redirect('user.login');
    }

    $oldEnv = NULL;

    try {
      [, , $oldEnv, $_sspSession] = $this->bootstrapSimpleSaml($config, $request);

      $errorMessage = $this->t('Error desconocido devuelto por Cl@ve.');

      foreach ($request->query->all() as $value) {
        if (!is_string($value) || $value === '') {
          continue;
        }

        try {
          $state = State::loadExceptionState($value);
        }
        catch (\Throwable $stateException) {
          $this->logger->warning('No se pudo interpretar el estado de error de Cl@ve: @msg', [
            '@msg' => $stateException->getMessage(),
          ]);
          continue;
        }

        if (isset($state['\\SimpleSAML\\Auth\\State.exceptionData'])) {
          $exceptionData = $state['\\SimpleSAML\\Auth\\State.exceptionData'];
          if (is_object($exceptionData) && method_exists($exceptionData, 'getStatusMessage')) {
            $message = $exceptionData->getStatusMessage();
            if (!empty($message)) {
              $errorMessage = $message;
              break;
            }
          }
        }
      }

      $this->messenger->addError($this->t('No se pudo completar el inicio de sesión con Cl@ve: @msg', [
        '@msg' => $errorMessage,
      ]));
      $this->logger->error('Error devuelto por Cl@ve: @msg', [
        '@msg' => $errorMessage,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('Error procesando el estado de error de Cl@ve: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->t('No se pudo completar el inicio de sesión con Cl@ve.'));
    }
    finally {
      $this->restoreSimpleSamlEnvironment($oldEnv ?? NULL);
    }

    return $this->redirect('user.login');
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
      $nombre_field = $config->get('nombre_field');
      $apellido_field = $config->get('apellido_field');
      
      if (!empty($nombre_field) && $user->hasField($nombre_field)) {
        $user->set($nombre_field, $givenName);
      }
      if (!empty($apellido_field) && $user->hasField($apellido_field)) {
        $user->set($apellido_field, $familyName);
      }
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
    $nombre_field = $config->get('nombre_field');
    $apellido_field = $config->get('apellido_field');
    
    if (!empty($nombre_field) && $newUser->hasField($nombre_field)) {
      $newUser->set($nombre_field, $givenName);
    }
    if (!empty($apellido_field) && $newUser->hasField($apellido_field)) {
      $newUser->set($apellido_field, $familyName);
    }

    $assign_roles = $config->get('assign_roles');
    if (!empty($assign_roles) && is_array($assign_roles)) {
      $newUser->set('roles', $assign_roles);
    }

    $newUser->activate();
    $newUser->save();

    return $newUser;
  }

  protected function bootstrapSimpleSaml($config, Request $request, bool $prepareSession = TRUE) {
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
    $sspSession = $this->loadSimpleSamlSession();

    $spId = $this->determineSpId($sspConfig, $config, $request, $sspSession, $prepareSession);

    if ($prepareSession && $sspSession instanceof Session) {
      $this->storeSpIdInSession($sspSession, $spId);
    }
    elseif ($prepareSession) {
      $this->logger->warning('No se pudo acceder a la sesión de SimpleSAMLphp para guardar el SPID seleccionado (@spid).', [
        '@spid' => $spId,
      ]);
    }

    $auth = new Simple($spId);

    return [$auth, $sspConfig, $oldEnv, $sspSession];
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

  protected function storeSpIdInSession(Session $session, string $spId): void {
    try {
      $session->cleanup();
      $session->deleteData('string', 'spid');
      $session->setData('string', 'spid', $spId);
    }
    catch (\Throwable $exception) {
      $this->logger->warning('No se pudo guardar el SPID (@spid) en la sesión de SimpleSAMLphp: @msg', [
        '@spid' => $spId,
        '@msg' => $exception->getMessage(),
      ]);
    }
  }

  protected function determineSpId(Configuration $sspConfig, $config, Request $request, ?Session $session, bool $allowQuerySelection): string {
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

    if ($allowQuerySelection) {
      $requestedSpId = $request->query->get('source');
      if (is_string($requestedSpId) && $requestedSpId !== '') {
        $spId = $requestedSpId;
      }
    }
    else {
      if ($session instanceof Session) {
        $storedSpId = $session->getData('string', 'spid');
        if (is_string($storedSpId) && $storedSpId !== '') {
          $spId = $storedSpId;
        }
      }
    }

    if ($spId === '') {
      throw new \RuntimeException('No se pudo determinar el SPID para Cl@ve.');
    }

    return $spId;
  }

  protected function buildLoginOptions(Configuration $sspConfig, $config, Request $request) {
    $query = [];
    $destination = $this->extractDestination($request);
    if ($destination) {
      $query['destination'] = $destination;
    }

    [$returnTo, $errorUrl] = $this->buildReturnUrls($sspConfig, $query);

    $options = [
      'saml:AuthnContextClassRef' => $config->get('loa') ?? 'http://eidas.europa.eu/LoA/low',
      'saml:AuthnContextComparison' => 'minimum',
      'ForceAuthn' => (bool) $config->get('force_login'),
      'ReturnTo' => $returnTo,
      'ErrorURL' => $errorUrl,
    ];

    $extensions = $this->buildSamlExtensions($sspConfig, $config);
    if (!empty($extensions)) {
      $options['saml:Extensions'] = $extensions;
    }

    return $options;
  }

  protected function buildSamlExtensions(Configuration $sspConfig, $config) {
    $extensions = [];
    $attributesArray = [];

    $nsEidas = $sspConfig->getString('NS_EIDAS');
    $nsEidasNatural = $sspConfig->getString('NS_EIDAS_NATURAL');
    $includeRelaystate = $sspConfig->getBoolean('INCLUDE_RELAYSTATE_ATTR');
    $idpAttrs = $sspConfig->getArray('attrs');

    $dom = DOMDocumentFactory::create();

    $ceType = $dom->createElementNS($nsEidas, 'eidas:SPType', 'public');
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
      if ($value === 0 || $value === '0' || $value === FALSE) {
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

  protected function rememberSimpleSamlSession(Request $request, ?Session $session): void {
    if (!$session instanceof Session) {
      return;
    }

    $sessionId = $session->getSessionId();
    if ($sessionId === NULL || $sessionId === '') {
      return;
    }

    $drupalSession = $this->getDrupalSession($request);
    if (!$drupalSession instanceof SessionInterface) {
      return;
    }

    $drupalSession->set('minsait_login_clave.simple_saml_session_id', $sessionId);
  }

  protected function confirmSimpleSamlSession(Request $request, ?Session $session): void {
    $drupalSession = $this->getDrupalSession($request);
    if (!$drupalSession instanceof SessionInterface) {
      return;
    }

    $expectedSessionId = $drupalSession->get('minsait_login_clave.simple_saml_session_id');
    $drupalSession->remove('minsait_login_clave.simple_saml_session_id');

    if (!is_string($expectedSessionId) || $expectedSessionId === '') {
      return;
    }

    if (!$session instanceof Session) {
      $this->logger->warning('No se pudo verificar la sesión de SimpleSAMLphp esperada (@expected) porque no se recibió ninguna sesión.', [
        '@expected' => $expectedSessionId,
      ]);
      return;
    }

    $currentSessionId = $session->getSessionId();
    if (!is_string($currentSessionId) || $currentSessionId === '') {
      $this->logger->warning('No se pudo verificar la sesión de SimpleSAMLphp esperada (@expected) porque la sesión actual no tiene identificador.', [
        '@expected' => $expectedSessionId,
      ]);
      return;
    }

    if (!hash_equals($expectedSessionId, $currentSessionId)) {
      $this->logger->warning('La sesión de SimpleSAMLphp esperada (@expected) no coincide con la sesión recibida (@current).', [
        '@expected' => $expectedSessionId,
        '@current' => $currentSessionId,
      ]);
    }
  }

  protected function getDrupalSession(Request $request): ?SessionInterface {
    if (!$request->hasSession()) {
      return NULL;
    }

    try {
      return $request->getSession();
    }
    catch (\Throwable $exception) {
      $this->logger->warning('No se pudo acceder a la sesión de Drupal: @msg', [
        '@msg' => $exception->getMessage(),
      ]);
      return NULL;
    }
  }

  protected function buildReturnUrls(Configuration $sspConfig, array $query = []): array {
    $base = rtrim($sspConfig->getString('ASSERTION_URL'), '/');

    $callbackPath = Url::fromRoute('minsait_login_clave.clave_callback', [], [
      'absolute' => FALSE,
      'query' => $query,
    ])->toString();
    $callbackPath = '/' . ltrim($callbackPath, '/');
    $callbackUrl = $base . $callbackPath;

    $errorPath = Url::fromRoute('minsait_login_clave.clave_error', [], [
      'absolute' => FALSE,
      'query' => $query,
    ])->toString();
    $errorPath = '/' . ltrim($errorPath, '/');
    $errorUrl = $base . $errorPath;

    return [$callbackUrl, $errorUrl];
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
