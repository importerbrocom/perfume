import { useEffect, useState } from 'react';
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom';
import { useStore } from '../lib/store';
import BagButton from './BagButton';
import { MenuIcon } from './Icons';
import Announcement from './Announcement';

/** Brand header: wordmark, menu, search, track order and live bag (same look as the WordPress theme). */
export default function Header() {
  const store = useStore()?.store;
  const s = store?.settings || {};
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState('');
  const navigate = useNavigate();
  const loc = useLocation();
  useEffect(() => setOpen(false), [loc.pathname]);
  useEffect(() => {
    const f = (e) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('keydown', f);
    return () => document.removeEventListener('keydown', f);
  }, []);
  const collections = (store?.categories || []).filter((c) => c.kind === 'collection').slice(0, 2);
  const menu = [
    ['/', 'Home'],
    ['/shop-perfumarathi', 'Perfumarathi'],
    ['/shop-fixnote', 'Fixnote'],
    ...collections.map((c) => [`/category/${c.slug}`, c.name]),
    ['/shop', 'All Perfumes'],
    ['/track-order', 'Track Order'],
  ];
  const submit = (e) => {
    e.preventDefault();
    if (q.trim()) navigate(`/shop?search=${encodeURIComponent(q.trim())}`);
  };
  return (
    <>
      <Announcement />
      <header className="pmt-header">
        <div className="pmt-container">
          <div className="pmt-header-inner">
            <button type="button" className="pmt-icon pmt-menu-toggle" aria-controls="pmt-nav" aria-expanded={open} aria-label={open ? 'Close menu' : 'Open menu'} onClick={() => setOpen((v) => !v)}>
              <MenuIcon />
            </button>
            <Link className="pmt-brand" to="/" aria-label={`${s.store_name || 'Perfumarathi'} home`}>
              {s.logo_url ? (
                <img src={s.logo_url} alt={s.store_name || 'Perfumarathi'} style={{ maxHeight: 56 }} />
              ) : (
                <>
                  <span className="pmt-brand-main">{(s.store_name || 'Perfumarathi').toUpperCase()}</span>
                  <span className="pmt-brand-tag">{(s.store_tagline || 'Secret of Attractiveness').toUpperCase()}</span>
                </>
              )}
            </Link>
            <div className="pmt-actions">
              <form className="pmt-search" role="search" onSubmit={submit}>
                <label className="screen-reader-text" htmlFor="pmt-s">Search perfumes</label>
                <input id="pmt-s" type="search" placeholder="Search perfumes…" value={q} onChange={(e) => setQ(e.target.value)} />
              </form>
              <BagButton />
            </div>
          </div>
        </div>
        <nav id="pmt-nav" className={'pmt-nav' + (open ? ' is-open' : '')} aria-label="Main menu">
          <div className="pmt-container">
            <ul>
              {menu.map(([to, label]) => (
                <li key={to}>
                  <NavLink to={to} end={to === '/'} className={({ isActive }) => (isActive ? 'current-menu-item' : undefined)}>
                    {label}
                  </NavLink>
                </li>
              ))}
            </ul>
          </div>
        </nav>
      </header>
    </>
  );
}
