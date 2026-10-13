/**
 * The PHP front controller rewrites <base href> to the URL prefix the app is served under:
 * '/' behind a rewrite (.htaccess), '/index.php/' for PATH_INFO URLs, or a subdirectory.
 * Router history and API calls both live under that prefix. Returns it without the
 * trailing slash ('' for the root).
 */
export function appBasePath(doc: Document = document): string {
  const base = doc.querySelector('base')?.getAttribute('href') ?? '/'
  const path = new URL(base, doc.location.origin).pathname
  return path.replace(/\/+$/, '')
}

/** Absolute URL of an app path (e.g. a webhook endpoint), including origin and base. */
export function absoluteAppUrl(path: string): string {
  return `${window.location.origin}${appBasePath()}${path}`
}
