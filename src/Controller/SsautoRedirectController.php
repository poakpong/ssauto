<?php

declare(strict_types=1);

namespace Drupal\ssauto\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Issues 301 redirects from legacy /search URLs to /smart-search.
 */
final class SsautoRedirectController extends ControllerBase {

  /**
   * Redirects /search?q={q} → /smart-search?q={q}.
   */
  public function redirect(Request $request): RedirectResponse {
    $q = (string) $request->query->get('q', '');
    $destination = '/smart-search' . ($q !== '' ? '?q=' . rawurlencode($q) : '');
    return new RedirectResponse($destination, 301);
  }

  /**
   * Redirects /search/{keys} → /smart-search?q={keys}.
   */
  public function redirectWithKeys(string $keys = ''): RedirectResponse {
    $destination = '/smart-search' . ($keys !== '' ? '?q=' . rawurlencode($keys) : '');
    return new RedirectResponse($destination, 301);
  }

}
