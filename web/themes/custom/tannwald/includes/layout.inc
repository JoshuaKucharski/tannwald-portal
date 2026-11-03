<?php

/**
 * @file
 * Seitenlayout: Demo-Leiste, Kopfzeile, Hauptnavigation, Brotkrumen.
 */

declare(strict_types=1);

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;
use Drupal\masquerade\Session\MetadataBag;
use Drupal\user\UserInterface;

/**
 * Demo-Konten (Seeder 2.3) für die Wechsel-Links der Demo-Leiste.
 *
 * @return string[]
 *   Kontonamen.
 */
function _tannwald_demo_accounts(): array {
  return ['kunde-a', 'kunde-b', 'haendler', 'techniker', 'redaktion', 'compliance'];
}

/**
 * Rollen-Labels der Demo-Leiste (Vertrag (e) an 5.3), in Prüfreihenfolge.
 *
 * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
 *   Rollen-ID → Label.
 */
function _tannwald_role_labels(): array {
  return [
    'kunde' => t('Customer reader'),
    'haendler' => t('Dealer'),
    'techniker' => t('Technician'),
    'redaktion' => t('Technical editing'),
    'compliance' => t('Compliance'),
    'portal_admin' => t('Portal admin'),
    'administrator' => t('Administrator'),
  ];
}

/**
 * Variablen für page.html.twig.
 *
 * Schlüssel: site_name, brand_mark, nav, language_switcher, account, demo,
 * cache_bubble.
 */
function _tannwald_layout_prepare(array &$variables): void {
  $current = \Drupal::currentUser();
  $hasMasquerade = \Drupal::moduleHandler()->moduleExists('masquerade');
  $variables['site_name'] = (string) (\Drupal::config('system.site')->get('name') ?: 'myTannwald');
  $variables['nav'] = _tannwald_navigation();
  // tw2-12: Bildmarke (Platz für das kommende Logo) und Sprachwechsler.
  $variables['brand_mark'] = _tannwald_brand_mark();
  $variables['language_switcher'] = _tannwald_language_switcher();
  $variables['account'] = NULL;
  $variables['demo'] = NULL;
  $variables['demo_hint'] = $hasMasquerade;
  // Konten-Chips auf der Anmeldeseite: lokal mit masquerade (Split dev), auf
  // dem Demo-Server über $settings['tannwald_demo_login'] (settings.php,
  // Block TANNWALD_DEPLOY). D9 vom 2026-11-03: Site offen, die Demo-Konten
  // mit Passwort demo sind der Zugang für Besucher.
  $showChips = $hasMasquerade || (bool) Settings::get('tannwald_demo_login', FALSE);
  $variables['demo_accounts'] = $showChips ? _tannwald_demo_account_chips() : [];
  // Kopfzeile und Demo-Leiste hängen am Konto und an der Sitzung (masquerade);
  // der Sprachwechsler trägt die Abfrage-Parameter weiter (url.query_args).
  $variables['cache_bubble'] = _tannwald_cache_bubble(
    ['user', 'session', 'url.path', 'url.query_args', 'languages:language_interface'],
    ['config:system.site']
  );

  if ($current->isAuthenticated()) {
    $user = User::load($current->id());
    $company = NULL;
    if ($user instanceof UserInterface && $user->hasField('field_firma') && !$user->get('field_firma')->isEmpty()) {
      $term = $user->get('field_firma')->entity;
      $company = $term ? (string) $term->label() : NULL;
    }
    $display = $user instanceof UserInterface ? (string) $user->getDisplayName() : (string) $current->getDisplayName();
    $variables['account'] = [
      'display_name' => $display,
      'company' => $company,
      'initials' => _tannwald_initials($display),
      'logout' => [
        '#type' => 'link',
        '#title' => t('Log out'),
        '#url' => Url::fromRoute('user.logout'),
        '#attributes' => ['class' => ['text-sm']],
      ],
    ];
    if ($hasMasquerade && $user instanceof UserInterface) {
      $variables['demo'] = _tannwald_demo_bar($user, $company);
    }
  }

  $routeMatch = \Drupal::routeMatch();
  // 1.2: Fehlerseiten 403/404 im eigenen System (page.html.twig zeigt den
  // Leer-Zustand mit Weg zurück; Core-Titel und -Inhalt bleiben aus).
  $variables['error_page'] = _tannwald_error_page($routeMatch->getRouteName());
  if ($variables['error_page'] !== NULL) {
    foreach (['tannwald_page_title', 'tannwald_content', 'tannwald_local_tasks'] as $block) {
      if (isset($variables['page']['content'][$block])) {
        $variables['page']['content'][$block]['#access'] = FALSE;
      }
    }
  }

  // Nutzerseiten (Anmelden, Passwort): kein Seitentitel-Block (das Template
  // zeigt den einen Titel) und keine lokalen Reiter (Leitplanken §5 und §6).
  if (in_array($routeMatch->getRouteName(), _tannwald_user_routes(), TRUE)) {
    foreach (['tannwald_page_title', 'tannwald_local_tasks'] as $block) {
      if (isset($variables['page']['content'][$block])) {
        $variables['page']['content'][$block]['#access'] = FALSE;
      }
    }
  }
  // Seitentitel-Block ausblenden, wo das Template den einen Titel zeigt:
  // Maschinen-, Dokument- und Bulletinseiten (Kopfkarte) und die Suche.
  $node = $routeMatch->getParameter('node');
  $ownTitle = in_array($routeMatch->getRouteName(), ['tannwald_assistant.search', 'tannwald_demo.styleguide'], TRUE)
    || ($routeMatch->getRouteName() === 'entity.node.canonical' && $node instanceof NodeInterface
      && in_array($node->bundle(), ['maschine', 'dokument', 'bulletin'], TRUE));
  if ($ownTitle && isset($variables['page']['content']['tannwald_page_title'])) {
    $variables['page']['content']['tannwald_page_title']['#access'] = FALSE;
  }
}

