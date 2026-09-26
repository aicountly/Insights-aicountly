import { useState } from 'react'
import { buildLocalAppIconUrl, buildProductIconUrl } from '../services/appLauncher'

/**
 * The Insights app mark, in the sidebar.
 *
 * WHY THIS IS NOT AN <img src="/apps/insights.png">. There is no Insights tile
 * in this repository and none in Manage's product-icon store yet, and drawing
 * one here would be inventing a trademark. The launcher already solves this:
 * product icons are served centrally from Console so an update propagates to
 * every product at once, and a product whose art has not shipped falls back to
 * its accent tile.
 *
 * So this tries the same two sources the launcher tries, in the same order, and
 * ends at the accent tile — which is a deliberate placeholder for art that does
 * not exist, not a substitute for art that does. The day the tile is uploaded
 * to Console, this picks it up with no change here.
 */
export function ProductMark({ size = 34, appId = 'insights', label = 'I' }: { size?: number; appId?: string; label?: string }) {
  const [source, setSource] = useState<'remote' | 'local' | 'initial'>('remote')

  if (source === 'initial') {
    return (
      <span
        aria-hidden
        style={{
          display: 'grid',
          placeItems: 'center',
          width: size,
          height: size,
          flex: 'none',
          borderRadius: 9,
          background: 'var(--accent)',
          color: 'var(--accent-fg)',
          fontWeight: 700,
          fontSize: size * 0.45,
          letterSpacing: '-0.02em',
        }}
      >
        {label}
      </span>
    )
  }

  return (
    <img
      src={source === 'remote' ? buildProductIconUrl(appId) : buildLocalAppIconUrl(appId)}
      alt=""
      aria-hidden
      width={size}
      height={size}
      style={{ borderRadius: 9, flex: 'none' }}
      onError={() => setSource((current) => (current === 'remote' ? 'local' : 'initial'))}
    />
  )
}
