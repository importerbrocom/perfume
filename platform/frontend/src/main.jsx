import { StrictMode, Suspense, lazy, useEffect } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Route, Routes, useLocation } from 'react-router-dom';
import { StoreProvider } from './lib/store';
import { ToastProvider } from './lib/toast';
import { CartProvider } from './lib/cart';
import Layout from './components/Layout';
import CartDrawer from './components/CartDrawer';
import Home from './pages/Home';
import Shop from './pages/Shop';
import Product from './pages/Product';
import Cart from './pages/Cart';
import Wishlist from './pages/Wishlist';
import Page from './pages/Page';
import NotFound from './pages/NotFound';
import './styles/brand-home.css';
import './styles/theme.css';
import './styles/shop.css';
import './styles/app.css';

// Pages that pull in Stripe.js load on demand so the shop pages stay light.
const Checkout = lazy(() => import('./pages/Checkout'));
const Order = lazy(() => import('./pages/Order'));
const Track = lazy(() => import('./pages/Track'));
const AdminPlaceholder = lazy(() => import('./pages/AdminPlaceholder'));

function ScrollToTop() {
  const { pathname } = useLocation();
  useEffect(() => {
    window.scrollTo(0, 0);
  }, [pathname]);
  return null;
}

const loading = <div className="pmt-container pmt-entry"><p className="pm-note">Loading…</p></div>;

export function App() {
  return (
    <StoreProvider>
      <ToastProvider>
        <CartProvider>
          <ScrollToTop />
          <Suspense fallback={loading}>
            <Routes>
              <Route path="/" element={<Home />} />
              <Route element={<Layout />}>
                <Route path="/shop" element={<Shop />} />
                <Route path="/shop-perfumarathi" element={<Shop key="perfumarathi" brand="perfumarathi" />} />
                <Route path="/shop-fixnote" element={<Shop key="fixnote" brand="fixnote" />} />
                <Route path="/brand/:brand" element={<Shop />} />
                <Route path="/category/:category" element={<Shop />} />
                <Route path="/product/:slug" element={<Product />} />
                <Route path="/cart" element={<Cart />} />
                <Route path="/checkout" element={<Checkout />} />
                <Route path="/order/:number" element={<Order />} />
                <Route path="/track-order" element={<Track />} />
                <Route path="/wishlist" element={<Wishlist />} />
                <Route path="/pages/:key" element={<Page />} />
                <Route path="/admin/*" element={<AdminPlaceholder />} />
                <Route path="*" element={<NotFound />} />
              </Route>
            </Routes>
          </Suspense>
          <CartDrawer />
        </CartProvider>
      </ToastProvider>
    </StoreProvider>
  );
}

const root = document.getElementById('root');
if (root) {
  createRoot(root).render(
    <StrictMode>
      <BrowserRouter>
        <App />
      </BrowserRouter>
    </StrictMode>,
  );
}