/**
 * Initialen eines Anzeigenamens („Anna Keller“ → „AK“).
 */
function _tannwald_initials(string $name): string {
  $parts = preg_split('/\s+/u', trim($name)) ?: [];
  $letters = '';
  foreach (array_slice($parts, 0, 2) as $part) {
    $letters .= mb_substr($part, 0, 1);
  }
  return mb_strtoupper($letters !== '' ? $letters : mb_substr($name, 0, 2));
}

/**
 * Hauptnavigation: Meine Maschinen, Bulletins (5.3 hängt Redaktion an).
 *
 * Seit tw2-12 ist die Suche kein Navigationspunkt mehr, sondern das
 * Lupen-Icon rechts in der Kopfzeile (Schlüssel search).
 *
 * @return array{home_url: string, items: array<int, array{label: mixed, url: string, active: bool}>, search: array{url: string, active: bool}}
 *   Navigation.
 */
function _tannwald_navigation(): array {
  $path = \Drupal::service('path.current')->getPath();
  $alias = \Drupal::service('path_alias.manager')->getAliasByPath($path);
  $node = \Drupal::routeMatch()->getParameter('node');
  $bundle = $node instanceof NodeInterface ? $node->bundle() : NULL;

  $items = [
    [
      'label' => t('My machines'),
      'url' => '/meine-maschinen',
      'active' => str_starts_with($alias, '/meine-maschinen') || in_array($bundle, ['maschine', 'dokument'], TRUE),
    ],
    [
      'label' => t('Bulletins'),
      'url' => '/bulletins',
      'active' => str_starts_with($alias, '/bulletins') || $bundle === 'bulletin',
    ],
  ];
  foreach ($items as &$item) {
    $item['url'] = Url::fromUserInput($item['url'])->toString();
  }
  unset($item);
  return [
    'home_url' => Url::fromUserInput('/meine-maschinen')->toString(),
    'items' => $items,
    'search' => [
      'url' => Url::fromUserInput('/suche')->toString(),
      'active' => str_starts_with($alias, '/suche'),
    ],
  ];
}

