/**
 * What the user is looking at, so "this page" means something.
 *
 * The turn body's optional `context` is what lets a question like "summarise
 * this page" resolve to a uid. It is read from the backend's own URL rather
 * than tracked: TYPO3 v14 routes modules as `/typo3/module/<group>/<name>` and
 * carries the record the module is editing in `id`, so the address bar already
 * holds the answer and anything this file remembered instead could go stale
 * behind a module navigation it did not see.
 *
 * Every value is best-effort. A context that cannot be read is an omitted key,
 * never a guess: the server treats a missing context as "no context", and a
 * WRONG page uid is considerably worse than none.
 */

export interface ClientContext {
  module?: string;
  pageUid?: number;
  workspaceId?: number;
}

function topLocation(): Location | null {
  try {
    return window.top?.location ?? window.location;
  } catch {
    // A cross-origin top frame. Nothing readable, and nothing to report.
    return null;
  }
}

export function readClientContext(): ClientContext {
  const location = topLocation();
  if (location === null) {
    return {};
  }

  const context: ClientContext = {};

  // `/typo3/module/web/layout` → `web_layout`, which is the identifier the
  // module is registered under and the one a tool would be given.
  const match = /\/module\/([^?#]+)/.exec(location.pathname);
  if (match?.[1] !== undefined) {
    const identifier = match[1].replace(/\/+$/, '').split('/').filter(Boolean).join('_');
    if (identifier !== '') {
      context.module = identifier;
    }
  }

  const params = new URLSearchParams(location.search);
  const id = Number.parseInt(params.get('id') ?? '', 10);
  if (Number.isFinite(id) && id > 0) {
    context.pageUid = id;
  }

  const workspace = Number.parseInt(params.get('workspace') ?? '', 10);
  if (Number.isFinite(workspace) && workspace >= 0) {
    context.workspaceId = workspace;
  }

  return context;
}
