<?php

namespace Drupal\minsait_login_clave\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use \Drupal\Component\Utility\Crypt;
use SimpleSAML\Auth\Simple;
use SimpleSAML\Auth\State;
use SimpleSAML\Configuration;
use SimpleSAML\Metadata\MetaDataStorageHandler;
use SimpleSAML\Session;
use SimpleSAML\Store\StoreFactory;
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
    
  }

  public function processSamlResponse(Request $request) {
    
  }

  public function processSamlError(Request $request) {
    
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
    
  }
}
