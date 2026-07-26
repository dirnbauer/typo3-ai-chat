/**
 * Tests for the color-scheme handling of the chat UI.
 *
 * The TYPO3 v14 backend supports light AND dark color schemes via
 * light-dark()-aware --typo3-* custom properties. Any hardcoded color
 * that is not a fallback inside var()/color-mix() renders wrong in one
 * of the two schemes. These tests scan the style sources and fail when
 * a bare color literal sneaks back in (regression guard), and verify
 * the shared theme contract used by both Lit components.
 *
 * Static source analysis is used instead of rendering because the Lit
 * components resolve 'lit' via the TYPO3 backend importmap, which is
 * not available under Jest.
 */

import {describe, test, expect} from '@jest/globals';
import {readFileSync} from 'node:fs';
import {dirname, join} from 'node:path';
import {fileURLToPath} from 'node:url';

const jsDir = join(dirname(fileURLToPath(import.meta.url)), '../../Resources/Public/JavaScript');

const STYLE_SOURCES = [
    'ai-chat-panel.js',
    'chat-app.js',
    'chat-core.js',
    'markdown-styles.js',
    'theme.js',
];

const COLOR_LITERAL = /#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)/;

const read = (file) => readFileSync(join(jsDir, file), 'utf8');

describe('color-scheme safety (no bare color literals)', () => {
    test.each(STYLE_SOURCES)('%s uses color literals only as var()/color-mix() fallbacks', (file) => {
        const offending = read(file)
            .split('\n')
            .map((line, idx) => ({line, no: idx + 1}))
            .filter(({line}) => COLOR_LITERAL.test(line))
            .filter(({line}) => !line.includes('var(--') && !line.includes('color-mix('));
        expect(offending.map(({no, line}) => `${file}:${no}: ${line.trim()}`)).toEqual([]);
    });
});

describe('shared theme contract', () => {
    const theme = read('theme.js');

    test.each([
        '--wc-chat-surface',
        '--wc-chat-surface-low',
        '--wc-chat-surface-base',
        '--wc-chat-surface-high',
        '--wc-chat-text',
        '--wc-chat-text-variant',
        '--wc-chat-link',
        '--wc-chat-border',
        '--wc-chat-input-border',
        '--wc-chat-hover',
        '--wc-chat-active',
        '--wc-chat-accent',
        '--wc-chat-on-accent',
        '--wc-chat-accent-hover',
        '--wc-chat-focus-ring',
        '--wc-chat-success-bg',
        '--wc-chat-success-text',
        '--wc-chat-warning-bg',
        '--wc-chat-warning-text',
        '--wc-chat-danger-bg',
        '--wc-chat-danger-text',
        '--wc-chat-status-info',
        '--wc-chat-status-success',
        '--wc-chat-status-danger',
    ])('theme.js defines %s', (property) => {
        expect(theme).toContain(`${property}:`);
    });

    test('theme maps the accent to the scheme-aware TYPO3 primary surface tokens', () => {
        expect(theme).toContain('--wc-chat-accent: var(--typo3-surface-primary,');
        expect(theme).toContain('--wc-chat-on-accent: var(--typo3-surface-primary-text,');
    });

    test('theme maps status chips to scheme-aware surface-container pairs', () => {
        expect(theme).toContain('var(--typo3-surface-container-success,');
        expect(theme).toContain('var(--typo3-surface-container-warning,');
        expect(theme).toContain('var(--typo3-surface-container-danger,');
    });

    test.each(['ai-chat-panel.js', 'chat-app.js'])('%s applies themeStyles before its own styles', (file) => {
        const source = read(file);
        expect(source).toContain("import {themeStyles} from './theme.js';");
        expect(source).toContain('static styles = [themeStyles, markdownStyles,');
    });

    test('markdown link color uses the shared token', () => {
        expect(read('markdown-styles.js')).toContain('var(--wc-chat-link, #0078d4)');
    });
});
