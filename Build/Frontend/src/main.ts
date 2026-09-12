import { defineChatElement } from '@/element';

/**
 * The bundle's entry point, and all it does.
 *
 * Both loaders — `toolbar/launcher.js` for the panel and `ChatModuleController`
 * for the module — import this file and then get out of the way: the launcher
 * appends `<wc-ai-chat variant="panel">` to the top document, the module
 * template emits `<wc-ai-chat variant="module">`, and the element upgrades
 * whichever of them is already in the DOM.
 *
 * Registering the element is therefore the whole job. Nothing here queries the
 * API, reads the URL or decides what to show; a bundle that did would be doing
 * it twice, once per surface.
 */
defineChatElement();
