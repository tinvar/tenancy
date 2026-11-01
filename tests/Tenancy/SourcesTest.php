<?php

declare(strict_types=1);

/**
 * The two tenant sources the spec names, Host and Signature, and what they
 * refuse to place.
 */

namespace Tinvar\Tests\Tenancy\Sources;

use Tinvar\Http\Request;
use Tinvar\Support\Signer;
use Tinvar\Tenancy\Host;
use Tinvar\Tenancy\Signature;
use Tinvar\Tenancy\SignedUrls;
use Tinvar\Tenancy\Subdomains;
use Tinvar\Tenancy\TenantHosts;

function hostRequest(string $host): Request
{
    return new Request('GET', '/', ['host' => $host]);
}

/** @return array<string, string> */
function queryOf(string $url): array
{
    parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

    /** @var array<string, string> $query */
    return $query;
}

describe('a host', function () {
    it('names the tenant the application maps it to, and nothing for one it does not', function () {
        $hosts = new class implements TenantHosts {
            /** @var list<string> */
            public array $asked = [];

            public function tenantForHost(string $host): ?string
            {
                $this->asked[] = $host;

                return $host === 'acme.test' ? 'acme' : null;
            }
        };
        $source = new Host($hosts);

        expect($source->tenantFor(hostRequest('Acme.Test:80')))->toBe('acme')
            ->and($source->tenantFor(hostRequest('globex.test')))->toBeNull()
            // Never asked about something that is not a host name.
            ->and($source->tenantFor(hostRequest('10.0.0.1')))->toBeNull()
            ->and($source->tenantFor(new Request('GET', '/')))->toBeNull()
            ->and($hosts->asked)->toBe(['acme.test', 'globex.test']);
    });

    // An empty tenant would be no tenant -- every #[TenantScoped] read
    // failing closed -- dressed up as a resolved one.
    it('treats an empty answer as none', function () {
        $source = new Host(new class implements TenantHosts {
            public function tenantForHost(string $host): ?string
            {
                return '';
            }
        });

        expect($source->tenantFor(hostRequest('acme.test')))->toBeNull();
    });

    // Where the application routes by host, the host it resolved is the
    // one answer: none resolved is no tenant, whatever the Host header says.
    it('reads the resolved host alone where there is one', function () {
        $source = new Host(new class implements TenantHosts {
            public function tenantForHost(string $host): ?string
            {
                return $host === 'acme.test' ? 'acme' : null;
            }
        });
        $request = hostRequest('acme.test');

        expect($source->tenantFor($request->withAttribute(\Tinvar\Routing\HostMatch::ATTRIBUTE, new \Tinvar\Routing\HostMatch(null, ''))))->toBeNull()
            ->and($source->tenantFor($request))->toBe('acme');
    });

    // X-Forwarded-Host is whatever the client sent.
    it('ignores a forwarded host', function () {
        $source = new Host(new class implements TenantHosts {
            public function tenantForHost(string $host): ?string
            {
                return $host === 'acme.test' ? 'acme' : null;
            }
        });

        expect($source->tenantFor(new Request('GET', '/', ['host' => 'globex.test', 'x-forwarded-host' => 'acme.test'])))->toBeNull();
    });
});

