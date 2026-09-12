import { memo } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';
import rehypeSanitize, { defaultSchema } from 'rehype-sanitize';
import rehypeExternalLinks from 'rehype-external-links';
import { cn } from '@/lib/utils';

/**
 * Model output, rendered as markdown and nothing else.
 *
 * 1.x rendered with `marked` and then washed the result with DOMPurify — parse
 * to HTML, then try to take the dangerous parts back out. This pipeline never
 * produces the HTML in the first place: `react-markdown` builds a syntax tree,
 * `rehype-sanitize` filters that tree against an allow-list, and React renders
 * elements. There is no `dangerouslySetInnerHTML` anywhere in the path, so a
 * `<script>` in a tool result is text by construction rather than by vigilance.
 *
 * Raw HTML is not enabled (`rehype-raw` is deliberately absent), which means a
 * `<div>` the model writes stays visible as the characters it typed.
 *
 * Every link is external as far as this document is concerned — the chat lives
 * in the TYPO3 backend, and a link in a model's answer points at the web. They
 * open in a new tab with `rel="noopener noreferrer"`, because a link that
 * navigates the backend away mid-run loses the run, and `window.opener` on a
 * backend document is not something to hand to an arbitrary page.
 */

const schema = {
  ...defaultSchema,
  attributes: {
    ...defaultSchema.attributes,
    // rehype-external-links adds these after sanitisation runs, so the schema
    // has to allow them or they are stripped right back off again.
    a: [...(defaultSchema.attributes?.a ?? []), 'target', 'rel'],
    code: [...(defaultSchema.attributes?.code ?? []), ['className', /^language-./]],
  },
};

export type MarkdownProps = {
  children: string;
  className?: string;
};

function MarkdownComponent({ children, className }: MarkdownProps) {
  return (
    <div className={cn('wc-prose', className)}>
      <ReactMarkdown
        remarkPlugins={[remarkGfm]}
        rehypePlugins={[
          [rehypeSanitize, schema],
          [rehypeExternalLinks, { target: '_blank', rel: ['noopener', 'noreferrer'] }],
        ]}
      >
        {children}
      </ReactMarkdown>
    </div>
  );
}

/**
 * Memoised because a streaming turn re-renders the thread on every frame, and
 * re-parsing every finished message each time is the difference between a
 * smooth stream and a stuttering one in a long conversation.
 */
export const Markdown = memo(MarkdownComponent);
