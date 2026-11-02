import { createFromReadableStream, getClientEntryUrl } from '@vitejs/plugin-rsc/ssr';
import { renderToReadableStream } from 'react-dom/server.edge';
import clientReferences from 'virtual:vite-rsc/client-references';
import { warmClientReferences } from '@tinvar/renderer/warm';

/** RSC stream -> React tree -> HTML, with the client entry as a nonced bootstrap module. */
export async function handleSsr(
  rscStream: ReadableStream,
  nonce: string,
  onError?: (error: unknown) => string | void,
  signal?: AbortSignal,
): Promise<ReadableStream> {
  const root = await createFromReadableStream<React.ReactNode>(rscStream);

  return renderToReadableStream(root, {
    nonce,
    ...(signal ? { signal } : {}),
    bootstrapModules: [getClientEntryUrl()],
    onError(error) {
      return onError?.(error);
    },
  });
}

/** Every client component's module, loaded before the renderer takes its first render. */
export function warm() {
  return warmClientReferences(clientReferences);
}
