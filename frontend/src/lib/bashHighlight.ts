/**
 * A small, forgiving bash tokenizer for colouring the commands shown in run logs.
 *
 * It is not a parser: it recognises enough structure to colour typical deploy scripts
 * (keywords, the command word of each simple command, strings, variables, operators,
 * comments, flags and numbers) and never throws. The tokens always join back into the
 * exact input, so rendering them as text nodes shows the command unchanged.
 */

export type BashTokenType =
  | 'keyword'
  | 'command'
  | 'string'
  | 'variable'
  | 'operator'
  | 'comment'
  | 'flag'
  | 'number'
  | 'text'

export interface BashToken {
  type: BashTokenType
  text: string
}

/** Keywords after which the next word is again a command (`if cmd`, `then cmd`, `! cmd`). */
const LEADING_KEYWORDS = new Set(['if', 'then', 'else', 'elif', 'do', 'while', 'until', '!', '{', 'time', 'coproc'])
/** Keywords that close a compound command; what follows is a separator or a redirection. */
const CLOSING_KEYWORDS = new Set(['fi', 'done', 'esac', '}'])
/** Keywords followed by a name (`for x in`, `case $x in`, `function deploy`). */
const NAME_KEYWORDS = new Set(['for', 'select', 'case', 'function'])

/** Operators after which a new command starts. */
const SEPARATORS = ['&&', '||', ';;', '|&', '|', ';', '&']
/** Optional fd number + redirection operator. Heredoc operators are handled separately. */
const REDIRECT = /^(\d*)(&>>|&>|>>|>&\d*-?|>\||<&\d*-?|<<<|<>|>|<)/
const HEREDOC = /^(<<-?)(?!<)/
const WORD_BREAK = /[\s;&|<>()'"`$]/
const NAME = /^[A-Za-z_][A-Za-z0-9_]*/
const SPECIAL_PARAM = /^[0-9?@*#$!-]/
const ASSIGNMENT = /^[A-Za-z_][A-Za-z0-9_]*\+?=/
const FLAG = /^--?[A-Za-z0-9]|^--$/
const NUMBER = /^\d+(\.\d+)?$/

interface Heredoc {
  delimiter: string
  stripTabs: boolean
}

export function highlightBash(source: string): BashToken[] {
  const tokens: BashToken[] = []
  const n = source.length
  let i = 0
  /** The next word is a command name (start of input, after a separator, `$(`, a leading keyword). */
  let commandPosition = true
  /** Inside `NAME=value`: the value does not end the command position. */
  let inAssignment = false
  /** After `for`/`case`/`select`: the next `in` is a keyword. */
  let expectIn = false
  /** After a redirection operator: the next word is its target. */
  let expectTarget = false
  let expectHeredocDelimiter: boolean | null = null
  const heredocs: Heredoc[] = []
  /** Open parentheses: command substitutions `$(` and plain groups `(`. */
  const parens: ('subst' | 'group')[] = []

  function push(type: BashTokenType, text: string) {
    if (text === '') return
    const last = tokens[tokens.length - 1]
    if (last && last.type === type && type === 'text' && /^\s+$/.test(last.text) && /^\s+$/.test(text)) {
      last.text += text
      return
    }
    tokens.push({ type, text })
  }

  /** Something that is not whitespace or a separator was emitted. */
  function consumedWord() {
    if (!inAssignment) commandPosition = false
    expectTarget = false
  }

  while (i < n) {
    const start = i
    const ch = source[i]!
    const rest = source.slice(i)

    // Whitespace, up to and including one newline (heredoc bodies start after it).
    if (/\s/.test(ch)) {
      let j = i
      while (j < n && /[ \t\r\f\v]/.test(source[j]!)) j++
      if (j < n && source[j] === '\n') j++
      if (j === i) j = i + 1
      const text = source.slice(i, j)
      push('text', text)
      i = j
      inAssignment = false
      if (text.includes('\n')) {
        commandPosition = true
        expectIn = false
        expectTarget = false
        expectHeredocDelimiter = null
        if (heredocs.length) i = readHeredocBodies(i)
      }
      continue
    }

    // Comments start at a word boundary only (`a#b` and "# in strings" are not comments).
    if (ch === '#') {
      let j = source.indexOf('\n', i)
      if (j === -1) j = n
      push('comment', source.slice(i, j))
      i = j
      continue
    }

    if (expectHeredocDelimiter !== null) {
      let j = i
      while (j < n && !/[\s;&|<>()]/.test(source[j]!)) j++
      if (j > i) {
        const raw = source.slice(i, j)
        heredocs.push({ delimiter: raw.replace(/['"\\]/g, ''), stripTabs: expectHeredocDelimiter })
        expectHeredocDelimiter = null
        push('string', raw)
        i = j
        continue
      }
      expectHeredocDelimiter = null
    }

    // Heredoc operator (`<<`, `<<-`), before generic redirections.
    const heredoc = HEREDOC.exec(rest)
    if (heredoc) {
      push('operator', heredoc[1]!)
      i += heredoc[1]!.length
      expectHeredocDelimiter = heredoc[1] === '<<-'
      continue
    }

    const separator = SEPARATORS.find((op) => rest.startsWith(op))
    // `&>` is a redirection, not a background `&` followed by `>`.
    if (separator && !(separator === '&' && rest.startsWith('&>'))) {
      push('operator', separator)
      i += separator.length
      commandPosition = true
      inAssignment = false
      expectTarget = false
      continue
    }

    const redirect = REDIRECT.exec(rest)
    if (redirect && (redirect[1] === '' || /^\d/.test(ch))) {
      push('operator', redirect[0])
      i += redirect[0].length
      // `2>&1` and `>&-` carry their target; `> file` takes the next word.
      expectTarget = !/&\d*-?$/.test(redirect[2]!) || redirect[2] === '&>' || redirect[2] === '&>>'
      continue
    }

    if (ch === '(') {
      parens.push('group')
      push('operator', '(')
      i++
      commandPosition = true
      continue
    }
    if (ch === ')') {
      const kind = parens.pop()
      push(kind === 'subst' ? 'variable' : 'operator', ')')
      i++
      commandPosition = kind !== 'subst'
      if (kind === 'subst') consumedWord()
      continue
    }

    if (ch === "'") {
      i = readSingleQuoted(i)
      consumedWord()
      continue
    }
    if (ch === '"') {
      i = readDoubleQuoted(i)
      consumedWord()
      continue
    }
    if (ch === '`') {
      const end = source.indexOf('`', i + 1)
      i = end === -1 ? n : end + 1
      push('variable', source.slice(start, i))
      consumedWord()
      continue
    }
    if (ch === '$') {
      if (source[i + 1] === '(') {
        parens.push('subst')
        push('variable', '$(')
        i += 2
        commandPosition = true
        inAssignment = false
        continue
      }
      if (source[i + 1] === "'") {
        i = readSingleQuoted(i + 1, true)
        tokens[tokens.length - 1]!.text = '$' + tokens[tokens.length - 1]!.text
        consumedWord()
        continue
      }
      const variable = readVariable(i)
      if (variable > i) {
        push('variable', source.slice(i, variable))
        i = variable
        consumedWord()
        continue
      }
      // A lone `$` is an ordinary character.
    }

    // A word: up to the next break character; backslash escapes the next character.
    let j = i
    while (j < n) {
      const c = source[j]!
      if (c === '\\') {
        j = Math.min(n, j + 2)
        continue
      }
      if (j > i && WORD_BREAK.test(c)) break
      if (j === i && WORD_BREAK.test(c) && c !== '$') break
      j++
    }
    if (j === i) j = i + 1 // Never stall on an unexpected character.
    i = classifyWord(source.slice(start, j), i)
  }

  return tokens

  function classifyWord(word: string, at: number): number {
    const end = at + word.length
    if (expectTarget) {
      push('text', word)
      expectTarget = false
      return end
    }
    if (commandPosition && !inAssignment) {
      const assignment = ASSIGNMENT.exec(word)
      if (assignment) {
        push('variable', assignment[0])
        push('text', word.slice(assignment[0].length))
        inAssignment = true
        return end
      }
      if (LEADING_KEYWORDS.has(word)) {
        push('keyword', word)
        return end
      }
      if (CLOSING_KEYWORDS.has(word)) {
        push('keyword', word)
        commandPosition = false
        return end
      }
      if (NAME_KEYWORDS.has(word)) {
        push('keyword', word)
        commandPosition = false
        expectIn = word !== 'function'
        return end
      }
      push('command', word)
      commandPosition = false
      return end
    }
    if (expectIn && word === 'in') {
      push('keyword', word)
      expectIn = false
      return end
    }
    if (FLAG.test(word)) push('flag', word)
    else if (NUMBER.test(word)) push('number', word)
    else push('text', word)
    if (!inAssignment) commandPosition = false
    return end
  }

  /** `'...'` (or `$'...'` with backslash escapes); unterminated runs to the end. */
  function readSingleQuoted(at: number, escapes = false): number {
    let j = at + 1
    while (j < n && source[j] !== "'") j += escapes && source[j] === '\\' ? 2 : 1
    const end = Math.min(n, j + 1)
    push('string', source.slice(at, end))
    return end
  }

  /** `"..."`: string pieces with `$X`, `${X}`, `$(...)` and backticks split out as variables. */
  function readDoubleQuoted(at: number): number {
    let chunk = at
    let j = at + 1
    while (j < n && source[j] !== '"') {
      const c = source[j]!
      if (c === '\\') {
        j += 2
        continue
      }
      let end = j
      if (c === '$' && source[j + 1] === '(') end = balancedParens(j + 1)
      else if (c === '$') end = readVariable(j)
      else if (c === '`') {
        const close = source.indexOf('`', j + 1)
        end = close === -1 ? n : close + 1
      }
      if (end > j) {
        push('string', source.slice(chunk, j))
        push('variable', source.slice(j, end))
        chunk = end
        j = end
        continue
      }
      j++
    }
    const end = Math.min(n, j + 1)
    push('string', source.slice(chunk, end))
    return end
  }

  /** End of `$NAME`, `${...}`, `$1`, `$?`…; `at` when there is no variable at `at`. */
  function readVariable(at: number): number {
    const next = source[at + 1]
    if (next === '{') {
      const close = source.indexOf('}', at + 2)
      return close === -1 ? n : close + 1
    }
    const after = source.slice(at + 1)
    const name = NAME.exec(after)
    if (name) return at + 1 + name[0].length
    if (SPECIAL_PARAM.test(after)) return at + 2
    return at
  }

  /** From an opening `(`, the index after its matching `)` (quotes skipped); end of input if unbalanced. */
  function balancedParens(open: number): number {
    let depth = 0
    let j = open
    while (j < n) {
      const c = source[j]!
      if (c === '\\') {
        j += 2
        continue
      }
      if (c === "'") {
        const close = source.indexOf("'", j + 1)
        j = close === -1 ? n : close + 1
        continue
      }
      if (c === '(') depth++
      else if (c === ')' && --depth === 0) return j + 1
      j++
    }
    return n
  }

  /** Consumes the bodies of pending heredocs (starting at a line start) as one string token. */
  function readHeredocBodies(at: number): number {
    let j = at
    while (heredocs.length && j < n) {
      const { delimiter, stripTabs } = heredocs.shift()!
      for (;;) {
        let lineEnd = source.indexOf('\n', j)
        if (lineEnd === -1) lineEnd = n
        const line = source.slice(j, lineEnd)
        const done = (stripTabs ? line.replace(/^\t+/, '') : line) === delimiter
        if (done || lineEnd === n) {
          j = lineEnd
          break
        }
        j = lineEnd + 1
      }
      // Several heredocs on one command line: the next body starts on the following line.
      if (heredocs.length && j < n) j++
    }
    heredocs.length = 0
    push('string', source.slice(at, j))
    return j
  }
}
