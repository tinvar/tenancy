<?php

declare(strict_types=1);

namespace Tinvar\Tenancy\SelfService;

use Tinvar\Console\Kits\AppKit;
use Tinvar\Console\Kits\KitTarget;
use Tinvar\Generators\FileSet;
use Tinvar\Generators\RegistrationRefused;
use Tinvar\Generators\RouteTable;

/**
 * The tenant self-service pages, written into an application once (ADR
 * 0161, ADR 0157): `bin/tinvar kit:install tinvar/tenancy`, or `tinvar new
 * --tenancy=subdomain --kit=tinvar/tenancy`, which runs it.
 *
 * It writes the application's own classes -- one per page, each a few lines
 * declaring where the page is, extending the package's -- the policy, the
 * views, and a migration for the kit's tables, and registers the views in
 * the route table. Discovery registers the classes (ADR 0146); the Kernel
 * wiring of the services, TenantSelfService, is the one step it leaves to
 * the developer, as AccountsKit's is, and says so.
 *
 * Nothing is written over: a file already there refuses the whole install,
 * naming it, and nothing is written.
 */
final class TenantPagesKit implements AppKit
{
    /** The views it writes, by name, each drawn inside the `app` layout. */
    public const array VIEWS = ['tenancy/switch', 'tenancy/domains', 'tenancy/settings', 'tenancy/invitation'];

    public const string MIGRATION = '2026_11_03_000001_create_tenant_self_service.php';

    public function install(KitTarget $app): array
    {
        $ns = $app->namespace;
        $files = new FileSet();
        foreach (self::classes($ns) as $relative => $source) {
            $files->add("{$app->root}/app/{$relative}", $source);
        }
        $resources = \dirname(__DIR__, 3) . '/resources';
        foreach (['pages/tenancy/switch.tsx', 'pages/tenancy/domains.tsx', 'pages/tenancy/settings.tsx', 'pages/tenancy/invitation.tsx', 'components/tenancy/TenancyForms.tsx', 'components/tenancy/types.ts'] as $view) {
            $files->add("{$app->root}/resources/{$view}", (string) file_get_contents("{$resources}/{$view}"));
        }
        $files->add("{$app->migrations}/" . self::MIGRATION, self::migration($ns));
        $files->write(static fn (string $path): string => str_starts_with($path, $app->root . '/') ? substr($path, \strlen($app->root) + 1) : $path);

        $steps = [];
        $table = "{$app->root}/resources/entries/rsc.tsx";
        foreach (self::VIEWS as $view) {
            try {
                RouteTable::add($table, $view, '../pages/' . $view, ['app']);
            } catch (RegistrationRefused $e) {
                $steps[] = "Add '{$view}' to ROUTES in resources/entries/rsc.tsx by hand: " . $e->getMessage() . '.';
            }
        }

        return [
            ...$steps,
            "Wire the services in app/Kernel.php: build a Tinvar\\Tenancy\\SelfService\\TenantSelfService and register() it in the container, and give the HTTP Kernel its memberships and a PanelRegistry with the 'customer' panel -- as the tenant self-service docs page (/docs/tenant-self-service) shows.",
            "{$app->console} migrate",
            'Set TINVAR_CUSTOM_DOMAIN_TARGETS to the host name tenants point their domains at before you deploy: production refuses to start without it.',
        ];
    }

