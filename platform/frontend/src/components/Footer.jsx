import { Link, useLocation } from 'react-router-dom';
import { useStore } from '../lib/store';
import { waLink } from '../lib/format';
import Socials from './Socials';

// Pages with forms or their own WhatsApp button: the floating pill would cover them on phones.
const HIDE_STICKY = /^\/(cart|checkout|order|product)(\/|$)/;

/** Footer from the collection page: "Delivery Across" band, links, socials, sticky WhatsApp button. */
export default function Footer() {
  const s = useStore()?.store?.settings || {};
  const { pathname } = useLocation();
  const wa = waLink(s.whatsapp_number, "Hi Perfumarathi, I'd like to get the price and details for your perfumes.");
  const links = [
    ['/shop', 'Shop'],
    ['/track-order', 'Track Order'],
    ['/wishlist', 'Wishlist'],
    ['/cart', 'Cart'],
    s.has_about_us && ['/pages/about', 'About Us'],
    s.has_return_policy && ['/pages/returns', 'Returns & Refunds'],
    s.has_privacy_policy && ['/pages/privacy', 'Privacy Policy'],
    s.has_terms && ['/pages/terms', 'Terms & Conditions'],
  ].filter(Boolean);
  const name = s.store_name || 'Perfumarathi';
  return (
    <>
      <section className="pmt-delivery" aria-label="Delivery">
        <h2>Delivery Across</h2>
        <div className="pmt-places">
          UAE • SAUDI ARABIA • QATAR • KUWAIT • OMAN • BAHRAIN
          <br />
          INDIA / KERALA • GCC • WORLDWIDE
        </div>
        <p>{s.shipping_info ? 'For delivery details and availability, contact us on WhatsApp.' : 'Contact us on WhatsApp for delivery details.'}</p>
        {wa && (
          <a className="pmt-cta" href={wa} target="_blank" rel="noopener noreferrer">
            WHATSAPP {name.toUpperCase()} →
          </a>
        )}
      </section>
      <footer className="pmt-footer">
        <ul className="pmt-footer-links">
          {links.map(([to, label]) => (
            <li key={to}><Link to={to}>{label}</Link></li>
          ))}
        </ul>
        <Socials className="pmt-socials" />
        <div>
          {name.toUpperCase()} • {(s.store_tagline || 'Secret of Attractiveness').toUpperCase()}
          {s.contact_phone && (<><br />WhatsApp: {s.contact_phone}</>)}
          {s.contact_email && (<><br /><a href={`mailto:${s.contact_email}`}>{s.contact_email}</a></>)}
        </div>
        <div style={{ marginTop: 8, fontSize: 12 }}>
          © {new Date().getFullYear()} Perfumarathi &amp; Fixnote. All rights reserved. · {s.presence_text}
        </div>
      </footer>
      {wa && !HIDE_STICKY.test(pathname) && (
        <div className="pmt-sticky">
          <a href={wa} target="_blank" rel="noopener noreferrer">WhatsApp • Get Price &amp; Order</a>
        </div>
      )}
    </>
  );
}
