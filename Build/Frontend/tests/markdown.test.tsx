import { describe, expect, it } from 'vitest';
import { render } from '@testing-library/react';
import { Markdown } from '@/components/chat/markdown';

/**
 * What the markdown pipeline refuses to render.
 *
 * Model output and tool previews are untrusted text that arrives inside a
 * logged-in TYPO3 backend session. The pipeline never builds HTML from it —
 * `react-markdown` produces a tree, `rehype-sanitize` filters that tree, React
 * renders elements — so these are assertions about a property the design has,
 * not about a filter that has to keep up.
 */

function html(markdown: string): string {
  const { container } = render(<Markdown>{markdown}</Markdown>);

  return container.innerHTML;
}

describe('Markdown', () => {
  it('renders ordinary prose', () => {
    expect(html('A **bold** claim.')).toContain('<strong>bold</strong>');
  });

  it('renders GitHub-flavoured tables, which is what a tool result looks like', () => {
    const rendered = html('| uid | title |\n| --- | --- |\n| 7 | Home |');

    expect(rendered).toContain('<table>');
    expect(rendered).toContain('<td>Home</td>');
  });

  it('strips a script tag, leaving its contents as inert text', () => {
    const { container } = render(
      <Markdown>{'Before <script>alert(document.cookie)</script> after'}</Markdown>,
    );

    // No element: the sanitiser drops the node, and what was between the tags
    // survives only as a text node, which a browser never executes.
    expect(container.querySelector('script')).toBeNull();
    expect(container.innerHTML).not.toContain('<script');
    expect(container.textContent).toContain('alert(document.cookie)');
  });

  it('strips an iframe', () => {
    const rendered = html('<iframe src="https://evil.example/"></iframe>');

    expect(rendered).not.toContain('<iframe');
  });

  it('strips an inline event handler', () => {
    const rendered = html('<img src="x" onerror="alert(1)">');

    expect(rendered).not.toContain('onerror');
  });

  it('refuses a javascript: link', () => {
    const rendered = html('[click me](javascript:alert(1))');

    expect(rendered).not.toContain('javascript:');
  });

  it('gives every external link rel="noopener noreferrer" and a new tab', () => {
    const rendered = html('[TYPO3](https://typo3.org/)');

    expect(rendered).toContain('href="https://typo3.org/"');
    expect(rendered).toContain('rel="noopener noreferrer"');
    expect(rendered).toContain('target="_blank"');
  });

  it('keeps a fenced code block as text, including the markup inside it', () => {
    const rendered = html('```html\n<script>alert(1)</script>\n```');

    expect(rendered).toContain('<pre>');
    expect(rendered).toContain('&lt;script&gt;');
    expect(rendered).not.toContain('<script>alert(1)</script>');
  });

  it('renders nothing at all for an empty answer', () => {
    expect(html('')).toBe('<div class="wc-prose"></div>');
  });
});
