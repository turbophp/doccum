/**
 * The file preview's client side: syntax-highlighted source, and Word
 * documents converted in the browser.
 *
 * Both libraries are loaded with dynamic import(), never at module scope, so
 * they become their own chunks and cost nothing on any page that does not open
 * a preview. mammoth alone is larger than the whole shell bundle; charging
 * every page load for it to render the occasional .docx would be a poor trade.
 */

/**
 * Convert a Word document to HTML in the browser.
 *
 * Server-side conversion would mean LibreOffice in the image, which this
 * project has deliberately deferred (item/doc-xls-libreoffice) and which
 * would add hundreds of megabytes to an image already carrying MinIO and
 * tesseract. mammoth reads the .docx's own XML and emits semantic HTML --
 * headings, lists, tables, emphasis -- which is what a preview needs. It
 * cannot reproduce exact page layout, and nothing that runs in a browser can.
 */
async function convertWordDocument(buffer) {
    const mammoth = await import('mammoth/mammoth.browser.js');

    const { value } = await mammoth.convertToHtml({ arrayBuffer: buffer });

    return value;
}

/**
 * Highlight source text, choosing the language from the file's own type.
 *
 * Only the languages a document system plausibly stores are registered.
 * highlight.js ships nearly two hundred, and loading the lot to colour an
 * invoice template is the kind of default that makes a bundle.
 */
async function highlightSource(text, language) {
    const { default: hljs } = await import('highlight.js/lib/core');

    const languages = {
        xml: () => import('highlight.js/lib/languages/xml'),
        json: () => import('highlight.js/lib/languages/json'),
        css: () => import('highlight.js/lib/languages/css'),
        javascript: () => import('highlight.js/lib/languages/javascript'),
        markdown: () => import('highlight.js/lib/languages/markdown'),
        plaintext: () => import('highlight.js/lib/languages/plaintext'),
    };

    const name = languages[language] ? language : 'plaintext';

    if (!hljs.getLanguage(name)) {
        const module = await languages[name]();
        hljs.registerLanguage(name, module.default);
    }

    return hljs.highlight(text, { language: name, ignoreIllegals: true }).value;
}

/** Map a stored content type onto one of the languages registered above. */
function languageFor(mime, name) {
    if (mime.includes('html') || mime.includes('xml') || name.endsWith('.svg')) {
        return 'xml';
    }

    if (mime.includes('json')) {
        return 'json';
    }

    if (mime.includes('css')) {
        return 'css';
    }

    if (mime.includes('javascript') || name.endsWith('.js')) {
        return 'javascript';
    }

    if (name.endsWith('.md') || mime.includes('markdown')) {
        return 'markdown';
    }

    return 'plaintext';
}

export function registerPreview(Alpine) {
    Alpine.data('filePreview', (config) => ({
        tab: config.initialTab ?? 'preview',
        state: 'idle',
        code: '',
        documentHtml: '',
        failure: '',

        init() {
            if (config.kind === 'word') {
                this.load('word');
            } else if (this.tab === 'code') {
                this.load('code');
            }
        },

        show(tab) {
            this.tab = tab;

            if (tab === 'code' && this.code === '' && this.state !== 'loading') {
                this.load('code');
            }
        },

        async load(kind) {
            this.state = 'loading';
            this.failure = '';

            try {
                const response = await fetch(config.url, { credentials: 'same-origin' });

                if (!response.ok) {
                    throw new Error(`the server answered ${response.status}`);
                }

                if (kind === 'word') {
                    this.documentHtml = await convertWordDocument(await response.arrayBuffer());
                } else {
                    this.code = await highlightSource(
                        await response.text(),
                        languageFor(config.mime ?? '', config.name ?? ''),
                    );
                }

                this.state = 'ready';
            } catch (error) {
                // Said out loud rather than left as an empty pane: "nothing
                // happened" is the one thing a viewer must never mean.
                this.state = 'failed';
                this.failure = error?.message ?? String(error);
            }
        },

        /**
         * The converted document, wrapped for an iframe's srcdoc.
         *
         * It goes into a sandboxed frame rather than straight into the page:
         * mammoth's output comes from somebody's upload, and an uploaded
         * document is not something to inject into the application's own DOM.
         */
        get documentFrame() {
            return `<!doctype html><meta charset="utf-8"><style>
                body { font: 14px/1.6 system-ui, sans-serif; color: #1f252b; margin: 0; padding: 24px; }
                table { border-collapse: collapse; } td, th { border: 1px solid #d3d8de; padding: 4px 8px; }
                img { max-width: 100%; height: auto; }
            </style>${this.documentHtml}`;
        },
    }));
}
