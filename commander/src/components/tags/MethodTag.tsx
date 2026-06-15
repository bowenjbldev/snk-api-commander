import type { HttpMethod } from '../../store/types'

export function MethodTag({ method }: { method: HttpMethod }) {
  const cls = method === 'GET' ? 'get' : method === 'POST' ? 'post' : 'other'
  return <span className={`scns-method scns-method--${cls}`}>{method}</span>
}
