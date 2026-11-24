import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import { useStore } from '../lib/store';
import { useSeo } from '../lib/seo';
import { waLink } from '../lib/format';
import BagButton from '../components/BagButton';
import ProductGrid from '../components/ProductGrid';
import Socials from '../components/Socials';
import Announcement from '../components/Announcement';

function Video({ src, poster, label }) {
  const ref = useRef(null);
  const [muted, setMuted] = useState(true);
  useEffect(() => {
    const v = ref.current;
    if (!v) return undefined;
    v.muted = true;
    v.play()?.catch?.(() => {});
    const f = () => setMuted(v.muted);
    v.addEventListener('volumechange', f);
    return () => v.removeEventListener('volumechange', f);
  }, [src]);
  const toggle = () => {
    const v = ref.current;
    v.muted = !v.muted;
    if (v.paused) v.play()?.catch?.(() => {});
  };
  return (
    <div className="pf-video-frame">
      <video ref={ref} className="pf-video" autoPlay muted loop playsInline preload="metadata" poster={poster} aria-label={label}>
        <source src={src} type="video/mp4" />
        Your browser does not support HTML5 video.
      </video>
      <button type="button" className="pf-sound" aria-label={muted ? 'Turn video sound on' : 'Turn video sound off'} aria-pressed={!muted} onClick={toggle}>
        {muted ? '🔇 UNMUTE' : '🔊 MUTE'}
      </button>
    </div>
  );
}

function Banners({ banners }) {
  if (!banners?.length) return null;
  return (
    <section className="pm-banners" aria-label="Offers">
      {banners.map((b) => {
        const inner = (
          <>
            <picture>
              {b.mobile_image_url && <source media="(max-width: 767px)" srcSet={b.mobile_image_url} />}
              <img src={b.image_url} alt={b.title || ''} loading="lazy" />
            </picture>
            {(b.title || b.subtitle || b.button_text) && (
              <div className="pm-banner__text">
                {b.title && <h2>{b.title}</h2>}
                {b.subtitle && <p>{b.subtitle}</p>}
                {b.button_text && <span className="pf-enter">{b.button_text} <span aria-hidden="true">→</span></span>}
              </div>
            )}
          </>
        );
        return b.link_url ? (
          b.link_url.startsWith('/') ? <Link key={b.id} className="pm-banner" to={b.link_url}>{inner}</Link> : <a key={b.id} className="pm-banner" href={b.link_url}>{inner}</a>
        ) : (
          <div key={b.id} className="pm-banner">{inner}</div>
        );
      })}
    </section>
  );
}

/** The Perfumarathi & Fixnote brand homepage (same layout as the live site) with the shop sections. */
export default function Home() {
  const { store } = useStore();
  const s = store?.settings || {};
  const [home, setHome] = useState(null);
  useEffect(() => {
    api('home').then(setHome).catch(() => setHome({ banners: [], promos: [], sections: [] }));
  }, []);
  useSeo({ description: 'Discover Perfumarathi & Fixnote perfumes in Dubai, UAE. Long-lasting fragrances, inspired perfumes and special offers.' });
  const wa = waLink(s.whatsapp_number);
  const hasAnnouncements = (store?.announcements || []).length > 0;
  return (
    <div id="pf-brand-home">
      {hasAnnouncements && <Announcement />}
      <div className="pf-bag-fixed"><BagButton /></div>
      <section className="pf-video-section" aria-label="Perfumarathi perfume spray video">
        <h2 className="pf-video-title">{s.home_title || 'THE ART OF FRAGRANCE'}</h2>
        <p className="pf-video-caption">{s.home_caption || 'Experience the scent'}</p>
        {s.home_video_url && <Video src={s.home_video_url} poster={s.home_poster_url} label="Perfumarathi perfume spray video" />}
      </section>
      <div className="pf-brand-section">
        <main className="pf-halves" id="pmt-content">
          <section className="pf-half" aria-label="Perfumarathi">
            <div className="pf-inner">
              <p className="pf-eyebrow">{s.perfumarathi_eyebrow}</p>
              <div className="pf-logo-wrap">
                {s.perfumarathi_logo_url && <img decoding="async" className="pf-logo pf-logo-perf" src={s.perfumarathi_logo_url} alt="Perfumarathi — Secret of Attractiveness" />}
              </div>
              <p className="pf-subtitle">{s.perfumarathi_subtitle}</p>
              <Link className="pf-enter" to="/shop-perfumarathi" aria-label="Shop Perfumarathi">SHOP PERFUMARATHI <span aria-hidden="true">→</span></Link>
              <p className="pf-foot">{s.perfumarathi_foot}</p>
            </div>
          </section>
          <section className="pf-half" aria-label="Fixnote">
            <div className="pf-inner">
              <p className="pf-eyebrow">{s.fixnote_eyebrow}</p>
              <div className="pf-logo-wrap">
                {s.fixnote_logo_url && <img decoding="async" className="pf-logo pf-logo-fix" src={s.fixnote_logo_url} alt="Fixnote Fragrances" />}
              </div>
              <p className="pf-subtitle">{s.fixnote_subtitle}</p>
              <Link className="pf-enter" to="/shop-fixnote" aria-label="Shop Fixnote">SHOP FIXNOTE <span aria-hidden="true">→</span></Link>
              <p className="pf-foot">{s.fixnote_foot}</p>
            </div>
          </section>
        </main>
      </div>

      <Banners banners={home?.banners} />
      <div className="pm-home">
        {!home && (
          <section className="pm-home__section">
            <h2 className="pm-home__title">&nbsp;</h2>
            <ProductGrid products={[]} loading skeletons={4} rail />
          </section>
        )}
        {home?.sections.map((sec) => (
          <section key={sec.id} className="pm-home__section" aria-label={sec.title}>
            <h2 className="pm-home__title">{sec.title}</h2>
            {sec.subtitle && <p className="pmc-sub">{sec.subtitle}</p>}
            <ProductGrid products={sec.products} rail />
          </section>
        ))}
        <Banners banners={home?.promos} />
      </div>

      <section className="pf-social-section" aria-label="Perfumarathi social media">
        <h2 className="pf-social-title">FOLLOW {(s.store_name || 'Perfumarathi').toUpperCase()}</h2>
        <p className="pf-social-sub">Connect with us on social media</p>
        <Socials className="pf-socials" withIcons />
        {wa && <a className="pf-contact" href={wa} target="_blank" rel="noopener noreferrer">WhatsApp: {s.contact_phone}</a>}
      </section>
      <footer className="pf-bottom">
        <p className="pf-presence">{s.presence_text}</p>
        <p className="pf-copyright">
          © {new Date().getFullYear()} Perfumarathi &amp; Fixnote. All rights reserved. ·{' '}
          <Link to="/track-order">Track order</Link>
          {s.has_terms && <> · <Link to="/pages/terms">Terms</Link></>}
          {s.has_privacy_policy && <> · <Link to="/pages/privacy">Privacy</Link></>}
        </p>
      </footer>
    </div>
  );
}
