import type { Platform } from '../../api/platforms'

/** Connections, opened at one platform's card. */
export const configureLink = (platform: Platform) => `/app/connections#${platform}-connection`
