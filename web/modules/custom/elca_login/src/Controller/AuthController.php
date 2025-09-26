<?php

namespace Drupal\elca_login\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Session\SessionManagerInterface;
use Drupal\Core\Password\PasswordInterface;
use Drupal\user\UserInterface;

class AuthController extends ControllerBase {

  /** @var \Drupal\Core\Session\SessionManagerInterface */
  protected $sessionManager;

  /** @var \Drupal\Core\Password\PasswordInterface */
  protected $passwordService;

  public function __construct(SessionManagerInterface $session_manager, PasswordInterface $password_service) {
    $this->sessionManager = $session_manager;
    $this->passwordService = $password_service;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('session_manager'),
      $container->get('password')
    );
  }

  /**
   * POST /api/login
   * Body: { "name": string, "pass": string }
   */
public function login(Request $request) {
  $response_headers = [
    'Access-Control-Allow-Origin' => '*',
    'Access-Control-Allow-Credentials' => 'true',
    'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-CSRF-Token',
    'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
  ];

  $content = $request->getContent();
  $data = json_decode($content, TRUE) ?: [];
  $email = $data['email'] ?? ''; 
  $password = $data['pass'] ?? '';

  if ($email === '' || $password === '') {
    return new JsonResponse(['error' => 'Missing credentials'], 400, $response_headers);
  }

  // Load user by email
  $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail' => $email]);
  $account = !empty($users) ? reset($users) : NULL;

  if (!$account instanceof UserInterface) {
    return new JsonResponse(['error' => 'Invalid credentials'], 401, $response_headers);
  }

  if (!$this->passwordService->check($password, $account->getPassword())) {
    return new JsonResponse(['error' => 'Invalid credentials'], 401, $response_headers);
  }

  user_login_finalize($account);

  $csrf_token = \Drupal::service('csrf_token')->get('rest');

  return new JsonResponse([
    'message' => 'Login successful',
    'current_user' => [
      'uid' => (int) $account->id(),
      'name' => $account->getAccountName(),
      'roles' => $account->getRoles(),
    ],
    'token' => $csrf_token,
  ], 200, $response_headers);
}



  /**
   * GET /api/me
   * Returns the current authenticated user or anonymous.
   */
  public function me() {
    $account = $this->currentUser();
    if ($account->isAuthenticated()) {
      $user = \Drupal\user\Entity\User::load($account->id());
      return new JsonResponse([
        'authenticated' => TRUE,
        'current_user' => [
          'uid' => (int) $user->id(),
          'name' => $user->getAccountName(),
          'roles' => $user->getRoles(),
        ],
      ]);
    }
    return new JsonResponse(['authenticated' => FALSE]);
  }

}



