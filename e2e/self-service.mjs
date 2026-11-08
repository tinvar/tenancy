// Drives the tenant self-service pages' shipped views (ADR 0161) in
// Chromium, against the browser fixture application, and prints what
// happened as one JSON object.
//
//   node packages/tenancy/e2e/self-service.mjs <port> <dns.json> <invites.jsonl>
//
// Run by packages/tenancy/tests/Browser/SelfServiceBrowserTest.php, which
// serves the fixture on {tenant}.localhost:<port>. The fixture signs a
// browser in by /_e2e/sign-in, reads DNS from <dns.json> -- which this
// script writes, as a tenant would create the records -- and appends each
// invite's link to <invites.jsonl>.
import { readFileSync, writeFileSync } from 'node:fs';
import { chromium } from 'playwright';

const [port, dnsFile, invitesFile] = process.argv.slice(2);
if (!port || !dnsFile || !invitesFile) {
  console.error('Usage: node e2e/self-service.mjs <port> <dns.json> <invites.jsonl>');
  process.exit(64);
}

const origin = (tenant) => `http://${tenant}.localhost:${port}`;
const handoff = `${origin('globex')}/_handoff/start?return=%2F`;
const report = { errors: [], responses: [] };
const browser = await chromium.launch();
/** The page being driven, whose text a failure reports. */
let current = null;
/** The console's errors, and the URLs that answered with the protocol's redirect. */
const logged = [];
const redirected = new Set();

/** A fresh browser signed in as $as on $tenant's host, on $path, hydrated. */
async function visit(tenant, as, path) {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  current = page;
  page.on('response', (r) => {
    // An action's redirect, as the protocol answers one: 409 and X-Redirect
    // (or X-Location, to another host),
    // which Chromium logs as a failed load.
    if (r.status() === 409 && (r.headers()['x-redirect'] || r.headers()['x-location']))
      redirected.add(r.url());
    else if (r.status() >= 400) report.responses.push(`${r.status()} ${r.request().method()} ${r.url()}`);
  });
  page.on('pageerror', (e) => report.errors.push(`${tenant} ${as} ${path}: ${e.message}`));
  page.on('console', (m) => {
    if (m.type() !== 'error') return;
    // The handoff is not mounted in the fixture: its 404 is expected.
    if ((m.location()?.url ?? '').startsWith(handoff)) return;
    logged.push({
      url: m.location()?.url ?? '',
      text: `${tenant} ${as} ${path}: ${m.text()} (${m.location()?.url ?? ''})`,
    });
  });
  await page.goto(`${origin(tenant)}/_e2e/sign-in?as=${as}&to=${encodeURIComponent(path)}`);
  await hydrated(page);
  // Kept only while the page is never loaded again: a form submitted in
  // place, and a client visit, leave it.
  await page.evaluate(() => {
    window.__sameDocument = true;
  });
  return { context, page };
}

const hydrated = (page) => page.waitForFunction(() => typeof history.state?.tinvar?.key === 'string');
const sameDocument = (page) => page.evaluate(() => window.__sameDocument === true);
const heading = (page) => page.locator('h1').first().innerText();
// Meridian's Table names the scrolling region around it.
const table = (page, label) => page.locator(`[role="region"][aria-label="${label}, scrolls horizontally"]`);
const form = (page, label) => page.locator(`form[aria-label="${label}"]`);
const cells = (rows) =>
  rows.evaluateAll((trs) => trs.map((tr) => [...tr.querySelectorAll('td')].map((td) => td.innerText.trim())));

