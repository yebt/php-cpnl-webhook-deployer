/**
 * App-wide "a run reached a terminal status" signal, so views that only poll slowly
 * (the sidebar's recent projects) refresh right away when the run page sees it finish.
 */
type Listener = (runId: number) => void

const listeners = new Set<Listener>()

export function onRunFinished(listener: Listener): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}

export function emitRunFinished(runId: number): void {
  for (const listener of [...listeners]) listener(runId)
}
