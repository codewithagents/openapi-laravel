import { defineConfig } from 'astro/config'
import starlight from '@astrojs/starlight'
import remarkGfm from 'remark-gfm'

export default defineConfig({
  site: 'https://openapi-laravel.codewithagents.de',
  base: '/',
  // Explicitly enable GFM so markdown tables render in .mdx files
  // (Astro 6 + Starlight 0.39 do not apply it to MDX by default).
  markdown: {
    remarkPlugins: [remarkGfm],
  },
  integrations: [
    starlight({
      title: 'openapi-laravel',
      description:
        'Generate Laravel models and a server scaffold from your OpenAPI spec. Spatie laravel-data classes, spec-derived validation rules, native PHP enums, abstract controllers, and routes. The spec is the source of truth.',
      head: [
        {
          tag: 'meta',
          attrs: { property: 'og:type', content: 'website' },
        },
        {
          tag: 'meta',
          attrs: {
            property: 'og:title',
            content: 'openapi-laravel: spec-first Laravel models from your OpenAPI document',
          },
        },
        {
          tag: 'meta',
          attrs: {
            property: 'og:description',
            content:
              'Generate spatie/laravel-data classes, spec-derived validation rules, and native PHP enums from your OpenAPI spec.',
          },
        },
        {
          tag: 'meta',
          attrs: { name: 'twitter:card', content: 'summary' },
        },
      ],
      logo: {
        src: './src/assets/logo-cairn.svg',
        alt: 'openapi-laravel',
      },
      favicon: '/favicon.svg',
      social: [
        {
          icon: 'github',
          label: 'GitHub',
          href: 'https://github.com/codewithagents/openapi-laravel',
        },
      ],
      customCss: ['./src/styles/custom.css'],
      components: {
        Footer: './src/components/Footer.astro',
      },
      sidebar: [
        {
          label: 'Getting Started',
          items: [
            { label: 'Introduction', slug: 'getting-started' },
            { label: 'Installation', slug: 'getting-started/installation' },
            { label: 'Quick start', slug: 'getting-started/quickstart' },
          ],
        },
        {
          label: 'Features',
          items: [
            { label: 'Generated models & enums', slug: 'guides/generated-output' },
            { label: 'Request & response bodies', slug: 'guides/request-response-bodies' },
            { label: 'Parameters', slug: 'guides/parameters' },
            { label: 'Unions & polymorphism', slug: 'guides/unions' },
            { label: 'Server scaffold', slug: 'guides/server-scaffold' },
            { label: 'Security & middleware', slug: 'guides/security' },
            { label: 'Subset generation', slug: 'guides/subset-generation' },
          ],
        },
        {
          label: 'Workflow',
          items: [
            { label: 'Configuration', slug: 'guides/configuration' },
            { label: 'Drift check', slug: 'guides/drift-check' },
            { label: 'Contract testing', slug: 'guides/contract-testing' },
            { label: 'Validation error shape', slug: 'guides/validation-errors' },
            { label: 'Cookbook', slug: 'guides/cookbook' },
          ],
        },
        {
          label: 'Reference',
          items: [
            { label: 'Stability & the 1.0 promise', slug: 'guides/stability' },
            { label: 'Supported OpenAPI versions', slug: 'guides/openapi-versions' },
            { label: 'Limitations', slug: 'guides/limitations' },
            { label: 'Versioning & upgrades', slug: 'guides/versioning-policy' },
            { label: 'Runtime coupling', slug: 'guides/runtime-coupling' },
            { label: 'End-to-end demo', slug: 'guides/end-to-end-demo' },
          ],
        },
        {
          label: 'Background',
          items: [
            { label: 'How it compares', slug: 'comparison' },
            { label: 'Philosophy', slug: 'philosophy' },
            { label: 'Roadmap & release history', slug: 'roadmap' },
          ],
        },
      ],
    }),
  ],
})
