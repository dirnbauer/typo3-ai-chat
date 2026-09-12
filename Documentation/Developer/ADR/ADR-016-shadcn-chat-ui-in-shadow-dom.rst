..  include:: /Includes.rst.txt

.. _adr-016:

=========================================
ADR-016: shadcn chat UI in a Shadow DOM
=========================================

**Status:** Accepted

**Date:** 2026-09-12

**Supersedes:** :ref:`ADR-008 <adr-008>`, :ref:`ADR-012 <adr-012>`

Context
=======

1.x shipped two front-ends in sequence and neither settled. The first was
build-free Lit components with `marked` and `DOMPurify` published as import-map
entries; the second was a bundled assistant-ui console. The build-free version
put two libraries on the TYPO3 backend's own module graph, where any other
extension publishing the same specifier decides which copy the whole backend
runs. The bundled version fixed that and introduced a different problem: it
rendered into the backend's document, so TYPO3's stylesheet and the chat's
fought over every element, and each TYPO3 release was a chance for that fight
to change sides.

Meanwhile the surface itself has to appear twice — a toolbar panel that
survives module navigation, and a full module — and two independent
implementations of a chat is how the two drift apart.

Decision
========

One custom element, ``<wc-ai-chat>``, rendering a React/shadcn interface inside
a **Shadow DOM**.

#.  **One element, two variants.** ``variant="panel"`` is the toolbar surface,
    ``variant="module"`` the full one. Same implementation, same state, same
    API; the variant changes layout, not behaviour.

#.  **Shadow DOM, not an iframe and not the light DOM.** The chat's styles and
    the backend's cannot reach each other, which is the property both previous
    attempts lacked — and unlike an iframe it stays one document, so focus,
    selection, keyboard handling and the backend's own modals still work.

#.  **The panel lives in the TOP document.** A panel inside the module iframe
    is destroyed by every click in the module menu. The toolbar therefore loads
    a small launcher that appends the element to the top document and imports
    the bundle on first use, so a backend page that never opens the chat pays
    nothing for it.

#.  **Dependencies live in the bundle.** ``Configuration/JavaScriptModules.php``
    publishes exactly one specifier and no libraries; markdown rendering and
    sanitisation are the bundle's business, where a bundler can pin, tree-shake
    and audit them.

#.  **The PHP side renders a mount point and nothing else.** The template emits
    the element with the conversation uid the URL asked for; every other piece
    of state the client fetches from the API under the user's own session.

Consequences
============

-   A build step is required. That is the trade this makes: a bundle in exchange
    for dependencies that cannot collide with another extension's.
-   Theming must be deliberate. Shadow DOM keeps the backend's stylesheet out,
    so the chat inherits nothing — including the backend's light/dark mode,
    which the element has to observe and mirror.
-   Nothing outside the element can style the chat, which is the point, and
    which an integrator who expected to override a class will notice first.
-   The backend and the frontend are separable: the API is the contract, and
    this ADR states the decision the frontend implements.
