import { describe, expect, it } from 'vitest';
import { shadowSafeCss } from '@/styles/shadow-css';
import rawStyles from '@/styles/tailwind.css?inline';

/**
 * The transform that stops half the utilities failing silently in a shadow
 * root. See `src/styles/shadow-css.ts` for why it is needed at all.
 */
describe('shadowSafeCss', () => {
  it('unwraps the gate and keeps the fallback declarations', () => {
    const css = shadowSafeCss(
      '@layer properties {\n@supports (((-webkit-hyphens:none))) {\n*, ::before { --tw-border-style: solid; }\n}\n}\n.border { border-width: 1px }',
    );

    expect(css).not.toContain('@supports');
    expect(css).toContain('--tw-border-style: solid');
    expect(css).toContain('.border { border-width: 1px }');
    // The layer survives: these declarations must stay below every utility.
    expect(css).toContain('@layer properties');
  });

  it('keeps the braces balanced', () => {
    const css = shadowSafeCss(
      '@layer properties {\n@supports (x) {\n*, ::before { --a: 1; }\n.b { --c: 2 }\n}\n}\n.d{e:f}',
    );

    const open = (css.match(/\{/g) ?? []).length;
    const close = (css.match(/\}/g) ?? []).length;
    expect(open).toBe(close);
  });

  it('leaves a stylesheet with no gate alone', () => {
    const css = '@layer utilities { .border { border-width: 1px } }';

    expect(shadowSafeCss(css)).toBe(css);
  });

  it('leaves a properties layer with no @supports alone', () => {
    const css = '@layer properties { *, ::before { --tw-border-style: solid } }';

    expect(shadowSafeCss(css)).toBe(css);
  });

  it('does not touch an @supports that belongs to some other rule', () => {
    // Tailwind writes the layer ORDER as a statement first and the block much
    // later. Searching for the name alone lands on the statement, and the next
    // @supports is then whatever unrelated feature query comes next.
    const css =
      '@layer properties;\n@supports (display: grid) { .g { display: grid } }\n@layer properties { @supports (x) { * { --a: 1 } } }';
    const out = shadowSafeCss(css);

    expect(out).toContain('@supports (display: grid) { .g { display: grid } }');
    expect(out).toContain('* { --a: 1 }');
    expect((out.match(/@supports/g) ?? []).length).toBe(1);
  });

  it('frees the real stylesheet: --tw-border-style is no longer behind a gate', () => {
    // The regression this exists to prevent is silent: a Tailwind upgrade
    // changes the shape of the block, the transform stops matching, and every
    // border, ring and shadow in the shadow root turns back off with no error.
    expect(rawStyles).toContain('--tw-border-style');

    const declaration = '--tw-border-style: solid';
    const before = (index: number, css: string) => css.slice(Math.max(0, index - 600), index);

    expect(before(rawStyles.indexOf(declaration), rawStyles)).toContain('@supports');

    const css = shadowSafeCss(rawStyles);
    expect(before(css.indexOf(declaration), css)).not.toContain('@supports');
  });
});
