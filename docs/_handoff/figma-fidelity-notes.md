---
name: figma-fidelity
description: Owner requires every built screen (admin and storefront) to match the Figma design exactly; Figma beats the written specs
metadata:
  node_type: memory
  type: feedback
  originSessionId: 4024073c-c3fa-4cb2-bfb4-4484c9bff667
  modified: 2026-11-11T17:03:50.765Z
---

On 2026-11-11 the owner said: "make sure frontend and backend design 100% match with figma." Here "backend" means the admin.

**Why:** the design was fully built and approved in Figma before any code. The owner wants the result to be what they signed off on, not an approximation of the written spec.

**How to apply:**
- Every UI agent prompt must name the Figma nodes for its screens and components. The file key is `PWZp5VzEgEEf5N2Y38lGIh`.
- Agents read Figma with create_session, then get_screenshot / get_design_context, after loading the /figma-design-to-code skill. They only read; never write to the file.
- Admin colours come from page 07 section "14 · Theme B"; layout can come from the Theme A originals. Node ids are in docs/_design/06-admin-feature-checklist.md §6. Storefront ids are in 03-storefront-spec.md and [[figma-build-progress]].
- Map every value to a packages/tokens token; never hardcode one. A value with no token gets reported, not invented.
- Before calling a screen done, compare built-vs-Figma screenshots at 1440 and at the mobile/768 size, and report the remaining differences to the owner.
- Part of [[dev-workflow]].
