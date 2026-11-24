import { Outlet } from 'react-router-dom';
import Header from './Header';
import Footer from './Footer';

export default function Layout() {
  return (
    <div className="pmt-wrap">
      <a className="skip-link screen-reader-text" href="#pmt-content">Skip to content</a>
      <Header />
      <main id="pmt-content" className="pmt-main">
        <Outlet />
      </main>
      <Footer />
    </div>
  );
}
