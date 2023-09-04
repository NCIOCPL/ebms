<?php

namespace Drupal\ebms_core\EventSubscriber;

use Drupal\Core\Session\AccountProxy;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Event subscriber subscribing to KernelEvents::REQUEST.
 */
class RedirectAnonymousUser implements EventSubscriberInterface {

  /**
   * End points we don't want to redirect.
   */
  const SKIP = [
    '/user/login',
    '/login',
    '/ssologin',
    '/articles/import/dates',
    '/articles/import/refresh',
  ];

  /**
   * The user requesting the page.
   *
   * @var AccountProxy
   */
  protected AccountProxy $currentUser;

  /**
   * Initialize the property for the current user.
   *
   * @param AccountProxy $current_user
   */
  public function __construct(AccountProxy $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [KernelEvents::REQUEST => 'checkLoginStatus'];
  }

  /**
   * Send the user to the login page if appropriate.
   */
  public function checkLoginStatus(RequestEvent $event) {
    $current_path = $event->getRequest()->getPathInfo();
    if ($this->currentUser->isAnonymous() && !in_array($current_path, self::SKIP)) {
      ebms_debug_log('redirecting to /login');
      $response = new RedirectResponse('/login');
      $response->send();
    }
  }

}
