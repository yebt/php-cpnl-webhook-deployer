import { describe, expect, it } from 'vitest'
import { isRetryable, type RunStatus } from './types'

describe('isRetryable', () => {
  it('allows retrying only runs that ended failed or cancelled', () => {
    const statuses: RunStatus[] = ['queued', 'running', 'success', 'failed', 'cancelled']
    expect(statuses.filter(isRetryable)).toEqual(['failed', 'cancelled'])
  })
})
