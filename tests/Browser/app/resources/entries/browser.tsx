import { createFromReadableStream } from '@vitejs/plugin-rsc/browser';
import type { ReactNode } from 'react';
import { hydrateRoot } from 'react-dom/client';
import { createPageRoot, createRouter, inlinePayloadStream } from '@tinvar/react/client';
import { RouterProvider } from '@tinvar/react/link';
import { FragmentProvider } from '@tinvar/react/deferred-boundary';

// The client entry, as an application's: the inline payload decoded and
// hydrated, and the router that visits every later page over RSC.
const decode = (stream: ReadableStream<Uint8Array>) => createFromReadableStream<ReactNode>(stream);

async function main() {
  const initial = await decode(inlinePayloadStream());
  const { PageRoot, prepare } = createPageRoot({ decode });
  const router = createRouter({ prepare });

  await router.ready;

  hydrateRoot(
    document,
    <RouterProvider router={router}>
      <FragmentProvider decode={decode}>
        <PageRoot initial={initial} />
      </FragmentProvider>
    </RouterProvider>,
  );
}

void main();
