# RemoteWP utility: design and frontend changes

Use this playbook for CSS, responsive layout, typography, spacing, component
structure and frontend behavior. It is guidance for the orchestrator; all site
access still goes through the authenticated RemoteWP API.

## Workflow

1. Read `/wp/info` and identify the active theme and child theme.
2. List the relevant theme directories before selecting files.
3. Read the target template, stylesheet and related JavaScript before proposing a change.
4. Check responsive breakpoints, existing design tokens, component conventions and accessibility attributes.
5. Explain the cause and show a narrow diff. Prefer child-theme or custom-plugin files.
6. After explicit approval, mutate with `expected_sha256`, an idempotency key and the narrowest supported endpoint.
7. Clear cache only after a successful mutation.
8. Re-read the changed file, verify the returned hash/operation status and perform an HTTP or browser check when the tool is available.

## Guardrails

- Do not claim visual verification without browser access.
- Do not replace an entire stylesheet when a focused patch is sufficient.
- Preserve keyboard navigation, focus states, contrast and reduced-motion behavior.
- Do not modify WordPress core, parent theme files or third-party plugin files unless the user explicitly accepts the maintenance risk.
- If the visual result cannot be verified, report that limitation instead of guessing.