describe('subdomains', function () {
    $subdomains = static fn (): Subdomains => new Subdomains(
        ['shop.test', 'Shop.Example'],
        static fn (string $label): ?string => \in_array($label, ['acme', 'globex'], true) ? "tenant-{$label}" : null,
    );

    it('names the tenant one label under a platform domain belongs to', function () use ($subdomains) {
        expect($subdomains()->tenantForHost('acme.shop.test'))->toBe('tenant-acme')
            ->and($subdomains()->tenantForHost('globex.shop.example'))->toBe('tenant-globex');
    });

    // One level, as a wildcard certificate covers: x.acme.shop.test is a name
    // acme never chose.
    it('refuses a deeper name, the bare domain, an unknown label and another domain', function () use ($subdomains) {
        expect($subdomains()->tenantForHost('x.acme.shop.test'))->toBeNull()
            ->and($subdomains()->tenantForHost('shop.test'))->toBeNull()
            ->and($subdomains()->tenantForHost('initech.shop.test'))->toBeNull()
            ->and($subdomains()->tenantForHost('acme.shop.test.evil.test'))->toBeNull()
            ->and($subdomains()->tenantForHost('acmeshop.test'))->toBeNull();
    });

    // One platform domain under another: a host under the inner one is the
    // inner one's, in whichever order they are listed, and the inner domain
    // itself is the platform's -- never the tenant whose slug is its label.
    it('reads a host against the most specific platform domain, and gives no platform domain to a tenant', function () {
        $lookup = static fn (string $label): ?string => \in_array($label, ['acme', 'eu'], true) ? "tenant-{$label}" : null;

        foreach ([['shop.test', 'eu.shop.test'], ['eu.shop.test', 'shop.test']] as $domains) {
            $subdomains = new Subdomains($domains, $lookup);

            expect($subdomains->tenantForHost('acme.eu.shop.test'))->toBe('tenant-acme')
                ->and($subdomains->tenantForHost('acme.shop.test'))->toBe('tenant-acme')
                ->and($subdomains->tenantForHost('eu.shop.test'))->toBeNull()
                ->and($subdomains->tenantForHost('shop.test'))->toBeNull();
        }
    });

    it('refuses a platform domain that is not one', function () {
        expect(fn () => new Subdomains(['not a domain'], static fn (string $l): ?string => $l))
            ->toThrow(\InvalidArgumentException::class);
    });
});

