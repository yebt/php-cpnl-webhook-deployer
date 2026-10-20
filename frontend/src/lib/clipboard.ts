/**
 * Copies text, falling back to a hidden textarea + execCommand where the Clipboard API is
 * missing or refused (plain-HTTP deployments are not a secure context). Resolves false
 * when both ways fail.
 */
export async function copyText(text: string): Promise<boolean> {
  try {
    if (!navigator.clipboard) throw new Error('Clipboard API unavailable')
    await navigator.clipboard.writeText(text)
    return true
  } catch {
    const area = document.createElement('textarea')
    area.value = text
    area.setAttribute('readonly', '')
    area.style.position = 'fixed'
    area.style.top = '0'
    area.style.opacity = '0'
    const active = document.activeElement instanceof HTMLElement ? document.activeElement : null
    document.body.appendChild(area)
    area.select()
    let copied = false
    try {
      copied = document.execCommand('copy')
    } catch {
      copied = false
    }
    area.remove()
    active?.focus()
    return copied
  }
}
