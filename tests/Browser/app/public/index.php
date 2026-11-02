<?php

declare(strict_types=1);

/**
 * The tenancy browser fixture's front controller, for php -S: the build's
 * assets, the fixture's sign-in, and every other request through the
 * application (../Fixture.php), built afresh for it.
 */

use Tinvar\Http\BuiltAssets;
use Tinvar\Http\NotFoundException;
use Tinvar\Http\Response;
use Tinvar\Http\Sapi;
use Tinvar\Tests\Tenancy\Browser\SelfServiceApp;

use const Tinvar\Tests\Tenancy\Browser\SESSION_COOKIE;
use const Tinvar\Tests\Tenancy\Browser\USER_COOKIE;

require \dirname(__DIR__, 6) . '/vendor/autoload.php';
require_once \dirname(__DIR__) . '/Fixture.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');

$assets = new BuiltAssets(\dirname(__DIR__) . '/dist')->match($_SERVER);
if ($assets !== null) {
    Sapi::send($assets);

    return;
}

// Fixture only: signs the browser in as whoever `as` names, in a session of
// its own, on this host, and sends it on to `to`.
$path = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
if ($path === '/_e2e/sign-in') {
    $as = \is_string($_GET['as'] ?? null) ? $_GET['as'] : '';
    $to = \is_string($_GET['to'] ?? null) && str_starts_with($_GET['to'], '/') && !str_starts_with($_GET['to'], '//') ? $_GET['to'] : '/settings';
    setcookie(USER_COOKIE, $as, ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    setcookie(SESSION_COOKIE, bin2hex(random_bytes(16)), ['path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    header('Location: ' . $to, true, 303);

    return;
}

try {
    $app = SelfServiceApp::fromEnvironment();
    $request = Sapi::request($_SERVER, $_GET, $_POST, $_COOKIE, $_FILES);
} catch (NotFoundException) {
    Sapi::send(Response::make(404, 'Not found', ['content-type' => 'text/plain']));

    return;
} catch (\Throwable $e) {
    error_log('[tenancy-e2e] could not start: ' . $e::class . ': ' . $e->getMessage());
    Sapi::send(Response::make(500, 'Service unavailable.', ['content-type' => 'text/plain']));

    return;
}

Sapi::send($app->handle($request, (string) file_get_contents('php://input')));
