import { Link } from 'react-router'

export function NotFound() {
  return (
    <div className="scns-page">
      <h1 style={{ fontSize: 24, fontWeight: 400, margin: 0 }}>Page not found</h1>
      <p className="scns-helper" style={{ marginTop: 4 }}>
        The page you’re looking for doesn’t exist.
      </p>
      <p style={{ marginTop: 24 }}>
        <Link className="scns-link" to="/dashboard">
          Back to dashboard
        </Link>
      </p>
    </div>
  )
}