/**
 * Bildmarke der Kopfzeile (tw2-12): Platz für das kommende Logo.
 *
 * Liegt images/logo.svg oder images/logo.png im Theme, wird es als Bild
 * gezeigt; sonst zeichnet page.html.twig die Blister-Marke der Anmeldeseite
 * als Platzhalter (inline-SVG, currentColor).
 *
 * @return array{src: ?string}
 *   Bildquelle oder NULL für den Platzhalter.
 */
function _tannwald_brand_mark(): array {
  $theme = \Drupal::service('extension.list.theme')->getPath('tannwald');
  foreach (['logo.svg', 'logo.png'] as $file) {
    $path = $theme . '/images/' . $file;
    if (is_file(DRUPAL_ROOT . '/' . $path)) {
      return ['src' => base_path() . $path];
    }
  }
  return ['src' => NULL];
}

/**
 * Sprachwechsler der Oberfläche (tw2-12): DE · EN · FR über den URL-Präfix.
 *
 * Links aus language_manager->getLanguageSwitchLinks() für die aktuelle Route
 * (Url::fromRouteMatch wie der Core-Block; Drupal 11 filtert die Links nach
 * $url->access(), die Route <current> besteht diese Prüfung nie). Auf den
 * Fehlerseiten 403/404 zeigt die Route auf system.40x, darum dort der Pfad
 * der Hauptanfrage ohne Sprachpräfix als unroutete base:-URL mit
 * path_processing (sonst bleibt der Präfix weg). Abfrage-Parameter bleiben.
 * Die Nutzer-Spracheinstellung (preferred_langcode) bleibt die Vorgabe; der
 * Wechsler setzt nur die URL.
 *
 * @return array<int, array{code: string, label: string, url: string, current: bool}>
 *   Sprachen in Reihenfolge der Konfiguration; leer bei nur einer Sprache.
 */
function _tannwald_language_switcher(): array {
  $languageManager = \Drupal::languageManager();
  if (!$languageManager->isMultilingual()) {
    return [];
  }
  $routeMatch = \Drupal::routeMatch();
  if (in_array($routeMatch->getRouteName(), ['system.403', 'system.404'], TRUE)) {
    $main = \Drupal::requestStack()->getMainRequest();
    $path = trim($main ? $main->getPathInfo() : '/', '/');
    $prefixes = (array) \Drupal::config('language.negotiation')->get('url.prefixes');
    foreach ($prefixes as $prefix) {
      if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
        $path = substr($path, strlen($prefix) + 1);
        break;
      }
    }
    $url = Url::fromUri('base:/' . $path, ['path_processing' => TRUE]);
  }
  else {
    $url = Url::fromRouteMatch($routeMatch);
  }
  $links = $languageManager->getLanguageSwitchLinks(LanguageInterface::TYPE_INTERFACE, $url);
  if ($links === NULL || empty($links->links)) {
    return [];
  }
  $current = $languageManager->getCurrentLanguage()->getId();
  // Eigennamen der Sprachen (die Konfigurationslabels sind englisch: „French“).
  $native = ['de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français'];
  $switcher = [];
  foreach ($links->links as $code => $link) {
    $target = $link['url'] instanceof Url ? clone $link['url'] : clone $url;
    $target->setOption('language', $link['language']);
    if (!empty($link['query'])) {
      $target->setOption('query', $link['query']);
    }
    $switcher[] = [
      'code' => (string) $code,
      'label' => $native[$code] ?? (string) $link['title'],
      'url' => $target->toString(),
      'current' => $code === $current,
    ];
  }
  return $switcher;
}

/**
 * Demo-Leiste: Konto, Firma, Rolle, Wechsel-Links über masquerade, Zurück.
 *
 * @return array{account_name: string, company: ?string, role_label: mixed, switch_links: array, back_url: ?string, back_name: ?string}
 *   Daten für page.html.twig.
 */
