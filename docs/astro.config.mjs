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
        'Generate Laravel models from your OpenAPI spec. Spatie laravel-data classes, spec-derived validation rules, and native PHP enums. The spec is the source of truth.',
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
          label: 'Reference',
          items: [
            { label: 'Configuration', slug: 'guides/configuration' },
            { label: 'Generated output', slug: 'guides/generated-output' },
          ],
        },
        {
          label: 'Background',
          items: [
            { label: 'How it compares', slug: 'comparison' },
            { label: 'Philosophy', slug: 'philosophy' },
            { label: 'Roadmap & limitations', slug: 'roadmap' },
          ],
        },
      ],
    }),
  ],
})
