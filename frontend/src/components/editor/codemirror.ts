/**
 * CodeMirror 6 setup for YAML, loaded on demand (dynamic import) so the editor's ~100 KB
 * gzipped only downloads on the instructions screen.
 */
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands'
import { yaml } from '@codemirror/lang-yaml'
import { bracketMatching, HighlightStyle, indentOnInput, syntaxHighlighting } from '@codemirror/language'
import { EditorState } from '@codemirror/state'
import { drawSelection, EditorView, highlightActiveLine, highlightActiveLineGutter, keymap, lineNumbers } from '@codemirror/view'
import { tags } from '@lezer/highlight'

const theme = EditorView.theme(
  {
    '&': { backgroundColor: '#0a0b0d', color: '#d7dbe0', fontSize: '13px', height: '100%' },
    '.cm-scroller': { fontFamily: 'var(--mono)', lineHeight: '1.6' },
    '.cm-content': { caretColor: '#5eead4', padding: '10px 0' },
    '.cm-cursor': { borderLeftColor: '#5eead4' },
    '.cm-gutters': { backgroundColor: '#0a0b0d', color: '#5c636d', border: 'none' },
    '.cm-activeLine': { backgroundColor: 'rgba(255,255,255,0.035)' },
    '.cm-activeLineGutter': { backgroundColor: 'rgba(255,255,255,0.05)', color: '#9aa1ab' },
    '&.cm-focused .cm-selectionBackground, .cm-selectionBackground, ::selection': { backgroundColor: 'rgba(94,234,212,0.22)' },
    '&.cm-focused': { outline: 'none' },
  },
  { dark: true },
)

const highlight = HighlightStyle.define([
  { tag: [tags.propertyName, tags.definition(tags.propertyName)], color: '#7ee7d6' },
  { tag: [tags.string, tags.special(tags.string)], color: '#e6c07b' },
  { tag: [tags.number, tags.bool, tags.null], color: '#d19a66' },
  { tag: tags.comment, color: '#6b737d', fontStyle: 'italic' },
  { tag: [tags.keyword, tags.operator, tags.punctuation, tags.separator], color: '#9aa1ab' },
  { tag: tags.meta, color: '#c678dd' },
])

export interface EditorHandle {
  view: EditorView
  setValue(value: string): void
  destroy(): void
}

export function createYamlEditor(
  parent: HTMLElement,
  initial: string,
  options: { label: string; onChange: (value: string) => void; onSave: () => void },
): EditorHandle {
  const view = new EditorView({
    parent,
    state: EditorState.create({
      doc: initial,
      extensions: [
        lineNumbers(),
        highlightActiveLineGutter(),
        highlightActiveLine(),
        drawSelection(),
        history(),
        indentOnInput(),
        bracketMatching(),
        yaml(),
        syntaxHighlighting(highlight),
        EditorState.tabSize.of(2),
        keymap.of([
          { key: 'Mod-s', preventDefault: true, run: () => (options.onSave(), true) },
          ...defaultKeymap,
          ...historyKeymap,
          indentWithTab,
        ]),
        EditorView.contentAttributes.of({ 'aria-label': options.label }),
        EditorView.updateListener.of((update) => {
          if (update.docChanged) options.onChange(update.state.doc.toString())
        }),
        theme,
      ],
    }),
  })

  return {
    view,
    setValue(value: string) {
      if (value === view.state.doc.toString()) return
      view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: value } })
    },
    destroy: () => view.destroy(),
  }
}