function _tannwald_demo_bar(UserInterface $user, ?string $company): array {
  // Nur aufgerufen, wenn das Modul masquerade aktiv ist (Split dev).
  /** @var \Drupal\masquerade\Masquerade $masquerade */
  $masquerade = \Drupal::service('masquerade');
  $isMasquerading = $masquerade->isMasquerading();

  $roleLabel = t('Authenticated');
  foreach (_tannwald_role_labels() as $role => $label) {
    if (in_array($role, $user->getRoles(TRUE), TRUE)) {
      $roleLabel = $label;
      break;
    }
  }
  if ((int) $user->id() === 1) {
    $roleLabel = t('Administrator');
  }

  $country = $user->hasField('field_land') && !$user->get('field_land')->isEmpty() ? (string) $user->get('field_land')->value : NULL;
  $companyLine = $company;
  if ($companyLine !== NULL && $country !== NULL) {
    $companyLine .= ', ' . $country;
  }

  $currentPath = \Drupal::service('path.current')->getPath();
  $backUrl = NULL;
  $backName = NULL;
  if ($isMasquerading) {
    $backUrl = Url::fromRoute('masquerade.unmasquerade', [], ['query' => ['destination' => $currentPath]])->toString();
    $bag = \Drupal::request()->getSession()->getMetadataBag();
    $previous = $bag instanceof MetadataBag ? $bag->getMasquerade() : NULL;
    $previousUser = $previous ? User::load($previous) : NULL;
    $backName = $previousUser instanceof UserInterface ? (string) $previousUser->getAccountName() : 'admin';
  }

  $links = [];
  foreach (_tannwald_demo_accounts() as $name) {
    if ($name === $user->getAccountName()) {
      continue;
    }
    $target = current(\Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $name]));
    if (!$target instanceof UserInterface) {
      continue;
    }
    try {
      if ($isMasquerading) {
        // Das Modul erlaubt kein Wechseln aus einer laufenden Maskerade, und
        // der CSRF-Seed wird beim Zurückwechseln erneuert: darum erst zurück
        // (auf dieselbe Seite), dort dann das Zielkonto wählen (zwei Klicks).
        $url = Url::fromRoute('masquerade.unmasquerade', [], ['query' => ['destination' => $currentPath]]);
        $title = t('Switches back to @name first; choose the account there.', ['@name' => $backName]);
      }
      elseif (function_exists('masquerade_target_user_access') && masquerade_target_user_access($target)) {
        $url = Url::fromRoute('entity.user.masquerade', ['user' => $target->id()]);
        $title = t('Masquerade as @name', ['@name' => $name]);
      }
      else {
        continue;
      }
      $links[] = ['label' => $name, 'url' => $url->toString(), 'title' => $title];
    }
    catch (\Exception) {
      continue;
    }
  }

  return [
    'account_name' => (string) $user->getAccountName(),
    'company' => $companyLine,
    'role_label' => $roleLabel,
    'switch_links' => $links,
    'back_url' => $backUrl,
    'back_name' => $backName,
  ];
}

/**
 * Brotkrumen nach Wireframe 01: „Meine Maschinen › TB200-2021-0417“.
 */
function _tannwald_breadcrumb_prepare(array &$variables): void {
  $routeMatch = \Drupal::routeMatch();
  $route = (string) $routeMatch->getRouteName();
  $machines = ['text' => t('My machines'), 'url' => Url::fromUserInput('/meine-maschinen')->toString()];
  $bulletins = ['text' => t('Bulletins'), 'url' => Url::fromUserInput('/bulletins')->toString()];

  // 1.2/1.4: Wurzelseiten (Maschinen, Suche, Bulletins) ohne Brotkrumen — der
  // einzige Eintrag wäre der Seitentitel (Leitplanken v2 §11.1, kein Titel
  // doppelt).
  $rootRoutes = ['view.portal_maschinen.page_1', 'view.portal_bulletins.page_1', 'tannwald_assistant.search'];
  if (in_array($route, $rootRoutes, TRUE)) {
    $variables['breadcrumb'] = [];
    return;
  }
  $node = $routeMatch->getParameter('node');
  if ($route === 'entity.node.canonical' && $node instanceof NodeInterface) {
    switch ($node->bundle()) {
      case 'maschine':
        $variables['breadcrumb'] = [$machines, ['text' => $node->label(), 'url' => '']];
        return;

      case 'dokument':
        $trail = [$machines];
        if ($node->hasField('field_seriennummer') && !$node->get('field_seriennummer')->isEmpty()) {
          $machine = $node->get('field_seriennummer')->entity;
          if ($machine instanceof NodeInterface && $machine->access('view')) {
            $trail[] = [
              'text' => $machine->label(),
              'url' => $machine->toUrl('canonical', ['language' => \Drupal::languageManager()->getCurrentLanguage()])->toString(),
            ];
          }
        }
        $trail[] = ['text' => $node->label(), 'url' => ''];
        $variables['breadcrumb'] = $trail;
        return;

      case 'bulletin':
        $variables['breadcrumb'] = [$bulletins, ['text' => $node->label(), 'url' => '']];
        return;
    }
  }
  // Sonst: Core-Brotkrumen, aber „Startseite“ führt auf „Meine Maschinen“.
  if (!empty($variables['breadcrumb'][0]) && \Drupal::currentUser()->isAuthenticated()) {
    $variables['breadcrumb'][0] = $machines;
  }
}

