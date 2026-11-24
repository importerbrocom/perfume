import { useState } from 'react';
import { useAuth } from '../AdminApp';
import { adminApi, fieldErrors } from '../lib';
import { Card, PageHead, Text, useUi } from '../ui';

export default function Account() {
  const { user } = useAuth();
  const { notify } = useUi();
  const [f, setF] = useState({ current_password: '', password: '', password_confirmation: '' });
  const [errors, setErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const save = async (e) => {
    e.preventDefault();
    if (f.password !== f.password_confirmation) return setErrors({ password_confirmation: 'The two new passwords are different.' });
    setBusy(true);
    setErrors({});
    try {
      await adminApi('me/password', { method: 'PUT', body: f });
      setF({ current_password: '', password: '', password_confirmation: '' });
      notify('Password changed. Other devices were logged out.');
    } catch (err) {
      setErrors(fieldErrors(err));
      notify(err.message, 'error');
    } finally {
      setBusy(false);
    }
  };
  return (
    <>
      <PageHead title="My account" sub={`${user.name} · ${user.email} · ${user.role === 'admin' ? 'Admin' : 'Manager'}`} />
      <Card title="Change password">
        <form className="ad-form" onSubmit={save}>
          <Text label="Current password" type="password" autoComplete="current-password" required value={f.current_password} onChange={(v) => setF({ ...f, current_password: v })} error={errors.current_password} wide />
          <Text label="New password" type="password" autoComplete="new-password" required value={f.password} onChange={(v) => setF({ ...f, password: v })} error={errors.password} help="At least 10 characters with letters and numbers." />
          <Text label="New password again" type="password" autoComplete="new-password" required value={f.password_confirmation} onChange={(v) => setF({ ...f, password_confirmation: v })} error={errors.password_confirmation} />
          <div className="ad-form__foot"><button className="ad-btn" disabled={busy}>Change password</button></div>
        </form>
      </Card>
    </>
  );
}
