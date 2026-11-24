import { createContext, lazy, Suspense, useCallback, useContext, useEffect, useState } from 'react';
import { NavLink, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom';
import { adminApi, csrf } from './lib';
import { Loading, UiProvider, useUi } from './ui';
import { useSeo } from '../lib/seo';
import Login from './pages/Login';
import Dashboard from './pages/Dashboard';
import './admin.css';

const Orders = lazy(() => import('./pages/Orders'));
const OrderDetail = lazy(() => import('./pages/OrderDetail'));
const Products = lazy(() => import('./pages/Products'));
const ProductEdit = lazy(() => import('./pages/ProductEdit'));
const Inventory = lazy(() => import('./pages/Inventory'));
const Customers = lazy(() => import('./pages/Customers'));
const Reviews = lazy(() => import('./pages/Reviews'));
const Content = lazy(() => import('./pages/Content'));
const Settings = lazy(() => import('./pages/Settings'));
const Payments = lazy(() => import('./pages/Payments'));
const Account = lazy(() => import('./pages/Account'));
const FixnoteAdmin = lazy(() => import('./pages/Fixnote'));

const AuthContext = createContext(null);
export const useAuth = () => useContext(AuthContext);

const NAV = [
  ['Store', [
    ['/admin', 'Dashboard', true],
    ['/admin/orders', 'Orders'],
    ['/admin/products', 'Products'],
    ['/admin/stock', 'Stock & Prices'],
    ['/admin/customers', 'Customers'],
    ['/admin/reviews', 'Reviews'],
  ]],
  ['Fixnote', [
    ['/admin/fixnote', 'Fixnote page', true],
    ['/admin/fixnote/products', 'Fixnote perfumes'],
    ['/admin/fixnote/orders', 'Fixnote orders'],
  ]],
  ['Marketing', [
    ['/admin/coupons', 'Coupons'],
    ['/admin/banners', 'Banners'],
    ['/admin/home-sections', 'Homepage sections'],
    ['/admin/announcements', 'Announcement bar'],
  ]],
  ['Catalogue', [
    ['/admin/brands', 'Brands'],
    ['/admin/categories', 'Categories'],
  ]],
  ['Admin', [
    ['/admin/settings', 'Store settings', false, 'admin'],
    ['/admin/payments', 'Payment methods', false, 'admin'],
    ['/admin/staff', 'Staff accounts', false, 'admin'],
    ['/admin/account', 'My account'],
  ]],
];

function Shell({ children }) {
  const { user, logout } = useAuth();
  const [open, setOpen] = useState(false);
  const { pathname } = useLocation();
  useEffect(() => setOpen(false), [pathname]);
  useEffect(() => {
    if (!open) return undefined;
    const f = (e) => e.key === 'Escape' && setOpen(false);
    document.addEventListener('keydown', f);
    return () => document.removeEventListener('keydown', f);
  }, [open]);
  return (
    <div className={'ad' + (open ? ' ad--menu' : '')}>
      <header className="ad-top">
        <button type="button" className="ad-burger" aria-label={open ? 'Close menu' : 'Open menu'} aria-expanded={open} aria-controls="ad-nav" onClick={() => setOpen(!open)}>
          <span /><span /><span />
        </button>
        <NavLink to="/admin" className="ad-brand" end>PERFUMARATHI <small>Admin</small></NavLink>
        <div className="ad-top__right">
          <a className="ad-link" href="/" target="_blank" rel="noopener noreferrer">View shop ↗</a>
          <span className="ad-user" title={user.email}>{user.name} <small>{user.role}</small></span>
          <button type="button" className="ad-btn ad-btn--ghost ad-btn--sm" onClick={logout}>Log out</button>
        </div>
      </header>
      <nav id="ad-nav" className="ad-nav" aria-label="Admin menu">
        {NAV.map(([group, items]) => (
          <div key={group} className="ad-nav__group">
            <p>{group}</p>
            {items.filter((i) => !i[3] || i[3] === user.role).map(([to, label, end]) => (
              <NavLink key={to} to={to} end={end}>{label}</NavLink>
            ))}
          </div>
        ))}
      </nav>
      {open && <div className="ad-scrim" onClick={() => setOpen(false)} aria-hidden="true" />}
      <main className="ad-main" id="ad-main">
        <Suspense fallback={<Loading />}>{children}</Suspense>
      </main>
    </div>
  );
}

function AdminOnly({ children }) {
  return useAuth().user.role === 'admin' ? children : <p className="ad-alert">Only an admin can open this page.</p>;
}

function AuthProvider({ children }) {
  const [user, setUser] = useState(undefined); // undefined = checking, null = logged out
  const { notify } = useUi();
  const navigate = useNavigate();
  useEffect(() => {
    csrf();
    adminApi('me').then((d) => setUser(d.user)).catch(() => setUser(null));
  }, []);
  useEffect(() => {
    const f = () => {
      setUser((u) => {
        if (u) notify('Your session ended. Please log in again.', 'error');
        return null;
      });
    };
    window.addEventListener('pm-admin-logout', f);
    return () => window.removeEventListener('pm-admin-logout', f);
  }, [notify]);
  const login = useCallback(async (email, password, remember) => {
    await csrf(true);
    const d = await adminApi('login', { method: 'POST', body: { email, password, remember } });
    await csrf(true); // the session id changed after login
    setUser(d.user);
  }, []);
  const logout = useCallback(async () => {
    try {
      await adminApi('logout', { method: 'POST' });
    } catch {
      /* already logged out */
    }
    await csrf(true);
    setUser(null);
    navigate('/admin');
  }, [navigate]);
  return <AuthContext.Provider value={{ user, setUser, login, logout }}>{children}</AuthContext.Provider>;
}

function Gate() {
  const { user } = useAuth();
  useSeo({ title: 'Store Admin', noindex: true });
  if (user === undefined) return <div className="ad-center"><Loading /></div>;
  if (!user) return <Login />;
  return (
    <Shell>
      <Routes>
        <Route index element={<Dashboard />} />
        <Route path="orders" element={<Orders key="all" />} />
        <Route path="orders/:id" element={<OrderDetail />} />
        <Route path="products" element={<Products key="all" />} />
        <Route path="products/new" element={<ProductEdit />} />
        <Route path="products/:id" element={<ProductEdit />} />
        <Route path="stock" element={<Inventory />} />
        <Route path="fixnote" element={<FixnoteAdmin />} />
        <Route path="fixnote/products" element={<Products key="fixnote" brand="fixnote" />} />
        <Route path="fixnote/orders" element={<Orders key="fixnote" brand="fixnote" />} />
        <Route path="customers" element={<Customers />} />
        <Route path="reviews" element={<Reviews />} />
        {['coupons', 'banners', 'home-sections', 'announcements', 'brands', 'categories'].map((k) => (
          <Route key={k} path={k} element={<Content kind={k} />} />
        ))}
        <Route path="staff" element={<AdminOnly><Content kind="staff" /></AdminOnly>} />
        <Route path="settings" element={<AdminOnly><Settings /></AdminOnly>} />
        <Route path="payments" element={<AdminOnly><Payments /></AdminOnly>} />
        <Route path="account" element={<Account />} />
        <Route path="*" element={<Navigate to="/admin" replace />} />
      </Routes>
    </Shell>
  );
}

export default function AdminApp() {
  return (
    <UiProvider>
      <AuthProvider>
        <Gate />
      </AuthProvider>
    </UiProvider>
  );
}
