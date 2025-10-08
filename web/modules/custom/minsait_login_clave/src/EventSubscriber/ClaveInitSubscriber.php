<?php

namespace Drupal\minsait_login_clave\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class ClaveInitSubscriber implements EventSubscriberInterface {

  protected $configFactory;
  protected $currentUser;
  protected $requestStack;

  public function __construct(ConfigFactoryInterface $config_factory, AccountProxyInterface $current_user, RequestStack $request_stack) {
    $this->configFactory = $config_factory;
    $this->currentUser = $current_user;
    $this->requestStack = $request_stack;
  }

  public function onKernelRequest(RequestEvent $event) {
    $request = $this->requestStack->getCurrentRequest();

    // Evita peticiones AJAX.
    if ($request->isXmlHttpRequest()) {
      return;
    }

    // Evita rutas específicas.
    $current_route = \Drupal::routeMatch()->getRouteName();
    $excluded_routes = [
      'minsait_login_clave.clave_callback',
      'minsait_login_clave.clave_login',
    ];
    if (in_array($current_route, $excluded_routes)) {
      return;
    }

    $config = $this->configFactory->get('minsait_login_clave.settings');
    if ($config->get('enable_clave')) {
      $cookie = $request->cookies->get('minsait_login_clave');
      if ($cookie) {
        $user_id = json_decode($cookie, true);
        if ($this->currentUser->id() !== $user_id){
            // Elimina la cookie SimpleSAML
            setcookie('minsait_login_clave', '', time() - 3600, '/');
            // Realiza el logout
            \Drupal::service('minsait_login_clave.clave_logout')->logout();
        }
      }
    }
  }

  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['onKernelRequest'],
    ];
  }
}
