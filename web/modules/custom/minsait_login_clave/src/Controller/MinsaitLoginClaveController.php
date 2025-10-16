<?php

namespace Drupal\minsait_login_clave\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Drupal\Component\Utility\Crypt;
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
      throw new ServiceUnavailableHttpException(NULL, 'El acceso con Cl@ve no está habilitado.');
    }

    $bootstrap = $this->bootstrapSimpleSaml($config, $request);
    $auth = $bootstrap['auth'];
    $sspConfig = $bootstrap['ssp_config'];
    $session = $bootstrap['session'];

    if ($auth->isAuthenticated()) {
      $options = [];
      $destination = $request->get('destination');
      if (is_string($destination) && $destination !== '' && UrlHelper::isValid($destination, TRUE) && !UrlHelper::isExternal($destination)) {
        $options['query']['destination'] = $destination;
      }

      return $this->redirect('minsait_login_clave.clave_callback', [], $options);
    }

    $destination = $request->get('destination');
    if (is_string($destination) && $destination !== '' && UrlHelper::isValid($destination, TRUE) && !UrlHelper::isExternal($destination)) {
      $session->setData('string', 'minsait_login_destination', $destination);
    }

    $requestData = array_merge($request->query->all(), $request->request->all());

    $loa = $requestData['loa'] ?? $config->get('loa') ?? 'http://eidas.europa.eu/LoA/low';
    $forceAuthn = $requestData['forceauthn'] ?? ($config->get('force_login') ? 'true' : 'false');
    $forceAuthnBool = filter_var($forceAuthn, FILTER_VALIDATE_BOOLEAN);

    $extensions = $this->buildSamlExtensions($sspConfig, $requestData);

    $returnTo = Url::fromRoute('minsait_login_clave.clave_callback', [], ['absolute' => TRUE])->toString();
    $errorUrl = Url::fromRoute('minsait_login_clave.clave_error', [], ['absolute' => TRUE])->toString();

    try {
      $auth->requireAuth([
        'saml:Extensions' => $extensions,
        'saml:AuthnContextClassRef' => $loa,
        'saml:AuthnContextComparison' => 'minimum',
        'ForceAuthn' => $forceAuthnBool,
        'ReturnTo' => $returnTo,
        'ErrorURL' => $errorUrl,
      ]);
    }
    catch (\Throwable $throwable) {
      $this->logger->error('Error iniciando sesión con Cl@ve: @msg', ['@msg' => $throwable->getMessage()]);
      throw new ServiceUnavailableHttpException(NULL, 'No se ha podido iniciar sesión con Cl@ve.', $throwable);
    }

    return $this->redirect('<front>');
  }

  public function processSamlResponse(Request $request) {
    $config = $this->config('minsait_login_clave.settings');

    if (!$config->get('enable_clave')) {
      throw new ServiceUnavailableHttpException(NULL, 'El acceso con Cl@ve no está habilitado.');
    }

    $bootstrap = $this->bootstrapSimpleSaml($config, $request);
    $auth = $bootstrap['auth'];
    $sspConfig = $bootstrap['ssp_config'];
    $session = $bootstrap['session'];

    if (!$auth->isAuthenticated()) {
      $this->messenger->addError($this->t('No se ha podido completar la autenticación con Cl@ve.'));
      return $this->redirect('minsait_login_clave.clave_login');
    }

    $cleanAttributes = $this->extractAttributes($auth, $sspConfig);

    $personIdentifier = $cleanAttributes['PersonIdentifier'] ?? $cleanAttributes['personidentifier'] ?? NULL;
    $givenName = $cleanAttributes['CurrentGivenName'] ?? $cleanAttributes['currentgivenname'] ?? ($cleanAttributes['GivenName'] ?? '');
    $familyName = $cleanAttributes['CurrentFamilyName'] ?? $cleanAttributes['currentfamilyname'] ?? ($cleanAttributes['FirstSurname'] ?? '');

    if (empty($familyName) && isset($cleanAttributes['CurrentFamilyName'])) {
      $familyName = $cleanAttributes['CurrentFamilyName'];
    }

    if (!$personIdentifier) {
      $this->logger->error('La respuesta de Cl@ve no incluye PersonIdentifier. Atributos recibidos: @attrs', ['@attrs' => json_encode($cleanAttributes)]);
      $this->messenger->addError($this->t('No se ha podido identificar al usuario devuelto por Cl@ve.'));
      return $this->redirect('user.login');
    }

    $user = $this->logInDrupalUser($personIdentifier, $givenName, $familyName);
    if (!$user) {
      return $this->redirect('user.login');
    }

    $cookieValue = json_encode($user->id());
    $expiry = \Drupal::time()->getRequestTime() + 86400;
    $cookie = new Cookie('minsait_login_clave', $cookieValue, $expiry, '/', NULL, $request->isSecure(), TRUE, FALSE, Cookie::SAMESITE_LAX);

    $destination = $session->getData('string', 'minsait_login_destination');
    $session->deleteData('string', 'minsait_login_destination');

    if ($session->getData('string', 'spid')) {
      $session->deleteData('string', 'spid');
    }

    if (!empty($destination) && $destination[0] !== '/') {
      $destination = '/' . ltrim($destination, '/');
    }

    $response = NULL;
    if (!empty($destination) && UrlHelper::isValid($destination, TRUE) && !UrlHelper::isExternal($destination)) {
      $destinationUrl = Url::fromUserInput($destination);
      $response = $this->redirect($destinationUrl->getRouteName(), $destinationUrl->getRouteParameters(), $destinationUrl->getOptions());
    }
    else {
      $response = $this->redirect('<front>');
    }

    $response->headers->setCookie($cookie);

    return $response;
  }

  public function processSamlError(Request $request) {
    $config = $this->config('minsait_login_clave.settings');

    if (!$config->get('enable_clave')) {
      $this->messenger->addError($this->t('El acceso con Cl@ve no está habilitado.'));
      return $this->redirect('user.login');
    }

    $this->bootstrapSimpleSaml($config, $request);

    $errorMessage = $this->t('Ha ocurrido un error durante el inicio de sesión con Cl@ve.');

    foreach ($request->query->all() as $getVar) {
      try {
        $state = State::loadExceptionState($getVar);
        if (isset($state['\\SimpleSAML\\Auth\\State.exceptionData'])) {
          $exception = $state['\\SimpleSAML\\Auth\\State.exceptionData'];
          if (method_exists($exception, 'getStatusMessage') && $exception->getStatusMessage()) {
            $errorMessage = $exception->getStatusMessage();
            break;
          }
        }
      }
      catch (\Throwable $throwable) {
        $this->logger->warning('No se pudo recuperar el estado de error de Cl@ve: @msg', ['@msg' => $throwable->getMessage()]);
      }
    }

    $this->messenger->addError($errorMessage);

    return $this->redirect('user.login');
  }

  public function logout(Request $request) {
    try {
      \Drupal::service('minsait_login_clave.clave_logout')->logout();
    }
    catch (\Throwable $throwable) {
      $this->logger->error('Error al cerrar la sesión en Cl@ve: @msg', ['@msg' => $throwable->getMessage()]);
      $this->messenger->addError($this->t('Se produjo un error al cerrar la sesión en Cl@ve.'));
    }

    $response = $this->redirect('<front>');
    $response->headers->clearCookie('minsait_login_clave', '/', NULL, $request->isSecure(), TRUE, Cookie::SAMESITE_LAX);

    return $response;
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

  protected function bootstrapSimpleSaml($config, ?Request $request = NULL) {
    $kitBasePath = DRUPAL_ROOT . '/../clave';
    $sspBasePath = $kitBasePath . '/simplesamlphp';
    $sspConfigDir = $sspBasePath . '/config';

    if (!is_dir($kitBasePath) || !is_dir($sspBasePath) || !is_dir($sspConfigDir)) {
      $this->logger->error('No se encontró la instalación del kit Cl@ve. Ruta esperada: @path', ['@path' => $kitBasePath]);
      throw new ServiceUnavailableHttpException(NULL, 'Error en la configuración de SAML.');
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
      $this->logger->error('No se pudo cargar el autoloader principal de SimpleSAMLphp.');
      throw new ServiceUnavailableHttpException(NULL, 'Error en la configuración de SAML.');
    }

    require_once $sspLibAutoload;

    putenv('SIMPLESAMLPHP_CONFIG_DIR=' . $sspConfigDir);

    try {
      $sspConfig = Configuration::getConfig('config.php');
    }
    catch (\Throwable $throwable) {
      $this->logger->error('No se pudo cargar config.php de SimpleSAMLphp: @msg', ['@msg' => $throwable->getMessage()]);
      throw new ServiceUnavailableHttpException(NULL, 'Error en la configuración de SAML.', $throwable);
    }

    $session = Session::getSessionFromRequest();

    $spId = $session->getData('string', 'spid');
    $requestedSource = NULL;
    if ($request) {
      $requestedSource = $request->get('source');
    }

    if ($requestedSource) {
      $spId = (string) $requestedSource;
      $session->setData('string', 'spid', $spId);
    }

    if (!$spId) {
      $spId = $config->get('sp_id');
      if (is_array($spId)) {
        $spId = reset($spId);
      }
      $spId = trim((string) $spId);
      if ($spId !== '') {
        $session->setData('string', 'spid', $spId);
      }
    }

    if ($spId === '') {
      $spId = $sspConfig->getString('DEFAULT_SPID', '');
      if ($spId === '') {
        throw new ServiceUnavailableHttpException(NULL, 'No se pudo determinar el SPID configurado.');
      }
      $session->setData('string', 'spid', $spId);
    }

    try {
      $auth = new Simple($spId);
    }
    catch (\Throwable $throwable) {
      $this->logger->error('No se pudo crear la instancia SimpleSAML Auth para SPID @spid: @msg', [
        '@spid' => $spId,
        '@msg' => $throwable->getMessage(),
      ]);
      throw new ServiceUnavailableHttpException(NULL, 'No se ha podido inicializar la autenticación con Cl@ve.', $throwable);
    }

    return [
      'auth' => $auth,
      'ssp_config' => $sspConfig,
      'session' => $session,
      'assertion_url' => $sspConfig->getString('ASSERTION_URL', ''),
    ];
  }

  protected function buildSamlExtensions(Configuration $sspConfig, array $requestData): array {
    $extensions = [];

    $nsEidas = $sspConfig->getString('NS_EIDAS', '');
    $nsEidasNatural = $sspConfig->getString('NS_EIDAS_NATURAL', '');
    $includeRelayState = $sspConfig->getBoolean('INCLUDE_RELAYSTATE_ATTR', FALSE);
    $attrsConfig = $sspConfig->getArray('attrs');

    $dom = DOMDocumentFactory::create();

    $spType = $requestData['SPType'] ?? 'public';
    if ($nsEidas !== '') {
      $spTypeElement = $dom->createElementNS($nsEidas, 'eidas:SPType', $spType);
      $spTypeElement->setAttribute('xmlns:eidas', $nsEidas);
    }
    else {
      $spTypeElement = $dom->createElement('eidas:SPType', $spType);
    }

    $extensions['eidas:SPType'] = new Chunk($spTypeElement);

    $attributesArray = [];

    if ($includeRelayState) {
      $relayState = (new Random())->generateID();
      $friendly = $attrsConfig['RelayState.name'] ?? 'RelayState';
      $name = $attrsConfig['RelayState.uri'] ?? 'http://es.minhafp.clave/RelayState';
      $nameFormat = $attrsConfig['RelayState.nameFormat'] ?? 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri';

      $valueElement = $dom->createElement('eidas:AttributeValue', $relayState);
      $valueElement->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
      $valueElement->setAttribute('xsi:type', 'eidas-natural:PersonIdentifierType');

      if ($nsEidas !== '') {
        $attributeElement = $dom->createElementNS($nsEidas, 'eidas:RequestedAttribute');
      }
      else {
        $attributeElement = $dom->createElement('eidas:RequestedAttribute');
      }
      $attributeElement->setAttribute('FriendlyName', $friendly);
      $attributeElement->setAttribute('Name', $name);
      $attributeElement->setAttribute('NameFormat', $nameFormat);
      $attributeElement->setAttribute('isRequired', 'false');
      $attributeElement->appendChild($valueElement);

      $attributesArray[] = $attributeElement;
    }

    foreach ($attrsConfig as $key => $value) {
      if (!str_ends_with($key, '.uri')) {
        continue;
      }

      $attributeName = substr($key, 0, -4);
      if (!isset($requestData[$attributeName]) || $requestData[$attributeName] !== 'off') {
        continue;
      }

      $friendly = $attrsConfig[$attributeName . '.name'] ?? $attributeName;
      $uri = $value;
      $nameFormat = $attrsConfig[$attributeName . '.nameFormat'] ?? 'urn:oasis:names:tc:SAML:2.0:attrname-format:uri';

      if ($nsEidas !== '') {
        $attributeElement = $dom->createElementNS($nsEidas, 'eidas:RequestedAttribute');
      }
      else {
        $attributeElement = $dom->createElement('eidas:RequestedAttribute');
      }

      $attributeElement->setAttribute('FriendlyName', $friendly);
      $attributeElement->setAttribute('Name', $uri);
      $attributeElement->setAttribute('NameFormat', $nameFormat);
      $attributeElement->setAttribute('isRequired', 'false');

      $attributesArray[] = $attributeElement;
    }

    if (!empty($attributesArray)) {
      if ($nsEidas !== '') {
        $requestedAttributes = $dom->createElementNS($nsEidas, 'eidas:RequestedAttributes');
        $requestedAttributes->setAttribute('xmlns:eidas', $nsEidas);
        $requestedAttributes->setAttributeNS($nsEidas, 'eidas:tmp', 'tmp');
        $requestedAttributes->removeAttributeNS($nsEidas, 'tmp');
      }
      else {
        $requestedAttributes = $dom->createElement('eidas:RequestedAttributes');
      }

      if ($nsEidasNatural !== '') {
        $requestedAttributes->setAttributeNS($nsEidasNatural, 'eidas-natural:tmp', 'tmp');
        $requestedAttributes->removeAttributeNS($nsEidasNatural, 'tmp');
      }

      foreach ($attributesArray as $attributeElement) {
        $requestedAttributes->appendChild($attributeElement);
      }

      $extensions['RequestedAttributes'] = new Chunk($requestedAttributes);
    }

    return $extensions;
  }

  protected function extractAttributes(Simple $auth, Configuration $sspConfig): array {
    $attributes = $auth->getAttributes();
    $clean = [];
    $attrsConfig = $sspConfig->getArray('attrs');

    foreach ($attributes as $key => $values) {
      if (empty($values)) {
        continue;
      }

      $foundKey = array_search($key, $attrsConfig, TRUE);
      if ($foundKey === FALSE) {
        continue;
      }

      $indexKey = explode('.', $foundKey);
      $cleanKey = strtolower($indexKey[0]);
      $clean[$cleanKey] = $values[0];
    }

    return $clean;
  }
}
