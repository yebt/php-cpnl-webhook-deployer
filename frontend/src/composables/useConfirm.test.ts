import { afterEach, describe, expect, it } from 'vitest'
import { resetConfirmForTests, useConfirm, useConfirmHost } from './useConfirm'

describe('useConfirm', () => {
  afterEach(() => resetConfirmForTests())

  it('opens a dialog with the requested options and defaults', () => {
    const confirm = useConfirm()
    const host = useConfirmHost()

    void confirm({ title: 'Delete "api"?', message: 'Run history is removed.', danger: true, confirmLabel: 'Delete project' })

    expect(host.current.value).toMatchObject({
      title: 'Delete "api"?',
      message: 'Run history is removed.',
      danger: true,
      confirmLabel: 'Delete project',
      cancelLabel: 'Cancel',
    })
  })

  it('uses "Confirm" and a non-danger variant by default', () => {
    void useConfirm()({ title: 'Apply?' })
    expect(useConfirmHost().current.value).toMatchObject({ confirmLabel: 'Confirm', cancelLabel: 'Cancel', danger: false })
  })

  it('resolves true when confirmed and closes the dialog', async () => {
    const answer = useConfirm()({ title: 'Go?' })
    const host = useConfirmHost()

    host.settle(true)

    await expect(answer).resolves.toBe(true)
    expect(host.current.value).toBeNull()
  })

  it('resolves false when cancelled (never rejects)', async () => {
    const answer = useConfirm()({ title: 'Go?' })
    useConfirmHost().settle(false)
    await expect(answer).resolves.toBe(false)
  })

  it('queues requests made while a dialog is open and shows them in order', async () => {
    const confirm = useConfirm()
    const host = useConfirmHost()
    const first = confirm({ title: 'First' })
    const second = confirm({ title: 'Second' })

    expect(host.current.value?.title).toBe('First')
    host.settle(false)
    await expect(first).resolves.toBe(false)

    expect(host.current.value?.title).toBe('Second')
    host.settle(true)
    await expect(second).resolves.toBe(true)
    expect(host.current.value).toBeNull()
  })

  it('ignores a settle with nothing open', () => {
    expect(() => useConfirmHost().settle(true)).not.toThrow()
    expect(useConfirmHost().current.value).toBeNull()
  })

  it('cancels every pending request when the host is torn down', async () => {
    const confirm = useConfirm()
    const first = confirm({ title: 'First' })
    const second = confirm({ title: 'Second' })

    useConfirmHost().cancelAll()

    await expect(first).resolves.toBe(false)
    await expect(second).resolves.toBe(false)
    expect(useConfirmHost().current.value).toBeNull()
  })
})
