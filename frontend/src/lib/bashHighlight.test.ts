import { describe, expect, it } from 'vitest'
import { highlightBash, type BashToken, type BashTokenType } from './bashHighlight'

/** Non-whitespace tokens as [type, text] pairs, for readable expectations. */
function pairs(source: string): [BashTokenType, string][] {
  return highlightBash(source)
    .filter((t) => t.text.trim() !== '')
    .map((t) => [t.type, t.text])
}

function typeOf(tokens: BashToken[], text: string): BashTokenType | undefined {
  return tokens.find((t) => t.text === text)?.type
}

describe('highlightBash', () => {
  it('is lossless: the tokens join back into the source', () => {
    const sources = [
      'composer install --no-dev -o && php artisan migrate --force',
      "if [ -f .env ]; then echo 'ok'; else cp .env.example .env; fi",
      'echo "unterminated',
      "cat <<'EOF'\nhello $NAME\nEOF\necho done",
      'echo $(( 1 + $(date +%s) ))',
      'a\\\nb',
      '',
      '   ',
      '$',
      'echo "${',
      '))) ((( }}} {{{',
    ]
    for (const source of sources) {
      expect(highlightBash(source).map((t) => t.text).join('')).toBe(source)
    }
  })

  it('marks the first word of each simple command, flags and operators', () => {
    expect(pairs('composer install --no-dev -o && php artisan migrate --force')).toEqual([
      ['command', 'composer'],
      ['text', 'install'],
      ['flag', '--no-dev'],
      ['flag', '-o'],
      ['operator', '&&'],
      ['command', 'php'],
      ['text', 'artisan'],
      ['text', 'migrate'],
      ['flag', '--force'],
    ])
  })

  it('recognises keywords and keeps command position after them', () => {
    expect(pairs('if [ -f .env ]; then cp a b; fi')).toEqual([
      ['keyword', 'if'],
      ['command', '['],
      ['flag', '-f'],
      ['text', '.env'],
      ['text', ']'],
      ['operator', ';'],
      ['keyword', 'then'],
      ['command', 'cp'],
      ['text', 'a'],
      ['text', 'b'],
      ['operator', ';'],
      ['keyword', 'fi'],
    ])
    expect(pairs('for f in *.log; do rm "$f"; done').slice(0, 4)).toEqual([
      ['keyword', 'for'],
      ['text', 'f'],
      ['keyword', 'in'],
      ['text', '*.log'],
    ])
  })

  it('does not treat keywords as keywords in argument position', () => {
    expect(pairs('echo if then done')).toEqual([
      ['command', 'echo'],
      ['text', 'if'],
      ['text', 'then'],
      ['text', 'done'],
    ])
  })

  it('handles single and double quotes with escapes', () => {
    expect(pairs(`echo 'it''s' "say \\"hi\\"" end`)).toEqual([
      ['command', 'echo'],
      ['string', "'it'"],
      ['string', "'s'"],
      ['string', '"say \\"hi\\""'],
      ['text', 'end'],
    ])
  })

  it('splits variables out of double-quoted strings', () => {
    expect(pairs('echo "Deploying ${PROJECT} to $DIR now"')).toEqual([
      ['command', 'echo'],
      ['string', '"Deploying '],
      ['variable', '${PROJECT}'],
      ['string', ' to '],
      ['variable', '$DIR'],
      ['string', ' now"'],
    ])
  })

  it('never expands variables inside single quotes', () => {
    expect(pairs("echo '$HOME'")).toEqual([
      ['command', 'echo'],
      ['string', "'$HOME'"],
    ])
  })

  it('recognises $X, ${X}, $?, $1 and positional specials', () => {
    const tokens = highlightBash('echo $X ${Y:-z} $? $1 $@ $$')
    for (const text of ['$X', '${Y:-z}', '$?', '$1', '$@', '$$']) {
      expect(typeOf(tokens, text)).toBe('variable')
    }
  })

  it('colours $( and ) of command substitutions and tokenizes the inside, nested', () => {
    expect(pairs('echo $(basename $(pwd)) done')).toEqual([
      ['command', 'echo'],
      ['variable', '$('],
      ['command', 'basename'],
      ['variable', '$('],
      ['command', 'pwd'],
      ['variable', ')'],
      ['variable', ')'],
      ['text', 'done'],
    ])
  })

  it('keeps a command substitution inside a double-quoted string as one variable token', () => {
    expect(pairs('echo "now: $(date +%s)!"')).toEqual([
      ['command', 'echo'],
      ['string', '"now: '],
      ['variable', '$(date +%s)'],
      ['string', '!"'],
    ])
  })

  it('treats # as a comment only at the start of a word, never inside strings or words', () => {
    expect(pairs('echo "a # b" c#d # trailing note')).toEqual([
      ['command', 'echo'],
      ['string', '"a # b"'],
      ['text', 'c#d'],
      ['comment', '# trailing note'],
    ])
    expect(pairs('# whole line\nls')).toEqual([
      ['comment', '# whole line'],
      ['command', 'ls'],
    ])
  })

  it('recognises redirections and pipes', () => {
    expect(pairs('make build 2>&1 | tee out.log >> all.log < in.txt')).toEqual([
      ['command', 'make'],
      ['text', 'build'],
      ['operator', '2>&1'],
      ['operator', '|'],
      ['command', 'tee'],
      ['text', 'out.log'],
      ['operator', '>>'],
      ['text', 'all.log'],
      ['operator', '<'],
      ['text', 'in.txt'],
    ])
    expect(pairs('a || b; c > d')).toEqual([
      ['command', 'a'],
      ['operator', '||'],
      ['command', 'b'],
      ['operator', ';'],
      ['command', 'c'],
      ['operator', '>'],
      ['text', 'd'],
    ])
  })

  it('marks numbers and assignments', () => {
    expect(pairs('APP_ENV=prod sleep 2')).toEqual([
      ['variable', 'APP_ENV='],
      ['text', 'prod'],
      ['command', 'sleep'],
      ['number', '2'],
    ])
    expect(pairs('test "$n" -eq 10')).toEqual([
      ['command', 'test'],
      ['string', '"'],
      ['variable', '$n'],
      ['string', '"'],
      ['flag', '-eq'],
      ['number', '10'],
    ])
  })

  it('keeps multi-line strings in one block as strings across lines', () => {
    const tokens = highlightBash('echo "line one\nline two" && ls')
    expect(tokens.find((t) => t.type === 'string')?.text).toBe('"line one\nline two"')
    expect(typeOf(tokens, 'ls')).toBe('command')
  })

  it('treats newlines as command separators', () => {
    expect(pairs('cd app\nnpm ci')).toEqual([
      ['command', 'cd'],
      ['text', 'app'],
      ['command', 'npm'],
      ['text', 'ci'],
    ])
  })

  it('tolerates heredocs: the body is a string, the command after the delimiter is a command', () => {
    const tokens = highlightBash("cat <<'EOF' > .env\nKEY=$VALUE # not a comment\nEOF\nphp artisan up")
    expect(typeOf(tokens, 'cat')).toBe('command')
    expect(typeOf(tokens, '<<')).toBe('operator')
    expect(tokens.some((t) => t.type === 'comment')).toBe(false)
    expect(tokens.find((t) => t.text.includes('KEY=$VALUE'))?.type).toBe('string')
    expect(typeOf(tokens, 'php')).toBe('command')
  })

  it('never crashes or loops on malformed input', () => {
    for (const source of ['"', "'", '$(', '${', '`', '<<', '<<EOF', ')', 'echo $((', '\\', '2>', '&&&&||||;;;;']) {
      expect(highlightBash(source).map((t) => t.text).join('')).toBe(source)
    }
  })
})