/**
 * Routen der Nutzerseiten ohne Seitentitel-Block und ohne lokale Reiter.
 *
 * @return string[]
 *   Routennamen.
 */
function _tannwald_user_routes(): array {
  return [
    'user.login',
    'user.pass',
    'user.register',
    'user.reset',
    'user.reset.form',
    'user.reset.login',
  ];
}

/**
 * Demo-Konten als Chips für die Anmeldeseite (nur dev): Name, Firma oder Rolle.
 *
 * @return array<int, array{name: string, label: string}>
 *   Chips in der Reihenfolge der Demo-Konten.
 */
function _tannwald_demo_account_chips(): array {
  $storage = \Drupal::entityTypeManager()->getStorage('user');
  $labels = _tannwald_role_labels();
  $chips = [];
  foreach (_tannwald_demo_accounts() as $name) {
    $account = current($storage->loadByProperties(['name' => $name]));
    if (!$account instanceof UserInterface) {
      continue;
    }
    $label = NULL;
    if ($account->hasField('field_firma') && !$account->get('field_firma')->isEmpty()) {
      $term = $account->get('field_firma')->entity;
      $label = $term ? (string) $term->label() : NULL;
    }
    if ($label === NULL) {
      foreach ($labels as $role => $roleLabel) {
        if (in_array($role, $account->getRoles(TRUE), TRUE)) {
          $label = (string) $roleLabel;
          break;
        }
      }
    }
    // 1.2: zwei Gruppen für das Chip-Raster (Kunden · Intern).
    $chips[] = [
      'name' => $name,
      'label' => $label ?? $name,
      'group' => in_array($name, ['kunde-a', 'kunde-b', 'haendler'], TRUE) ? 'customers' : 'internal',
    ];
  }
  return $chips;
}

/**
 * Texte der Fehlerseiten 403 und 404 (1.2), sonst NULL.
 *
 * @return array{code: int, title: \Drupal\Core\StringTranslation\TranslatableMarkup, text: \Drupal\Core\StringTranslation\TranslatableMarkup, back_url: string, back_label: \Drupal\Core\StringTranslation\TranslatableMarkup}|null
 *   Titel, Satz und Weg zurück.
 */
function _tannwald_error_page(?string $route): ?array {
  $authenticated = \Drupal::currentUser()->isAuthenticated();
  $back = $authenticated
    ? ['url' => Url::fromUserInput('/meine-maschinen')->toString(), 'label' => t('To my machines')]
    : ['url' => Url::fromRoute('user.login')->toString(), 'label' => t('Log in')];
  return match ($route) {
    'system.403' => [
      'code' => 403,
      'title' => t('No access'),
      'text' => $authenticated
        ? t('This page belongs to another company or your role does not cover it. If you think you should see it, contact your Tannwald contact.')
        : t('Please log in to see this page.'),
      'back_url' => $back['url'],
      'back_label' => $back['label'],
    ],
    'system.404' => [
      'code' => 404,
      'title' => t('Page not found'),
      'text' => t('The address does not exist or the document has been withdrawn. Use the search or go back to your machines.'),
      'back_url' => $back['url'],
      'back_label' => $back['label'],
    ],
    default => NULL,
  };
}
