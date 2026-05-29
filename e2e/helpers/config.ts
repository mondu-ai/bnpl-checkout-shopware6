export const SW_ADMIN_USER = process.env.SW_ADMIN_USER || 'demo'
export const SW_ADMIN_PASS = process.env.SW_ADMIN_PASS || 'demo'

export function getAdminUrl(baseURL: string): string {
  return `${baseURL}/admin`
}

export function getApiUrl(baseURL: string): string {
  return `${baseURL}/api`
}

export function getVariant(projectName: string): 'sw66' | 'sw67' {
  return projectName === 'sw67' ? 'sw67' : 'sw66'
}

export function getContainerName(projectName: string): string {
  return projectName === 'sw67' ? 'shop-sw67' : 'shop-sw66'
}
