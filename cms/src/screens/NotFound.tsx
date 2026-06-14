import { Link } from 'react-router-dom'
import { Button } from '@carbon/react'
import { EmptyState } from '../components/ui'

export function NotFound() {
  return (
    <div className="sc-page">
      <div style={{ paddingTop: 80 }}>
        <EmptyState
          title="Page not found"
          description="The page you were looking for doesn’t exist."
          action={
            <Button as={Link} to="/">
              Back to dashboard
            </Button>
          }
        />
      </div>
    </div>
  )
}
