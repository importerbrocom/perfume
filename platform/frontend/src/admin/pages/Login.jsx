import { useState } from 'react';
import { useAuth } from '../AdminApp';
import { Check } from '../ui';

export default function Login() {
  const { login } = useAuth();
  const [f, setF] = useState({ email: '', password: '', remember: true });
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);
  const submit = async (e) => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await login(f.email.trim(), f.password, f.remember);
    } catch (err) {
      setError(err.message);
    } finally {
      setBusy(false);
    }
  };
  return (
    <div className="ad-login">
      <form className="ad-card ad-login__box" onSubmit={submit}>
        <p className="ad-brand">PERFUMARATHI <small>Admin</small></p>
        <h1>Log in</h1>
        <div className="ad-field">
          <label htmlFor="ad-email">Email</label>
          <input id="ad-email" type="email" autoComplete="username" required value={f.email} onChange={(e) => setF({ ...f, email: e.target.value })} />
        </div>
        <div className="ad-field">
          <label htmlFor="ad-pass">Password</label>
          <input id="ad-pass" type="password" autoComplete="current-password" required value={f.password} onChange={(e) => setF({ ...f, password: e.target.value })} />
        </div>
        <Check label="Keep me logged in on this device" checked={f.remember} onChange={(v) => setF({ ...f, remember: v })} />
        {error && <p className="ad-alert ad-alert--error" role="alert">{error}</p>}
        <button className="ad-btn ad-btn--block" disabled={busy}>{busy ? 'Logging in…' : 'Log in'}</button>
        <p className="ad-muted">Forgot your password? Ask the store admin to reset it, or run <code>php artisan shop:admin</code> on the server.</p>
      </form>
    </div>
  );
}
