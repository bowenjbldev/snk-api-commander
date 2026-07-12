export const slugify = (value: string) =>
  value.toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '')

export const optionalLimit = (value: string): number | null => {
  const n = Number(value)
  return value.trim() && Number.isFinite(n) && n >= 0 ? Math.round(n) : null
}

export const validEmail = (value: string) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
