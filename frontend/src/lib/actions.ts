/** Helpers for project actions (named instruction sets declared under `actions:`). */

/** Display label of an action run; null for a deploy. */
export function actionLabel(run: { action: string | null; action_name: string | null }): string | null {
  if (run.action === null) return null
  return run.action_name ?? run.action
}

/** `POST <webhook path>/actions/<id>` runs that action (same token as the deploy webhook). */
export function actionWebhookPath(webhookPath: string, actionId: string): string {
  return `${webhookPath.replace(/\/+$/, '')}/actions/${actionId}`
}

/** Confirmation text shown before an action is queued from the UI. */
export function actionConfirmMessage(projectName: string, steps: string[]): string {
  const count = `${steps.length} ${steps.length === 1 ? 'step' : 'steps'}`
  return `Runs ${count} (${steps.join(', ')}) for "${projectName}". It starts after any queued or running run of this project.`
}

/** Minimal example shown when a project defines no actions. */
export const ACTIONS_EXAMPLE = `actions:
  clear-cache:
    name: Clear cache
    description: Empties the application cache.
    steps:
      - name: Clear
        run: php artisan cache:clear
`
