import { describe, expect, it } from 'vitest'
import { detectLanguage, languageLabels } from '../../src/features/repositories/syntax/languages'
import { highlight } from '../../src/features/repositories/syntax/highlight'
import {
  canHighlight,
  MAX_HIGHLIGHT_CHARACTERS,
  MAX_HIGHLIGHT_LINES,
} from '../../src/features/repositories/syntax/protocol'

describe('file language detection', () => {
  it.each([
    ['Aktido/App.xaml.cs', 'csharp'],
    ['App.XAML', 'xml'],
    ['View.axaml', 'xml'],
    ['Project.csproj', 'xml'],
    ['Program.fs', 'fsharp'],
    ['Component.tsx', 'typescript'],
    ['app.jsx', 'javascript'],
    ['config.json', 'json'],
    ['main.php', 'php'],
    ['theme.css', 'css'],
    ['schema.sql', 'sql'],
    ['main.py', 'python'],
    ['build.ps1', 'powershell'],
    ['compose.yaml', 'yaml'],
    ['Dockerfile', 'dockerfile'],
    ['docker/Dockerfile.production', 'dockerfile'],
    ['Makefile', 'makefile'],
    ['.editorconfig', 'ini'],
    ['.env.local', 'ini'],
    ['.htaccess', 'apache'],
    ['README.md', 'markdown'],
    ['docs/readme.txt', null],
    ['unknown.something', null],
    ['LICENSE', null],
    ['constructor', null],
    ['file.__proto__', null],
  ])('detects %s as %s', (path, language) => {
    expect(detectLanguage(path)).toBe(language)
  })
})

describe('safe syntax highlighting', () => {
  it('colors C# keywords, strings and comments while preserving the original code', () => {
    const content = 'using System;\r\n// čćžšđ\r\npublic class App { string name = "Glimpse"; }\n'
    const result = highlight({ language: 'csharp', content })
    expect(result.tokens?.map(token => token.text).join('')).toBe(content)
    expect(result.tokens).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ text: 'using', className: 'hljs-keyword' }),
        expect.objectContaining({ text: '// čćžšđ', className: 'hljs-comment' }),
        expect.objectContaining({ text: '"Glimpse"', className: 'hljs-string' }),
      ]),
    )
  })

  it('keeps markup as text tokens without creating executable HTML', () => {
    const content =
      '<Window Title="Glimpse"><script>alert("x")</script><img src=x onerror=alert(1) /></Window>'
    const result = highlight({ language: 'xml', content })
    expect(result.tokens?.map(token => token.text).join('')).toBe(content)
    expect(result.tokens?.some(token => token.className.includes('hljs-attr'))).toBe(true)
    expect(result.tokens).toEqual(
      expect.arrayContaining([
        { text: 'Title', className: 'hljs-attr' },
        { text: '"Glimpse"', className: 'hljs-string' },
      ]),
    )
    expect(
      result.tokens?.every(token => Object.keys(token).sort().join(',') === 'className,text'),
    ).toBe(true)
  })

  it('has a registered grammar for every mapped language', () => {
    for (const language of Object.keys(languageLabels) as Array<keyof typeof languageLabels>) {
      expect(highlight({ language, content: 'sample' }).tokens, language).not.toBeNull()
    }
  })

  it('falls back before parsing oversized files or excessive line counts', () => {
    expect(canHighlight('x'.repeat(MAX_HIGHLIGHT_CHARACTERS))).toBe(true)
    expect(
      highlight({ language: 'csharp', content: 'x'.repeat(MAX_HIGHLIGHT_CHARACTERS + 1) }),
    ).toEqual({ tokens: null, reason: 'large' })
    expect(highlight({ language: 'csharp', content: '\n'.repeat(MAX_HIGHLIGHT_LINES) })).toEqual({
      tokens: null,
      reason: 'large',
    })
  })

  it('limits the number of rendered tokens', () => {
    expect(
      highlight({
        language: 'json',
        content: '[' + Array.from({ length: 11000 }, () => '42').join(',') + ']',
      }),
    ).toEqual({ tokens: null, reason: 'large' })
  })
})
