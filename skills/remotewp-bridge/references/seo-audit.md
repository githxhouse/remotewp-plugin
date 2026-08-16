# RemoteWP utility: SEO and structured-data audit

Use this playbook for technical SEO, metadata, canonical URLs, headings,
robots directives, sitemaps and Schema.org/AEO implementation. It does not
claim rankings, traffic or a complete SEO audit without external data.

## Workflow

1. Read `/wp/info` and record the active theme, WordPress version and site URL.
2. Read the relevant template files, beginning with the document head and the page template.
3. Search approved files for title tags, meta descriptions, canonical output, robots directives, Open Graph, JSON-LD, breadcrumbs and heading generation.
4. Inspect active SEO plugin files/options only through the capabilities returned by the site context.
5. Compare findings with the requested page type and identify conflicts or duplicate output.
6. Produce a finding with evidence, impact, confidence and a proposed minimal fix.
7. After explicit approval, patch only the owning child theme/custom plugin and preserve `expected_sha256`.
8. Clear cache, re-read the changed files and verify rendered HTML or the relevant endpoint when available.

## Guardrails

- Do not invent schema properties or business facts absent from the site.
- Do not claim search-engine validation, indexing or ranking improvement without the relevant external tool/data.
- Preserve existing multilingual, canonical and pagination behavior.
- Never expose redacted secrets or transmit private SEO/plugin configuration in handoff logs.