    /**
     * The application's classes, by path under app/: the pages, the status
     * query and the policy.
     *
     * @return array<string, string>
     */
    public static function classes(string $ns): array
    {
        $page = static fn (string $class, string $base, string $attributes, string $doc, string $body = ''): string => <<<PHP
            <?php

            declare(strict_types=1);

            namespace {$ns}\\Http\\Pages\\Tenancy;

            use Tinvar\\Attributes\\Domain;
            use Tinvar\\Attributes\\Page;
            use Tinvar\\Attributes\\Panel;
            use Tinvar\\Attributes\\TenantFrom;
            use Tinvar\\Tenancy\\Host;
            use Tinvar\\Tenancy\\SelfService\\Http\\{$base};

            /** {$doc} */
            {$attributes}
            final class {$class} extends {$base}
            {{$body}}

            PHP;

        return [
            'Http/Pages/Tenancy/Organizations.php' => $page(
                'Organizations',
                'TenantSwitchPage',
                "#[Page('/organizations', view: 'tenancy/switch', name: 'tenancy.switch'), Domain('{tenant}.{root}'), TenantFrom(Host::class), Panel('customer')]",
                'The organizations the signed-in user belongs to, and the switch between them (ADR 0161).',
            ),
            'Http/Pages/Tenancy/OrganizationDomains.php' => $page(
                'OrganizationDomains',
                'CustomDomainsPage',
                "#[Page('/settings/domains', view: 'tenancy/domains', name: 'tenancy.domains'), Domain('{tenant}.{root}'), TenantFrom(Host::class), Panel('customer')]",
                'The organization\'s custom domains: added, proved, checked and removed by its admins (ADR 0161).',
            ),
            'Http/Pages/Tenancy/OrganizationSettings.php' => $page(
                'OrganizationSettings',
                'TenantSettingsPage',
                "#[Page('/settings', view: 'tenancy/settings', name: 'tenancy.settings'), Domain('{tenant}.{root}'), TenantFrom(Host::class), Panel('customer')]",
                'The organization\'s name, address, members, roles and invites (ADR 0161).',
            ),
            'Http/Pages/Tenancy/OrganizationInvitation.php' => $page(
                'OrganizationInvitation',
                'AcceptInvitationPage',
                "#[Page('/invite', view: 'tenancy/invitation', name: 'tenancy.invitation'), Domain('{tenant}.{root}'), TenantFrom(Host::class)]",
                'Where an invite\'s emailed link lands: in no panel, since whoever opens it is no member yet (ADR 0161).',
            ),
            'Policies/Tenancy/TenantAdministrationRules.php' => <<<PHP
                <?php

                declare(strict_types=1);

                namespace {$ns}\\Policies\\Tenancy;

                use Tinvar\\Attributes\\Policy;
                use Tinvar\\Tenancy\\SelfService\\TenantAdministration;
                use Tinvar\\Tenancy\\SelfService\\TenantAdministrationPolicy;

                /**
                 * Who may do what on the organization pages: the package's defaults --
                 * a member sees, a role carrying tenant.settings, tenant.members or
                 * tenant.domains changes. Override an ability to answer it your way.
                 */
                #[Policy(TenantAdministration::class)]
                final class TenantAdministrationRules extends TenantAdministrationPolicy {}

                PHP,
        ];
    }

    private static function migration(string $ns): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use {$ns}\\Database\\Models\\Account\\User;
            use Tinvar\\Orm\\Migrations\\Migration;
            use Tinvar\\Orm\\Schema\\Schema;
            use Tinvar\\Orm\\Schema\\Table;
            use Tinvar\\Tenancy\\SelfService\\DatabaseTenantMembers;
            use Tinvar\\Tenancy\\SelfService\\DatabaseTenants;
            use Tinvar\\Tenancy\\SelfService\\TenantDomains;

            /**
             * The tenant self-service pages' tables (ADR 0161), written by
             * `kit:install tinvar/tenancy`: the members and their roles, and the
             * custom domains. The tenants table `tinvar new` made gains the name
             * and parent the settings page and the tenant tree read, and a slug
             * history beside it; an app without one gets the kit's.
             */
            return new class extends Migration {
                public function up(Schema \$schema): void
                {
                    if (\$schema->hasTable(DatabaseTenants::TABLE)) {
                        \$schema->table(DatabaseTenants::TABLE, static function (Table \$table): void {
                            \$table->string('name', 100)->default('');
                            \$table->string('parent_id', 191)->nullable();
                        });
                        DatabaseTenants::defineSlugs(\$schema);
                    } else {
                        DatabaseTenants::define(\$schema);
                    }
                    DatabaseTenantMembers::define(\$schema, User::class);
                    TenantDomains::define(\$schema);
                }

                public function down(Schema \$schema): void
                {
                    \$schema->drop(TenantDomains::TABLE);
                    \$schema->drop(DatabaseTenantMembers::TABLE);
                    \$schema->drop(DatabaseTenants::SLUGS_TABLE);
                }
            };

            PHP;
    }
}