try {
  // 1. Custom domains: add one, prove it, check it, remove it.
  {
    const { context, page } = await visit('acme', 'm-1', '/settings/domains');
    const domains = { emptyBanner: await page.getByText('No domains yet').isVisible() };

    await form(page, 'Add a domain').locator('input[name="host"]').fill('shop.acme.test');
    await form(page, 'Add a domain').getByRole('button', { name: 'Add domain' }).click();
    const card = page.locator('section[data-domain="shop.acme.test"]');
    await card.waitFor();
    domains.added = {
      status: await card.getAttribute('data-status'),
      badge: await card.getByText('Pending', { exact: true }).isVisible(),
      records: await cells(card.locator('tbody tr')),
      emptyBanner: await page.getByText('No domains yet').isVisible(),
    };

    // The records the page says to create, created.
    const txt = domains.added.records.find((r) => r[0] === 'TXT');
    const cname = domains.added.records.find((r) => r[0] === 'CNAME');
    writeFileSync(
      dnsFile,
      JSON.stringify({
        aliases: { [cname?.[1] ?? 'shop.acme.test']: cname?.[2] ?? '' },
        texts: { [txt?.[1] ?? '']: [txt?.[2] ?? ''] },
      }),
    );

    await card.getByRole('button', { name: 'Check now' }).click();
    await page.locator('section[data-domain="shop.acme.test"][data-status="verified"]').waitFor();
    domains.checked = {
      status: await card.getAttribute('data-status'),
      badge: await card.getByText('Verified', { exact: true }).isVisible(),
      records: await card.locator('tbody tr').count(),
      // The schedule's next check, and the last (ADR 0198).
      schedule: await card.getByText(/^Last checked .* UTC\. Next check .* UTC\./).isVisible(),
    };

    await card.getByRole('button', { name: 'Remove' }).click();
    await card.waitFor({ state: 'detached' });
    domains.removed = {
      gone: (await page.locator('section[data-domain]').count()) === 0,
      emptyBanner: await page.getByText('No domains yet').isVisible(),
    };
    domains.sameDocument = await sameDocument(page);
    report.domains = domains;

    await context.close();
  }

  // 2. Settings, as the owner: rename, invite, revoke, and an invite accepted.
  {
    const { context, page } = await visit('acme', 'm-1', '/settings');
    const settings = { heading: await heading(page) };

    const rename = form(page, 'Rename the organization');
    await rename.locator('input[name="name"]').fill('Acme Rockets');
    await rename.getByRole('button', { name: 'Save name' }).click();
    await page.locator('h1', { hasText: 'Acme Rockets' }).waitFor();
    settings.renamed = await heading(page);

    const invite = form(page, 'Invite someone');
    await invite.locator('input[name="email"]').fill('dee@example.net');
    await invite.locator('select[name="role"]').selectOption({ label: 'Admin' });
    await invite.getByRole('button', { name: 'Send invite' }).click();
    const invites = table(page, 'Open invites');
    await invites.waitFor();
    settings.invited = (await cells(invites.locator('tbody tr'))).map((r) => r.slice(0, 2));

    await invites.getByRole('button', { name: 'Revoke' }).click();
    await invites.waitFor({ state: 'detached' });
    settings.revoked = (await table(page, 'Open invites').count()) === 0;
    settings.sameDocument = await sameDocument(page);

    // Invited again, as a member, and the link opened as Dee.
    await invite.locator('input[name="email"]').fill('dee@example.net');
    await invite.locator('select[name="role"]').selectOption({ label: 'Member' });
    await invite.getByRole('button', { name: 'Send invite' }).click();
    await table(page, 'Open invites').waitFor();
    await context.close();

    const sent = readFileSync(invitesFile, 'utf8')
      .trim()
      .split('\n')
      .map((line) => JSON.parse(line));
    const link = new URL(sent[sent.length - 1].url);
    settings.inviteLink = { host: link.host, path: link.pathname, count: sent.length };

    const dee = await visit('acme', 'm-4', `${link.pathname}${link.search}`);
    settings.invitation = { heading: await heading(dee.page) };
    await dee.page.getByRole('button', { name: 'Accept and join' }).click();
    await dee.page.waitForURL(`${origin('acme')}/settings`);
    await table(dee.page, 'Members').waitFor();
    settings.accepted = {
      path: new URL(dee.page.url()).pathname,
      members: (await cells(table(dee.page, 'Members').locator('tbody tr'))).map((r) => r[0].split('\n')[0]),
      sameDocument: await sameDocument(dee.page),
    };

    // Back to the link, by the history: a client visit the router renders
    // over RSC, the invite used now.
    await dee.page.goBack();
    await dee.page.getByText("This invite can't be used").waitFor();
    report.clientVisit = {
      path: new URL(dee.page.url()).pathname,
      sameDocument: await sameDocument(dee.page),
    };
    await dee.context.close();
    report.settings = settings;
  }

  // 3. Settings, as a member: no forms to manage anything.
  {
    const { context, page } = await visit('acme', 'm-3', '/settings');
    report.member = {
      heading: await heading(page),
      members: (await cells(table(page, 'Members').locator('tbody tr'))).map((r) => r[0].split('\n')[0]),
      renameForms: await form(page, 'Rename the organization').count(),
      inviteForms: await form(page, 'Invite someone').count(),
      forms: await page.locator('form').count(),
    };
    await context.close();
  }

  // 4. The switcher: Acme current, Globex on its own host, reached by the handoff.
  {
    const { context, page } = await visit('acme', 'm-1', '/organizations');
    const acme = page.locator('li[data-tenant="acme"]');
    const globex = page.locator('li[data-tenant="globex"]');
    report.switcher = {
      tenants: await page.locator('li[data-tenant]').evaluateAll((lis) => lis.map((li) => li.dataset.tenant)),
      acmeCurrent: await acme.getByText('Current', { exact: true }).isVisible(),
      globexHost: await globex.getByText('globex.localhost', { exact: true }).isVisible(),
    };
    await globex.getByRole('button', { name: 'Switch to Globex' }).click();
    // Either the browser is sent to the handoff on Globex's host, or the
    // switch is refused and the button's form says so.
    const refused = globex.getByText("That didn't work");
    await Promise.race([page.waitForURL(handoff), refused.waitFor()]);
    report.switcher.navigatedTo = page.url();
    report.switcher.refused = page.url() === handoff ? null : await refused.innerText();
    await context.close();
  }

  // 5. Another tenant's settings, where Ann is only a member.
  {
    const { context, page } = await visit('globex', 'm-1', '/settings');
    report.crossTenant = {
      heading: await heading(page),
      members: (await cells(table(page, 'Members').locator('tbody tr'))).map((r) => r[0].split('\n')[0]),
      forms: await page.locator('form').count(),
    };
    await context.close();
  }
} catch (e) {
  report.failure = String(e?.stack ?? e);
  report.failedOn = current
    ? { url: current.url(), text: await current.innerText('body').catch(() => '') }
    : null;
} finally {
  await browser.close();
}
report.errors.push(
  ...logged.filter((e) => !(redirected.has(e.url) && e.text.includes('status of 409'))).map((e) => e.text),
);

console.log(JSON.stringify(report));
