#!/usr/bin/env node
// @ts-check
'use strict';

/**
 * Zero-dependency static-site generator for docs/self-hosting/*.md.
 *
 * item/docs-site (issue #27, spec §12): the Pages workflow (pages.yml)
 * needs *something* to upload as a Pages artifact, and CLAUDE.md's
 * lockfile section forbids adding a dependency to get one -- a Markdown
 * renderer is one npm install away from the exact `npm ci`/`npm run build`
 * lockfile rewrite that has cost four CI cycles already. So this is a small,
 * deliberately incomplete Markdown -> HTML converter, written against only
 * what docs/self-hosting/*.md actually uses (checked by reading all nine
 * files before writing this, not guessed at): headings (#/##), paragraphs
 * hard-wrapped across lines, unordered and ordered lists with wrapped
 * continuation lines, fenced code blocks (with and without a language tag),
 * inline code, **bold**, *italic*, [text](url) links (internal .md
 * cross-references and the one external https:// link in upgrading.md),
 * and the pipe tables in configuration-reference.md, operations-runbook.md
 * and search.md. No blockquotes, nested lists, images or thematic breaks
 * exist in the corpus, so none are implemented -- see the plain-text
 * regexes below for exactly which shapes each block-level branch matches.
 *
 * Every text run is HTML-escaped before any inline markup is applied, and
 * fenced code content is escaped with no markup applied at all. The docs
 * contain real HTML-looking and shell-looking text that would otherwise
 * break or inject markup -- storage.md's `<a real password>` inside a
 * ```bash fence, and configuration-reference.md's `` `<title>` `` inline
 * code inside a table cell -- both round-trip as literal text.
 *
 * Usage: node .github/scripts/build-docs-site.mjs
 * Reads docs/self-hosting/*.md and writes static HTML to docs/.site/.
 */

