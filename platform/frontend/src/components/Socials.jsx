import { useStore } from '../lib/store';
import { waLink } from '../lib/format';
import { SOCIAL_ICONS } from './Icons';

/** Social links from Store Settings (only the ones that are filled in). */
export function useSocials() {
  const s = useStore()?.store?.settings || {};
  return [
    ['instagram', 'Instagram', s.social_instagram],
    ['facebook', 'Facebook', s.social_facebook],
    ['tiktok', 'TikTok', s.social_tiktok],
    ['youtube', 'YouTube', s.social_youtube],
    ['whatsapp', 'WhatsApp', waLink(s.whatsapp_number)],
  ].filter(([, , url]) => url);
}

export default function Socials({ className, withIcons = false }) {
  const links = useSocials();
  return (
    <nav className={className} aria-label="Social media links">
      {links.map(([k, label, url]) => (
        <a key={k} className={withIcons ? 'pf-social' : undefined} href={url} target="_blank" rel="noopener noreferrer">
          {withIcons && SOCIAL_ICONS[k]}
          {label}
        </a>
      ))}
    </nav>
  );
}
