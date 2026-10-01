import js from '@eslint/js'
import globals from 'globals'
import tseslint from 'typescript-eslint'
import reactHooks from 'eslint-plugin-react-hooks'
import reactRefresh from 'eslint-plugin-react-refresh'
import pluginQuery from '@tanstack/eslint-plugin-query'

// Structure: src/api, src/components and src/format.ts are shared by everything and import no feature.
// A feature imports shared code, features/auth, and only the features listed here: the workspace and
// the page of an access link are built on the repository views. Only app/ puts the features together.
const features = ['admin', 'auth', 'repositories', 'sharing', 'workspace']
const allowed = {
  admin: ['auth'],
  auth: [],
  repositories: ['auth'],
  sharing: ['auth', 'repositories'],
  workspace: ['auth', 'repositories'],
}

function boundary(feature) {
  const others = features.filter(other => other !== feature && !allowed[feature].includes(other))
  return {
    regex: `^(\\.\\./)+(${others.join('|')}|app)/`,
    message: `features/${feature} may import shared code${allowed[feature].map(other => `, features/${other}`).join('')}; only app/ puts features together.`,
  }
}

const shared = {
  regex: '(^|/)(features|app)/',
  message: 'Shared code (api, components, format) does not depend on features or app/.',
}

// Every request goes through the api.ts of its area, with its URLs, types and query keys.
const serverCalls = {
  regex: '(^|/)api/client$',
  importNames: ['request', 'post', 'put', 'patch'],
  message: "Call the server through this area's api.ts.",
}

const restrict = patterns => ({ 'no-restricted-imports': ['error', { patterns }] })

export default tseslint.config(
  { ignores: ['dist', 'coverage'] },
  js.configs.recommended,
  ...tseslint.configs.recommended,
  ...pluginQuery.configs['flat/recommended'],
  {
    files: ['**/*.{ts,tsx}'],
    languageOptions: { globals: { ...globals.browser, ...globals.node } },
    plugins: { 'react-hooks': reactHooks, 'react-refresh': reactRefresh },
    rules: {
      ...reactHooks.configs.recommended.rules,
      'react-refresh/only-export-components': ['warn', { allowConstantExport: true }],
    },
  },
  { files: ['src/api/**/*.{ts,tsx}'], rules: restrict([shared]) },
  {
    files: ['src/components/**/*.{ts,tsx}', 'src/format.ts'],
    rules: restrict([shared, serverCalls]),
  },
  { files: ['src/app/**/*.{ts,tsx}'], rules: restrict([serverCalls]) },
  ...features.map(feature => ({
    files: [`src/features/${feature}/**/*.{ts,tsx}`],
    ignores: ['**/api.ts'],
    rules: restrict([boundary(feature), serverCalls]),
  })),
  ...features.map(feature => ({
    files: [`src/features/${feature}/**/api.ts`],
    rules: restrict([boundary(feature)]),
  })),
)
