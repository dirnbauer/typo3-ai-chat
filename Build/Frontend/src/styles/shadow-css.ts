/**
 * The one thing Tailwind v4 cannot do inside a Shadow DOM, undone.
 *
 * Half of Tailwind's utilities are written against custom properties that it
 * registers with `@property`:
 *
 *     @property --tw-border-style { syntax: "<line-style>"; initial-value: solid }
 *     .border { border-style: var(--tw-border-style); border-width: 1px }
 *
 * `@property` registers into the DOCUMENT. A rule inside a stylesheet that is
 * only adopted by a shadow root never registers, so `--tw-border-style` has no
 * value, `var()` makes the declaration invalid at computed-value time,
 * `border-style` falls back to its initial `none` — and a `border-width` with no
 * style computes to **0px**. The border silently disappears. So do the ring, the
 * shadow, every transform and every gradient: 48 registrations, all of them
 * quiet failures that look like a stylesheet that did not load.
 *
 * Tailwind already ships the cure. It emits a `@layer properties` block that
 * assigns every one of those properties its initial value on `*, ::before,
 * ::after` — for browsers with no `@property` support — and gates it behind an
 * `@supports` that only Safari below 16.4 and old Firefox satisfy. A shadow
 * root is the third case nobody wrote a query for, so the gate is removed here
 * and the fallback applies always.
 *
 * The values are Tailwind's own, which is the point: this changes WHETHER the
 * fallback runs, never what it says.
 */

export function shadowSafeCss(css: string): string {
  const block = propertiesBlock(css);
  if (block === null) {
    return css;
  }

  const supports = css.indexOf('@supports', block.open);
  if (supports === -1 || supports > block.close) {
    // A future Tailwind that no longer needs the gate, or a stylesheet built
    // without it. Nothing to do, and nothing to guess at.
    return css;
  }

  const open = css.indexOf('{', supports);
  if (open === -1 || open > block.close) {
    return css;
  }

  const close = matchingBrace(css, open);
  if (close === -1) {
    return css;
  }

  // Keep the block's contents, drop its wrapper: `@supports (…) {` at the front
  // and the `}` that closes it at the back.
  return css.slice(0, supports) + css.slice(open + 1, close) + css.slice(close + 1);
}

/**
 * The `@layer properties { … }` BLOCK, not the statement.
 *
 * Tailwind writes `@layer properties, theme, base, …;` as a plain statement
 * first, to fix the layer order, and emits the block itself later. Searching
 * for the name alone therefore lands on the statement, and the next `@supports`
 * after it is some unrelated feature query further down the file — which this
 * would then unwrap, corrupting a rule that has nothing to do with the problem.
 */
function propertiesBlock(css: string): { open: number; close: number } | null {
  const pattern = /@layer\s+properties\s*\{/g;
  const match = pattern.exec(css);
  if (match === null) {
    return null;
  }

  const open = match.index + match[0].length - 1;
  const close = matchingBrace(css, open);

  return close === -1 ? null : { open, close };
}

function matchingBrace(css: string, open: number): number {
  let depth = 0;
  for (let index = open; index < css.length; index += 1) {
    const character = css[index];
    if (character === '{') {
      depth += 1;
    } else if (character === '}') {
      depth -= 1;
      if (depth === 0) {
        return index;
      }
    }
  }

  return -1;
}
