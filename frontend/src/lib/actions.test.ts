import { describe, expect, it } from 'vitest'
import { actionConfirmMessage, actionLabel, actionWebhookPath } from './actions'

describe('action helpers', () => {
  it('labels action runs with their name, falling back to the id', () => {
    expect(actionLabel({ action: 'clear-cache', action_name: 'Clear cache' })).toBe('Clear cache')
    expect(actionLabel({ action: 'clear-cache', action_name: null })).toBe('clear-cache')
    expect(actionLabel({ action: null, action_name: null })).toBeNull()
  })

  it('builds the action webhook path from the project webhook path', () => {
    expect(actionWebhookPath('/hooks/shop', 'clear-cache')).toBe('/hooks/shop/actions/clear-cache')
    expect(actionWebhookPath('/deployer/hooks/shop/', 'a_b')).toBe('/deployer/hooks/shop/actions/a_b')
    expect(actionWebhookPath('/hooks/shop', '<id>')).toBe('/hooks/shop/actions/<id>')
  })

  it('explains what running an action does, listing its steps', () => {
    expect(actionConfirmMessage('Shop', ['Clear', 'Warm up'])).toBe(
      'Runs 2 steps (Clear, Warm up) for "Shop". It starts after any queued or running run of this project.',
    )
    expect(actionConfirmMessage('Shop', ['Clear'])).toBe(
      'Runs 1 step (Clear) for "Shop". It starts after any queued or running run of this project.',
    )
  })
})
