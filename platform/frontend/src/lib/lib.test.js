import { describe, expect, it } from 'vitest';
import { sanitizeHtml, textToHtml } from './sanitize';
import { money, paymentLabel, priceRange, statusLabel, waLink } from './format';

describe('sanitizeHtml', () => {
  it('removes scripts, event handlers and unsafe links', () => {
    const out = sanitizeHtml('<p onclick="x()">Hi<script>alert(1)</script></p><a href="javascript:alert(1)">bad</a><img src=x onerror=alert(1)>');
    expect(out).toBe('<p>Hi</p><a rel="noopener noreferrer">bad</a>');
  });
  it('keeps allowed formatting and safe external links', () => {
    const out = sanitizeHtml('<h3>Notes</h3><ul><li><strong>Oud</strong></li></ul><a href="https://example.com">x</a>');
    expect(out).toContain('<h3>Notes</h3><ul><li><strong>Oud</strong></li></ul>');
    expect(out).toContain('href="https://example.com"');
    expect(out).toContain('target="_blank"');
  });
  it('unwraps unknown tags but keeps their text', () => {
    expect(sanitizeHtml('<div><section>Text</section></div>')).toBe('Text');
  });
  it('cleans tags nested inside unwrapped tags', () => {
    const out = sanitizeHtml('<div><a href="javascript:alert(1)" onclick="x">a</a><section><p style="x">b</p></section><svg><a href="javascript:1">c</a></svg></div>');
    expect(out).toBe('<a rel="noopener noreferrer">a</a><p>b</p>');
  });
});

describe('textToHtml', () => {
  it('escapes plain text and turns blank lines into paragraphs', () => {
    expect(textToHtml('Line <1>\nsame\n\nNext')).toBe('<p>Line &lt;1&gt;<br>same</p><p>Next</p>');
  });
  it('sanitizes when the text already contains HTML', () => {
    expect(textToHtml('<p>Ok</p><script>x</script>')).toBe('<p>Ok</p>');
  });
});

describe('format', () => {
  it('formats AED amounts', () => {
    const s = money(1234.5, 'AED');
    expect(s).toMatch(/1,234\.50/);
    expect(s).toMatch(/AED/);
    expect(money(null)).toBe('');
  });
  it('shows a range only when prices differ', () => {
    expect(priceRange({ priced: false })).toBeNull();
    expect(priceRange({ priced: true, price_min: 50, price_max: 50 }, 'AED')).not.toContain('–');
    expect(priceRange({ priced: true, price_min: 50, price_max: 90 }, 'AED')).toContain('–');
  });
  it('labels statuses', () => {
    expect(statusLabel('pending')).toBe('Order placed');
    expect(paymentLabel('cod_pending')).toBe('Pay on delivery');
    expect(statusLabel('custom')).toBe('custom');
  });
  it('builds WhatsApp links from any phone format', () => {
    expect(waLink('+971 50-123 4567', 'Hi there')).toBe('https://wa.me/971501234567?text=Hi%20there');
    expect(waLink('')).toBeNull();
  });
});
