// Allow-list HTML cleaner for product descriptions / policy pages (defence in depth: content comes from staff).
const TAGS = new Set(['P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'UL', 'OL', 'LI', 'H2', 'H3', 'H4', 'A', 'SPAN', 'BLOCKQUOTE', 'HR']);

export function sanitizeHtml(html) {
  if (!html) return '';
  const doc = new DOMParser().parseFromString(`<div>${html}</div>`, 'text/html');
  const walk = (node) => {
    for (const el of [...node.children]) {
      if (!TAGS.has(el.tagName)) {
        if (['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'FORM', 'TEMPLATE', 'SVG', 'MATH'].includes(el.tagName.toUpperCase())) el.remove();
        else {
          walk(el); // clean the children before lifting them out of the unknown tag
          el.replaceWith(...el.childNodes);
        }
        continue;
      }
      for (const a of [...el.attributes]) {
        const keep = el.tagName === 'A' && a.name === 'href' && /^(https?:|mailto:|\/)/i.test(a.value.trim());
        if (!keep) el.removeAttribute(a.name);
      }
      if (el.tagName === 'A') {
        el.setAttribute('rel', 'noopener noreferrer');
        if (/^https?:/i.test(el.getAttribute('href') || '')) el.setAttribute('target', '_blank');
      }
      walk(el);
    }
  };
  const root = doc.body.firstChild;
  walk(root);
  return root.innerHTML;
}

/** Plain text with line breaks -> paragraphs (for policies typed in a text box). */
export function textToHtml(text) {
  if (!text) return '';
  if (/<[a-z][\s\S]*>/i.test(text)) return sanitizeHtml(text);
  const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
  return text.split(/\n{2,}/).map((p) => `<p>${esc(p).replace(/\n/g, '<br>')}</p>`).join('');
}