import { readFileSync, writeFileSync, mkdirSync, rmSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const REPO_ROOT = path.resolve(__dirname, '..', '..');
const DOCS_DIR = path.join(REPO_ROOT, 'docs', 'self-hosting');
const OUT_DIR = path.join(REPO_ROOT, 'docs', '.site');

/**
 * The site index. docs/self-hosting/README.md is already a numbered list
 * linking every other page (checked before writing this: it reads fine as
 * a home page on its own), so it becomes index.html rather than this
 * script generating a second, redundant one.
 */
export const INDEX_PAGE = { file: 'README.md', slug: 'index' };

/**
 * Every other self-hosting page, in the order docs/self-hosting/README.md
 * itself lists them. Kept as a flat array of plain string/string literals
 * (not computed) so a test can regex this file's own source for the list
 * of `.md` filenames it builds, without running node -- see
 * tests/Feature/DocsSiteBuildTest.php, and CLAUDE.md's "no test may
 * require an external binary", which the tests job's runner (no Node
 * setup step -- see .github/workflows/tests.yml) would otherwise violate.
 *
 * @type {{file: string, slug: string}[]}
 */
export const PAGES = [
  { file: 'quick-start.md', slug: 'quick-start' },
  { file: 'configuration-reference.md', slug: 'configuration-reference' },
  { file: 'storage.md', slug: 'storage' },
  { file: 'search.md', slug: 'search' },
  { file: 'operations-runbook.md', slug: 'operations-runbook' },
  { file: 'backup-and-restore.md', slug: 'backup-and-restore' },
  { file: 'upgrading.md', slug: 'upgrading' },
  { file: 'troubleshooting.md', slug: 'troubleshooting' },
];

/** @type {Record<string, string>} filename (e.g. 'storage.md') -> slug */
const FILE_TO_SLUG = Object.fromEntries(
  [INDEX_PAGE, ...PAGES].map((p) => [p.file, p.slug]),
);

// ---------------------------------------------------------------------------
// HTML escaping
// ---------------------------------------------------------------------------

/**
 * Escapes the five characters that matter in HTML text content and
 * attribute values. Applied to every text run BEFORE any inline markup is
 * generated, so an unescaped `<` or `&` sitting in a shell command or an
 * env value can never be read back as markup. `&` is replaced first so the
 * entities this function itself inserts are not re-escaped.
 *
 * @param {string} text
 * @returns {string}
 */
function escapeHtml(text) {
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

// ---------------------------------------------------------------------------
// Heading slugs
// ---------------------------------------------------------------------------

/**
 * GitHub-style heading slug: strip inline code backticks, lowercase,
 * drop everything but letters/digits/underscore/hyphen/space, collapse
 * spaces to hyphens. Matches the three cross-file anchors the docs
 * actually use (`configuration-reference.md#database`, `#object-storage`,
 * `#container-level-toggles`) -- all plain-text headings with no inline
 * code in them, so the backtick-stripping only matters for headings that
 * don't happen to be link targets today (e.g. "`APP_KEY` travels with the
 * backup...").
 *
 * @param {string} headingText
 * @returns {string}
 */
function slugify(headingText) {
  return headingText
    .replace(/`/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9_\- ]+/g, '')
    .trim()
    .replace(/\s+/g, '-');
}

// ---------------------------------------------------------------------------
// Inline markup: code spans, bold, italic, links
// ---------------------------------------------------------------------------

/**
 * Renders one line/run of Markdown inline syntax to HTML. Order matters:
 * text is escaped first, then code spans are pulled out into placeholder
 * tokens (protecting their content -- and any `*` inside it, e.g. the
 * `DB_*` env-var-prefix code spans in configuration-reference.md -- from
 * being mistaken for bold/italic markers by the next two passes), then
 * bold, then italic, then links, then the code-span placeholders are
 * substituted back in as real `<code>` tags.
 *
 * @param {string} rawText
 * @returns {string}
 */
function renderInline(rawText) {
  let text = escapeHtml(rawText);

  /** @type {string[]} */
  const codeSpans = [];
  text = text.replace(/`([^`]+)`/g, (_m, code) => {
    const token = `\u0000CODE${codeSpans.length}\u0000`;
    codeSpans.push(code);
    return token;
  });

  text = text.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
  text = text.replace(/\*(.+?)\*/g, '<em>$1</em>');

  text = text.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (_m, label, url) => {
    return `<a href="${resolveHref(url)}">${label}</a>`;
  });

  text = text.replace(/\u0000CODE(\d+)\u0000/g, (_m, i) => {
    return `<code>${codeSpans[Number(i)]}</code>`;
  });

  return text;
}

/**
 * Resolves a Markdown link target to what the generated site actually
 * serves. An external `https://` link (the one in upgrading.md, to Keep a
 * Changelog) passes through untouched. An internal `something.md` or
 * `something.md#anchor` link -- the only other kind in this corpus -- is
 * rewritten to `something.html` (or `something.html#anchor`), because that
 * is the extension every page in this generator is actually written under.
 *
 * @param {string} url
 * @returns {string}
 */
function resolveHref(url) {
  if (/^[a-z][a-z0-9+.-]*:\/\//i.test(url)) {
    return url;
  }

  const hashIndex = url.indexOf('#');
  const file = hashIndex === -1 ? url : url.slice(0, hashIndex);
  const anchor = hashIndex === -1 ? '' : url.slice(hashIndex);

  const slug = FILE_TO_SLUG[file];
  if (!slug) {
    // Not one of the nine known pages -- leave it as-is rather than
    // guessing, so a broken link stays visibly broken instead of silently
    // pointing somewhere plausible-looking but wrong.
    return url;
  }

  return `${slug}.html${anchor}`;
}

/**
 * Plain-text version of a heading, for the `<title>` tag and for slugging
 * -- no HTML tags, just the letters a reader would say out loud.
 *
 * @param {string} headingText
 * @returns {string}
 */
function plainHeadingText(headingText) {
  return headingText.replace(/`/g, '').replace(/\*\*/g, '').replace(/\*/g, '');
}

// ---------------------------------------------------------------------------
// Block-level parsing
// ---------------------------------------------------------------------------

const TABLE_SEPARATOR_RE = /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/;

/**
 * Splits Markdown source into an ordered list of block-level nodes. Each
 * branch below matches exactly one shape actually present in
 * docs/self-hosting/*.md (checked by grepping all nine files first --
 * see this file's top docblock); nothing here handles a Markdown feature
 * the corpus doesn't use.
 *
 * @param {string} markdown
 * @returns {Array<Record<string, any>>}
 */
function parseBlocks(markdown) {
  const lines = markdown.replace(/\r\n/g, '\n').split('\n');
  const blocks = [];
  let i = 0;

  while (i < lines.length) {
    const line = lines[i];

    if (line.trim() === '') {
      i++;
      continue;
    }

    const fenceMatch = line.match(/^```(.*)$/);
    if (fenceMatch) {
      const lang = fenceMatch[1].trim();
      const codeLines = [];
      i++;
      while (i < lines.length && lines[i].trim() !== '```') {
        codeLines.push(lines[i]);
        i++;
      }
      i++; // consume the closing fence
      blocks.push({ type: 'code', lang, content: codeLines.join('\n') });
      continue;
    }

    const headingMatch = line.match(/^(#{1,6})\s+(.+)$/);
    if (headingMatch) {
      blocks.push({
        type: 'heading',
        level: headingMatch[1].length,
        text: headingMatch[2].trim(),
      });
      i++;
      continue;
    }

    if (/^\s*\|/.test(line) && lines[i + 1] !== undefined && TABLE_SEPARATOR_RE.test(lines[i + 1])) {
      const headerRow = line;
      i += 2; // header row + separator row
      const bodyRows = [];
      while (i < lines.length && /^\s*\|/.test(lines[i])) {
        bodyRows.push(lines[i]);
        i++;
      }
      blocks.push({ type: 'table', headerRow, bodyRows });
      continue;
    }

    if (/^-\s+/.test(line)) {
      const items = [];
      let current = null;
      while (i < lines.length) {
        const l = lines[i];
        if (/^-\s+/.test(l)) {
          if (current !== null) items.push(current);
          current = l.replace(/^-\s+/, '');
          i++;
        } else if (/^\s+\S/.test(l) && current !== null) {
          current += ' ' + l.trim();
          i++;
        } else {
          break;
        }
      }
      if (current !== null) items.push(current);
      blocks.push({ type: 'ul', items });
      continue;
    }

    if (/^\d+\.\s+/.test(line)) {
      const items = [];
      let current = null;
      while (i < lines.length) {
        const l = lines[i];
        if (/^\d+\.\s+/.test(l)) {
          if (current !== null) items.push(current);
          current = l.replace(/^\d+\.\s+/, '');
          i++;
        } else if (/^\s+\S/.test(l) && current !== null) {
          current += ' ' + l.trim();
          i++;
        } else {
          break;
        }
      }
      if (current !== null) items.push(current);
      blocks.push({ type: 'ol', items });
      continue;
    }

    // Paragraph: a hard-wrapped run of plain text lines, joined with a
    // single space each -- these docs are hand-wrapped at ~78 columns for
    // source readability (see tests/Feature/SelfHostingDocsTest.php's own
    // docblock making the same point about the rendered page not being
    // hard-wrapped), so a single "\n" here is a soft break, not a new
    // paragraph.
    const paraLines = [line];
    i++;
    while (
      i < lines.length &&
      lines[i].trim() !== '' &&
      !/^```/.test(lines[i]) &&
      !/^#{1,6}\s/.test(lines[i]) &&
      !/^-\s+/.test(lines[i]) &&
      !/^\d+\.\s+/.test(lines[i]) &&
      !/^\s*\|/.test(lines[i])
    ) {
      paraLines.push(lines[i]);
      i++;
    }
    blocks.push({ type: 'p', text: paraLines.join(' ') });
  }

  return blocks;
}

/**
 * @param {string} row a raw `| a | b |` source line
 * @returns {string[]} trimmed cell contents, in order
 */
function splitTableRow(row) {
  let trimmed = row.trim();
  if (trimmed.startsWith('|')) trimmed = trimmed.slice(1);
  if (trimmed.endsWith('|')) trimmed = trimmed.slice(0, -1);
  return trimmed.split('|').map((cell) => cell.trim());
}

// ---------------------------------------------------------------------------
// Block-level rendering
// ---------------------------------------------------------------------------

/**
 * @param {Array<Record<string, any>>} blocks
 * @returns {{ bodyHtml: string, headings: Array<{level: number, id: string, text: string}> }}
 */
function renderBlocks(blocks) {
  /** @type {Array<{level: number, id: string, text: string}>} */
  const headings = [];
  const parts = [];

  for (const block of blocks) {
    switch (block.type) {
      case 'heading': {
        const id = slugify(block.text);
        const plain = plainHeadingText(block.text);
        headings.push({ level: block.level, id, text: plain });
        parts.push(`<h${block.level} id="${id}">${renderInline(block.text)}</h${block.level}>`);
        break;
      }

      case 'p':
        parts.push(`<p>${renderInline(block.text)}</p>`);
        break;

      case 'ul':
        parts.push('<ul>' + block.items.map((item) => `<li>${renderInline(item)}</li>`).join('') + '</ul>');
        break;

      case 'ol':
        parts.push('<ol>' + block.items.map((item) => `<li>${renderInline(item)}</li>`).join('') + '</ol>');
        break;

      case 'code': {
        // Escaped only -- no inline markup applies inside a fenced code
        // block, so renderInline (which would treat _ and * as markup) is
        // deliberately not used here.
        const escaped = escapeHtml(block.content);
        const langClass = block.lang ? ` class="language-${escapeHtml(block.lang)}"` : '';
        parts.push(`<pre><code${langClass}>${escaped}</code></pre>`);
        break;
      }

      case 'table': {
        const head = splitTableRow(block.headerRow).map((cell) => `<th>${renderInline(cell)}</th>`).join('');
        const body = block.bodyRows
          .map((row) => '<tr>' + splitTableRow(row).map((cell) => `<td>${renderInline(cell)}</td>`).join('') + '</tr>')
          .join('');
        parts.push(`<table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`);
        break;
      }

      default:
        throw new Error(`build-docs-site: unhandled block type "${block.type}"`);
    }
  }

  return { bodyHtml: parts.join('\n'), headings };
}

// ---------------------------------------------------------------------------
// Page assembly
// ---------------------------------------------------------------------------

const STYLE = `
:root { color-scheme: light dark; }
body {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
  line-height: 1.6;
  max-width: 46rem;
  margin: 0 auto;
  padding: 1.5rem 1.25rem 4rem;
  color: #1b1f24;
  background: #ffffff;
}
@media (prefers-color-scheme: dark) {
  body { color: #e6edf3; background: #0d1117; }
  a { color: #6cb6ff; }
  code, pre { background: #161b22; }
  th, td { border-color: #30363d; }
  nav { border-bottom-color: #30363d; }
}
a { color: #0969da; }
nav {
  margin-bottom: 2rem;
  padding-bottom: 1rem;
  border-bottom: 1px solid #d0d7de;
  font-size: 0.9rem;
}
nav ul { list-style: none; display: flex; flex-wrap: wrap; gap: 0.75rem; padding: 0; margin: 0.5rem 0 0; }
code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: #f6f8fa; padding: 0.15em 0.35em; border-radius: 4px; font-size: 0.9em; }
pre { background: #f6f8fa; padding: 1rem; overflow-x: auto; border-radius: 6px; }
pre code { padding: 0; background: none; }
table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
th, td { border: 1px solid #d0d7de; padding: 0.5rem 0.75rem; text-align: left; vertical-align: top; }
h1, h2, h3 { line-height: 1.25; }
`.trim();

/**
 * @param {{title: string, bodyHtml: string, navHtml: string}} page
 * @returns {string}
 */
function renderPage({ title, bodyHtml, navHtml }) {
  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${escapeHtml(title)}</title>
<style>${STYLE}</style>
</head>
<body>
<nav>${navHtml}</nav>
<main>
${bodyHtml}
</main>
</body>
</html>
`;
}

/**
 * @param {string} currentSlug
 * @returns {string}
 */
function buildNavHtml(currentSlug) {
  const allPages = [INDEX_PAGE, ...PAGES];
  const items = allPages
    .map(({ slug }) => {
      const label = slug === 'index' ? 'Self-hosting docs' : slug.replace(/-/g, ' ');
      if (slug === currentSlug) {
        return `<li><strong>${escapeHtml(label)}</strong></li>`;
      }
      return `<li><a href="${slug}.html">${escapeHtml(label)}</a></li>`;
    })
    .join('');
  return `<a href="index.html">doccum self-hosting docs</a><ul>${items}</ul>`;
}

/**
 * @param {{file: string, slug: string}} pageDef
 * @returns {{ path: string, html: string, title: string }}
 */
function buildPage(pageDef) {
  const source = readFileSync(path.join(DOCS_DIR, pageDef.file), 'utf8');
  const blocks = parseBlocks(source);
  const { bodyHtml, headings } = renderBlocks(blocks);

  const firstH1 = headings.find((h) => h.level === 1);
  const title = firstH1 ? firstH1.text : pageDef.slug;

  const html = renderPage({
    title,
    bodyHtml,
    navHtml: buildNavHtml(pageDef.slug),
  });

  return { path: path.join(OUT_DIR, `${pageDef.slug}.html`), html, title };
}

/**
 * Builds every page and writes docs/.site/. Returns the list of
 * {path, title} written, for the CLI summary below.
 *
 * @returns {Array<{ path: string, title: string }>}
 */
export function build() {
  rmSync(OUT_DIR, { recursive: true, force: true });
  mkdirSync(OUT_DIR, { recursive: true });

  const written = [];
  for (const pageDef of [INDEX_PAGE, ...PAGES]) {
    const { path: outPath, html, title } = buildPage(pageDef);
    writeFileSync(outPath, html, 'utf8');
    written.push({ path: outPath, title });
  }

  return written;
}

// Run when invoked directly (`node build-docs-site.mjs`), not when imported.
if (process.argv[1] === __filename) {
  const written = build();
  console.log(`build-docs-site: wrote ${written.length} pages to ${path.relative(REPO_ROOT, OUT_DIR)}/`);
  for (const { path: outPath, title } of written) {
    console.log(`  - ${path.relative(REPO_ROOT, outPath)}  (${title})`);
  }
}
