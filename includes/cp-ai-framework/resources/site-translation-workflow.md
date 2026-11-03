# Site Translation Workflow

Use this workflow when a user asks to translate a page, several named pages, the homepage, a header or footer, a navigation menu, or a defined part of a site. The objective is a complete and verified target-language experience, not merely a successful save operation.

## Core rules

1. Translate only the content in the user's requested scope. Do not translate posts, pages, templates, or terms outside that scope without permission.
2. Start with discovery. Do not write translations until the site configuration, target language, requested source content, glossary, and relevant dependencies are known.
3. A successful tool call does **not** mean the work is complete. Inspect the result of every write.
4. Do not silently skip content. Report every requested item as `completed`, `remaining`, `failed`, or `needs_attention`.
5. Preserve HTML, Elementor structure, shortcodes, dynamic tags, URLs that must remain unchanged, and glossary-mandated terminology.
6. URL slugs follow the rule saved in AutoPoly's settings (`slug_translation` in `get-translation-settings`): `title_translate`, `slug_translate` or `slug_keep`. The tools apply it. Send `translated_slug` only when the rule is `slug_translate`, and read `slug` and `slug_notice` in the result.

## Plan first

For a site-level request (homepage, header, footer, menu pages), call `get-translation-plan` with the target language before anything else. It returns the whole checklist in a safe order: header and footer templates, the static homepage, then every page, term and custom link in the header menu. It also returns `blockers`, `steps`, `next_item` and `verify_batches`. Use that list as your checklist. It is rebuilt from the site every time, so calling it again resumes an interrupted job and shows what is left.

## Discovery

Before a translation job, call these tools as applicable:

1. `get-translation-settings`: read URL behaviour, allowed content types/meta, menu assignments, and Elementor compatibility.
2. `list-languages`: confirm the target language exists.
3. `list-content-types`: confirm each requested post type is translatable.
4. `get-glossary`: apply the exact target-language terms, including brand names that must remain unchanged.
5. `get-content` for every requested page or template: retrieve its source content, editor type, builder strings, language, existing translation, and glossary terms.

For a request involving a header menu, identify every destination represented by that menu before starting. A menu destination must have a published target-language translation before menu sync can point to it.

## Translation order

### Pages and Elementor content

For each requested page:

1. Call `get-content`.
2. Translate the title, normal content, excerpt, allowed meta, and every eligible builder string.
3. For Elementor (`builder_storage: meta`), send **all** translatable `builder_strings` together in one `translate-post` call. Do not send entries marked `translate: false`.
4. Preserve HTML markup in each builder-string translation.
5. Use `translate-post` with public verification enabled.
6. Read `outcome`, `summary`, `next_action`, `public_page`, `links_unresolved`, `template_notice`, `meta_skipped`, and any glossary result. A result of `partial` or `needs_attention` is not complete: repair it and call `translate-post` again for the same post (it updates the existing translation, it does not create a duplicate).

Translate menu destination pages before the menu itself. For a normal page, translate the homepage and requested linked pages in the order that best satisfies their dependencies.

### Static homepage

Do not attempt to fix a static homepage URL by changing `translated_slug`. A language's homepage URL is determined by Polylang's front-page mapping and language URL configuration. Use the returned `canonical_url`, the public-page result, and site settings to diagnose a 404.

### Elementor headers and footers

Before translating an Elementor header or footer:

1. Call `preflight-template`.
2. Confirm `elementor_library` is translatable and the source template has a language. Assign a missing language with `manage-translation-links` when appropriate.
3. Confirm Connect Polylang for Elementor is active if the translated header/footer must be displayed automatically by language.
4. Translate the Elementor template with `get-content` and `translate-post`.

Menu Sync is **not** required to translate a header or footer. It is needed only when an Elementor Nav Menu widget must show a separate target-language WordPress menu.

### Navigation menus

When the requested scope includes a WordPress menu or an Elementor Nav Menu widget:

1. Ensure every required menu destination page is translated and published.
2. Run `sync-menu` with `dry_run: true`.
3. Review `items_skipped`, `labels_unchanged`, `links_remapped`, custom links, target-menu replacement, and refused languages.
4. Translate required custom labels with `translate-string`.
5. Run `sync-menu` with `dry_run: false` only after the preview is acceptable.
6. If an Elementor header/footer Nav Menu widget should use the new menu, call `set-template-menu` on the translated template.
7. Verify the public target-language homepage and every menu destination.

If a header/footer contains buttons or ordinary Elementor links instead of a Nav Menu widget, Menu Sync does not manage those links. Check `links_unresolved` and the public page instead.

## Verification and recovery

Never report completion solely because a response has `success: true`. Resolve or explicitly report:

- `outcome: partial` or `outcome: needs_attention`;
- public-page HTTP errors, especially 404;
- untranslated or omitted Elementor strings;
- `links_unresolved`;
- missing/published-late menu destinations;
- missing target-language headers/footers or a menu still bound to the source language;
- missing glossary terms; and
- `meta_skipped` where that meta is needed for the requested page.

Use `get-translation-status`, `get-untranslated-content`, `get-outdated-translations`, and translation-link management tools to locate or repair remaining work. Verify the real public target-language URLs after every dependent stage.

## Final check

After the last write, call `verify-translations` for each batch in `verify_batches` (at most 5 source posts per call). Pass `menu_id` if a menu was synced. It confirms that each translation exists, is in the target language and published, that the public page does not return an error, and that the menu points to published target-language pages. If `complete` is not `true`, fix the first problem, using `next_action`, and verify again. A page it could not request (`http_not_verified`) is not verified: tell the user to check it in a browser.

## Large requests and batching

Treat a request for many pages as one job with checkpoints.

1. Use the `items` from `get-translation-plan` as the checklist before writing.
2. Process as many complete pages as can be safely translated and verified in the current session.
3. After each page, record its source ID, target ID, status, public URL, and unresolved issues.
4. Do not begin a dependent operation, such as menu sync, until its prerequisite pages are complete.
5. If capacity is reached, tell the user exactly what was completed and what remains, then ask whether to continue. Do not claim that the original request is finished.
6. A later session calls `get-translation-plan` again and resumes from the items whose `state` is not `translated`; it does not repeat completed translations blindly.

## Completion response

Only call a translation job complete when `get-translation-plan` shows no remaining items and `verify-translations` returns `complete: true`. State the verified homepage/template/menu URLs and list any intentional exceptions.