describe('a signed URL', function () {
    $urls = static fn (string $key = 'app-key'): SignedUrls => new SignedUrls(new Signer($key, SignedUrls::PURPOSE));
    $request = static fn (string $url): Request => new Request('GET', (string) parse_url($url, PHP_URL_PATH), [], queryOf($url));

    it('names its tenant to Signature', function () use ($urls, $request) {
        $url = $urls()->sign('/exports/42', 'acme', ['format' => 'csv']);

        expect((new Signature($urls()))->tenantFor($request($url)))->toBe('acme')
            ->and(queryOf($url))->toHaveKeys(['format', 'tenant', 'expires', 'signature']);
    });

    // Signed over the path and the whole query, by name: the order a client
    // sends them in does not matter, and changing any of them is a forgery.
    it('verifies whatever order its parameters arrive in', function () use ($urls) {
        $url = $urls()->sign('/exports/42', 'acme', ['format' => 'csv', 'a' => '1']);
        $query = array_reverse(queryOf($url), preserve_keys: true);

        expect($urls()->tenantOf(new Request('GET', '/exports/42', [], $query)))->toBe('acme');
    });

    it('refuses one with its tenant, another parameter, an added parameter or its path changed', function () use ($urls) {
        $url = $urls()->sign('/exports/42', 'acme', ['format' => 'csv']);
        $query = queryOf($url);

        expect($urls()->tenantOf(new Request('GET', '/exports/42', [], [...$query, 'tenant' => 'globex'])))->toBeNull()
            ->and($urls()->tenantOf(new Request('GET', '/exports/42', [], [...$query, 'format' => 'xlsx'])))->toBeNull()
            ->and($urls()->tenantOf(new Request('GET', '/exports/42', [], [...$query, 'extra' => '1'])))->toBeNull()
            ->and($urls()->tenantOf(new Request('GET', '/exports/43', [], $query)))->toBeNull();
    });

    // Every token is its host's (ADR 0149). A signed URL names its tenant
    // with no session behind it, so the host it was signed for is signed
    // with it: acme's link opens nothing on globex's subdomain.
    it('is its host\'s where the application routes by host', function () use ($urls) {
        $on = static fn (string $host, array $query): Request => new Request('GET', '/exports/42', ['host' => $host], $query)
            ->withAttribute(\Tinvar\Routing\HostMatch::ATTRIBUTE, new \Tinvar\Routing\HostMatch('{account}.{root}', $host, ['account' => explode('.', $host)[0]]));
        $query = queryOf($urls()->sign('/exports/42', 'acme', ['format' => 'csv'], host: 'ACME.shop.test.'));

        expect($urls()->tenantOf($on('acme.shop.test', $query)))->toBe('acme')
            ->and($urls()->tenantOf($on('globex.shop.test', $query)))->toBeNull()
            // Signed for a host, and read where no host is routed: not this one.
            ->and($urls()->tenantOf(new Request('GET', '/exports/42', [], $query)))->toBeNull();
    });

    // The migration: a URL signed before the host was, naming none, still
    // opens where the application routes on no host, and nowhere it does.
    it('refuses one signed for no host where the application routes by host, and takes it where it does not', function () use ($urls) {
        $query = queryOf($urls()->sign('/exports/42', 'acme'));
        $routed = new Request('GET', '/exports/42', ['host' => 'shop.test'], $query)
            ->withAttribute(\Tinvar\Routing\HostMatch::ATTRIBUTE, new \Tinvar\Routing\HostMatch(null, 'shop.test'));

        expect($urls()->tenantOf($routed))->toBeNull()
            ->and($urls()->tenantOf(new Request('GET', '/exports/42', ['host' => 'shop.test'], $query)))->toBe('acme');
    });

    it('refuses to sign for what is not a host', function () use ($urls) {
        expect(fn () => $urls()->sign('/exports/42', 'acme', host: 'shop.test/evil'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $urls()->sign('/exports/42', 'acme', host: 'shop.test:8443'))->toThrow(\InvalidArgumentException::class);
    });

    it('refuses one past its expiry, and one whose expiry was moved', function () use ($urls) {
        $url = $urls()->sign('/exports/42', 'acme', ttl: 60, now: 1_000_000);
        $query = queryOf($url);
        $request = new Request('GET', '/exports/42', [], $query);

        expect($urls()->tenantOf($request, now: 1_000_059))->toBe('acme')
            ->and($urls()->tenantOf($request, now: 1_000_060))->toBeNull()
            ->and($urls()->tenantOf(new Request('GET', '/exports/42', [], [...$query, 'expires' => '9999999999']), now: 1_000_000))->toBeNull();
    });

    it('refuses one signed with another key, or for another purpose', function () use ($urls) {
        $url = $urls('other-key')->sign('/exports/42', 'acme');
        $forged = (new SignedUrls(new Signer('app-key', 'fragment-token')))->sign('/exports/42', 'acme');

        expect($urls()->tenantOf(new Request('GET', '/exports/42', [], queryOf($url))))->toBeNull()
            ->and($urls()->tenantOf(new Request('GET', '/exports/42', [], queryOf($forged))))->toBeNull();
    });

    it('refuses one carrying no signature, or one in another spelling', function () use ($urls) {
        $query = queryOf($urls()->sign('/exports/42', 'acme'));

        expect($urls()->tenantOf(new Request('GET', '/exports/42', [], array_diff_key($query, ['signature' => true]))))->toBeNull()
            ->and($urls()->tenantOf(new Request('GET', '/exports/42', [], [...$query, 'signature' => $query['signature'] . '='])))->toBeNull()
            ->and($urls()->tenantOf(new Request('GET', '/exports/42', [], [...$query, 'expires' => '0' . $query['expires']])))->toBeNull();
    });

    it('refuses to sign what it could not verify', function () use ($urls) {
        expect(fn () => $urls()->sign('/x', ''))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $urls()->sign('relative', 'acme'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $urls()->sign('/x?y=1', 'acme'))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $urls()->sign('/x', 'acme', ttl: 0))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $urls()->sign('/x', 'acme', ['tenant' => 'globex']))->toThrow(\InvalidArgumentException::class)
            ->and(fn () => $urls()->sign('/x', 'acme', ['a.b' => '1']))->toThrow(\InvalidArgumentException::class);
    });
});
