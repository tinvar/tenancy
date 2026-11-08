<?php

declare(strict_types=1);

/**
 * The tenant pages kit (ADR 0161), as `kit:install tinvar/tenancy` and
 * `tinvar new --kit=tinvar/tenancy` run it: the application's page classes,
 * policy, views and migration written once, the views registered in its
 * route table, and nothing written over.
 */

namespace Tinvar\Tests\Tenancy\SelfService\Kit;

use Tinvar\Console\Kits\KitDeclaration;
use Tinvar\Console\Kits\KitTarget;
use Tinvar\Discovery\Classifier;
use Tinvar\Generators\GenerationRefused;
use Tinvar\Tenancy\SelfService\TenantPagesKit;

const TABLE = <<<'TSX'
    import HomePage from '../pages/app/home';

    type Page = (props: { data: unknown }) => unknown;

    const ROUTES: Record<string, { Page: Page; layouts: readonly 'app'[] }> = {
      'app/home': { Page: HomePage as Page, layouts: ['app'] },
    };

    export default ROUTES;

    TSX;

/** An application as `tinvar new` leaves one, with its route table. */
function app(): KitTarget
{
    $root = sys_get_temp_dir() . '/tinvar-kit-' . bin2hex(random_bytes(5));
    mkdir("{$root}/resources/entries", 0o777, true);
    mkdir("{$root}/database/migrations", 0o777, true);
    file_put_contents("{$root}/resources/entries/rsc.tsx", TABLE);

    return new KitTarget($root, 'KitApp' . bin2hex(random_bytes(3)), 'bin/tinvar', "{$root}/database/migrations");
}

it('is declared in the package\'s composer.json, as tinvar new finds kits', function () {
    $manifest = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(KitDeclaration::of($manifest))->toEqual(new KitDeclaration('tinvar/tenancy', 'tenant-pages', $manifest['extra']['tinvar']['kit']['title'], TenantPagesKit::class));
});

it('writes the pages, the policy, the views and the migration, and registers the views', function () {
    $app = app();
    $steps = new TenantPagesKit()->install($app);

    $written = [
        'app/Http/Pages/Tenancy/Organizations.php',
        'app/Http/Pages/Tenancy/OrganizationDomains.php',
        'app/Http/Pages/Tenancy/OrganizationSettings.php',
        'app/Http/Pages/Tenancy/OrganizationInvitation.php',
        'app/Policies/Tenancy/TenantAdministrationRules.php',
        'resources/pages/tenancy/switch.tsx',
        'resources/pages/tenancy/domains.tsx',
        'resources/pages/tenancy/settings.tsx',
        'resources/pages/tenancy/invitation.tsx',
        'resources/components/tenancy/TenancyForms.tsx',
        'resources/components/tenancy/types.ts',
        'database/migrations/' . TenantPagesKit::MIGRATION,
    ];
    foreach ($written as $file) {
        expect(is_file("{$app->root}/{$file}"))->toBeTrue($file);
    }

    $table = (string) file_get_contents("{$app->root}/resources/entries/rsc.tsx");
    expect($table)->toContain("'tenancy/domains': { Page: ")
        ->and($table)->toContain("from '../pages/tenancy/settings'")
        ->and(implode("\n", $steps))->toContain('TenantSelfService')->toContain('bin/tinvar migrate')->toContain('ReverifyTenantDomains');
});

it('writes classes that load, and that discovery registers as pages and actions with their policy', function () {
    $app = app();
    new TenantPagesKit()->install($app);

    foreach (['Http/Pages/Tenancy/Organizations', 'Http/Pages/Tenancy/OrganizationDomains', 'Http/Pages/Tenancy/OrganizationSettings', 'Http/Pages/Tenancy/OrganizationInvitation', 'Policies/Tenancy/TenantAdministrationRules'] as $file) {
        require_once "{$app->root}/app/{$file}.php";
    }
    $domains = new \ReflectionClass("{$app->namespace}\\Http\\Pages\\Tenancy\\OrganizationDomains");
    $policy = new \ReflectionClass("{$app->namespace}\\Policies\\Tenancy\\TenantAdministrationRules");

    expect(Classifier::classify($domains)[0])->toBe(['routes', 'actions'])
        ->and(Classifier::classify($policy))->toBe([['policies'], [\Tinvar\Tenancy\SelfService\TenantAdministration::class]]);
});

// The kit writes pages for a tenant per host; an application serving every
// tenant on one host is told, in the class and the steps, what its invite
// page declares instead (ADR 0182).
it('says what the invitation page declares where every tenant is served on one host', function () {
    $app = app();
    $steps = implode("\n", new TenantPagesKit()->install($app));
    $invitation = (string) file_get_contents("{$app->root}/app/Http/Pages/Tenancy/OrganizationInvitation.php");

    expect($invitation)->toContain("#[Page('/invite', view: 'tenancy/invitation', name: 'tenancy.invitation'), Domain('{tenant}.{root}'), TenantFrom(Host::class)]")
        ->and($invitation)->toContain('one host')
        ->and($invitation)->toContain("#[Page('/invite', view: 'tenancy/invitation', name: 'tenancy.invitation')]\n")
        ->and($steps)->toContain('one host')->toContain('OrganizationInvitation');
});

it('writes nothing over a file that is there, and nothing at all then', function () {
    $app = app();
    mkdir("{$app->root}/app/Http/Pages/Tenancy", 0o777, true);
    file_put_contents("{$app->root}/app/Http/Pages/Tenancy/OrganizationSettings.php", '<?php // mine');

    expect(static fn () => new TenantPagesKit()->install($app))->toThrow(GenerationRefused::class)
        ->and(is_file("{$app->root}/app/Http/Pages/Tenancy/Organizations.php"))->toBeFalse()
        ->and((string) file_get_contents("{$app->root}/app/Http/Pages/Tenancy/OrganizationSettings.php"))->toBe('<?php // mine');
});
