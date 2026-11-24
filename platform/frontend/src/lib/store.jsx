import { createContext, useContext, useEffect, useState } from 'react';
import { api } from './api';

const StoreContext = createContext(null);

/** Public store settings (name, contacts, socials, homepage texts, categories, enabled payment methods). */
export function StoreProvider({ children }) {
  const [store, setStore] = useState(null);
  const [error, setError] = useState(null);
  useEffect(() => {
    api('store').then(setStore).catch(setError);
  }, []);
  return <StoreContext.Provider value={{ store, error, currency: store?.settings?.currency || 'AED' }}>{children}</StoreContext.Provider>;
}

export const useStore = () => useContext(StoreContext);
