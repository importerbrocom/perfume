import { useEffect } from 'react';

function meta(name, content, attr = 'name') {
  let el = document.head.querySelector(`meta[${attr}="${name}"]`);
  if (!el) {
    el = document.createElement('meta');
    el.setAttribute(attr, name);
    document.head.appendChild(el);
  }
  el.setAttribute('content', content || '');
}

/** Page title, description, canonical, Open Graph and optional JSON-LD. */
export function useSeo({ title, description, image, jsonLd, noindex } = {}) {
  useEffect(() => {
    const full = title ? `${title} | Perfumarathi` : 'Perfumarathi & Fixnote | Premium Perfumes Dubai UAE';
    document.title = full;
    if (description) meta('description', description);
    meta('og:title', full, 'property');
    meta('og:description', description || '', 'property');
    meta('og:type', jsonLd?.['@type'] === 'Product' ? 'product' : 'website', 'property');
    if (image) meta('og:image', image, 'property');
    meta('robots', noindex ? 'noindex,nofollow' : 'index,follow');
    let canon = document.head.querySelector('link[rel="canonical"]');
    if (!canon) {
      canon = document.createElement('link');
      canon.rel = 'canonical';
      document.head.appendChild(canon);
    }
    canon.href = window.location.origin + window.location.pathname;
    let script = document.getElementById('pm-jsonld');
    if (jsonLd) {
      if (!script) {
        script = document.createElement('script');
        script.type = 'application/ld+json';
        script.id = 'pm-jsonld';
        document.head.appendChild(script);
      }
      script.textContent = JSON.stringify(jsonLd);
    } else if (script) script.remove();
  }, [title, description, image, noindex, JSON.stringify(jsonLd || null)]);
}
