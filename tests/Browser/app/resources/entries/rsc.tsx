import type { ReactNode } from 'react';
import * as stylex from '@stylexjs/stylex';
import { global } from '@meridian/ui/tokens';
import { renderToReadableStream } from '@vitejs/plugin-rsc/rsc/server';
import { deferredRegistry, runInRenderContext } from '@tinvar/react/server';
import { composeRoute } from '@tinvar/react/compose-route';
import { FormSession, type Flashed } from '@tinvar/react/form';
import { HeadProvider } from '@tinvar/react/head';
import '../app.css';
// The package's shipped views, by relative import: what the browser test
// holds to, never copies of them.
import SwitchPage from '../../../../../resources/pages/tenancy/switch';
import DomainsPage from '../../../../../resources/pages/tenancy/domains';
import SettingsPage from '../../../../../resources/pages/tenancy/settings';
import InvitationPage from '../../../../../resources/pages/tenancy/invitation';

type Page = (props: { data: never; params: never }) => ReactNode;

/** Each view the fixture's pages name (Fixture.php). */
const ROUTES: Record<string, { Page: Page }> = {
  'tenancy/switch': { Page: SwitchPage as Page },
  'tenancy/domains': { Page: DomainsPage as Page },
  'tenancy/settings': { Page: SettingsPage as Page },
  'tenancy/invitation': { Page: InvitationPage as Page },
};

export type RenderFrame = {
  route: string;
  params: Record<string, string>;
  data: { page: unknown; layouts?: Record<string, unknown> };
  rid: string;
  nonce: string;
  allow: string[];
  segments?: string[];
  csrf?: string;
  flash?: Flashed;
};

type QueryClientLike = {
  call(name: string, args: Record<string, unknown>, options?: { trailing?: boolean }): Promise<unknown>;
};
type MintClientLike = { mint(component: string, props: Record<string, unknown>): Promise<string> };
type DeferredComponent = (props: Record<string, unknown>) => ReactNode | Promise<ReactNode>;

/** No deferred components in the fixture. */
const COMPONENTS = deferredRegistry<Record<string, DeferredComponent>>({});

type FragmentToken = { component: string; props: Record<string, unknown> };
type FragmentFrame = {
  token?: FragmentToken;
  tokens?: FragmentToken[];
  rid: string;
  nonce: string;
  allow: string[];
};

const styles = stylex.create({
  main: { maxWidth: '960px', marginInline: 'auto', padding: '32px 16px' },
});

/** The document every page renders into: Meridian's globals, the forms' session, the head. */
function Document({ children, frame }: { children: ReactNode; frame: RenderFrame }) {
  return (
    <html lang="en" {...stylex.props(global.html)}>
      <head>
        <meta charSet="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        {import.meta.viteRsc.loadCss()}
      </head>
      <body {...stylex.props(global.body)}>
        <FormSession csrfToken={frame.csrf ?? null} flash={frame.flash ?? null}>
          <HeadProvider titleTemplate="%s · Tenancy" defaultTitle="Tenancy">
            <main {...stylex.props(styles.main)}>{children}</main>
          </HeadProvider>
        </FormSession>
      </body>
    </html>
  );
}

function buildTree(frame: RenderFrame) {
  const route = ROUTES[frame.route];
  if (!route) throw new Error(`Unknown route '${frame.route}'`);

  return composeRoute({
    segments: frame.segments ?? null,
    document: (children) => <Document frame={frame}>{children}</Document>,
    page: () => <route.Page data={frame.data.page as never} params={frame.params as never} />,
    layouts: [],
  });
}

export function renderRsc(
  frame: RenderFrame,
  client: QueryClientLike,
  mints?: MintClientLike,
  onError?: (error: unknown) => string | void,
  signal?: AbortSignal,
): ReadableStream<Uint8Array> {
  return runInRenderContext(
    { requestId: frame.rid, nonce: frame.nonce, allowed: frame.allow, client, ...(mints ? { mints } : {}) },
    () => renderToReadableStream(buildTree(frame), { onError, ...(signal ? { signal } : {}) }),
  );
}

export function renderFragment(
  frame: FragmentFrame,
  client: QueryClientLike,
  onError?: (error: unknown) => string | void,
  signal?: AbortSignal,
): ReadableStream<Uint8Array> {
  const element = (token: FragmentToken, key?: number) => {
    const Component = Object.hasOwn(COMPONENTS, token.component) ? COMPONENTS[token.component] : undefined;
    if (!Component) throw new Error(`Unknown deferred component '${token.component}'.`);
    return <Component key={key} {...token.props} />;
  };
  const root = frame.tokens ? frame.tokens.map(element) : element(frame.token as FragmentToken);

  return runInRenderContext({ requestId: frame.rid, nonce: frame.nonce, allowed: frame.allow, client }, () =>
    renderToReadableStream(root, { onError, ...(signal ? { signal } : {}) }),
  );
}

export async function renderHtml(
  frame: RenderFrame,
  client: QueryClientLike,
  onError?: (error: unknown) => string | void,
  mints?: MintClientLike,
  signal?: AbortSignal,
) {
  const [forSsr, forPayload] = renderRsc(frame, client, mints, onError, signal).tee();
  const ssr = await import.meta.viteRsc.loadModule<typeof import('./ssr')>('ssr', 'index');
  const htmlStream = await ssr.handleSsr(forSsr, frame.nonce, onError, signal);

  return { htmlStream, rscStream: forPayload };
}

export async function warm() {
  const ssr = await import.meta.viteRsc.loadModule<typeof import('./ssr')>('ssr', 'index');

  return ssr.warm();
}

export default async function handler(): Promise<Response> {
  return new Response('Tinvar renderer: drive this through the socket.', { status: 400 });
}
