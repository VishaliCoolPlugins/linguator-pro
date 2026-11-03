# AutoMLP Translation Workflow (WPML)

Use this workflow when a user asks to translate a page, several named pages, the homepage, a header or footer, the pages in a navigation menu, or a defined part of a site. The objective is a complete and verified target-language experience, not merely a successful save.

## Core rules

1. Translate only the content in the user's requested scope. Do not translate anything outside it without permission.
2. Start with discovery. Do not write translations until the target language, the requested content and its blockers are known.
3. A successful tool call does **not** mean the work is complete. Read `outcome`, `summary`, `warnings` and `next_action` in every `translate-post` result.
4. Do not silently skip content. Report every requested item as `completed`, `remaining`, `failed` or `needs_attention`.
5. Preserve HTML tags and the exact string keys that `get-content` returns. Never invent, rename or renumber a key.
6. New translations are created with the status set in WPML > AutoMLP AI > Settings, which is **draft** unless changed (`new_post_status` in `get-translation-plan`). A draft gives visitors a 404. Pass `post_status` "publish" when the user wants the page live.

## Plan first

For a site-level request (homepage, header, footer, menu pages), call `get-translation-plan` with the target language before anything else. It returns the whole checklist in a safe order: header and footer templates, the static homepage, then every page, term and custom link in the header menu. It also returns `blockers`, `notes`, `steps`, `next_item` and `verify_batches`. Use that list as your checklist. It is rebuilt from the site every time, so calling it again resumes an interrupted job and shows what is left.

Resolve every entry in `blockers` first. The usual ones:

- **A content type is not translatable.** Elementor templates (`elementor_library`) are often left switched off, and content in a switched-off type has no language. A site administrator must set the type to "Translatable" under WPML > Settings > Post Types Translation. These tools cannot change that.
- **A post has no language.** Give it one with `manage-translation-links` (action `set_language`), then translate it.

## Translating an item

For each item in order:

1. `get-content` for the source post.
2. Translate every value, keeping the HTML and the keys exactly.
3. `translate-post` with `post_id`, `target_language`, `title`, `contents`, and `post_status` "publish" when the user wants it live.
4. Read the result. `outcome` `needs_attention` is not complete: repair what `summary` names and call `translate-post` again for the same post (it updates the existing translation, it does not create a duplicate).

For Elementor, Divi and Cornerstone pages the text lives in `contents`, not in the post body. AutoMLP re-saves an Elementor translation through Elementor's own document API after writing it; `elementor_rebuild` in the result says whether that worked. If it did not, call `rebuild-elementor-data`.

The URL slug follows WPML's "translated document slug" setting (`get-translation-settings`). Omit `post_name` unless the user asked for a specific slug. A static homepage is served from the language home address, so do not try to fix it by changing its slug.

## Headers and footers

Headers and footers are Elementor templates (`elementor_library`). They must be translatable (see blockers), and each one is translated like a page. `verify-translations` checks the target-language home page, where the header and footer appear. If a template still points at the source-language version of another template, `audit-translation-integrity` reports `source_language_template`.

## Navigation menus

These tools cannot create or sync a translated menu. WPML builds it from **WPML > Translations > Menus (older WPML: WPML > WP Menus Sync)**, which only an administrator can run. So:

1. Translate and publish every page in the menu first.
2. Menu labels are WPML strings: `get-string-groups` lists the group named "<menu name> menu", `get-strings` reads it and `translate-strings` saves it.
3. Tell the user to run WPML > Translations > Menus (older WPML: WPML > WP Menus Sync). Do not report the menu as translated until `verify-translations` (with `menu_id`) confirms the translated menu and its items.

## Theme and plugin text

Text from themes and plugins is WPML String Translation, not post content: `get-string-groups`, `get-strings`, then `translate-strings` (up to 100 per call). Keep placeholders such as `%s` and `{name}` exactly.

## Verification and recovery

Never report completion solely because a write returned. Resolve or explicitly report:

- a translation that is a draft (visitors get a 404);
- public-page HTTP errors;
- a page that does not declare the target language;
- internal links or templates still pointing at the source language (`audit-translation-integrity`);
- text left untranslated;
- a menu that has not been synced by an administrator.

After the last write, call `verify-translations` for each batch in `verify_batches` (at most 5 source posts per call). Pass `menu_id` if a menu was involved. If `complete` is not `true`, fix the first problem using `next_action` and verify again. A page it could not request (`http_not_verified`) is not verified: tell the user to check it in a browser.

## Large requests and batching

1. Use the `items` from `get-translation-plan` as the checklist before writing.
2. Process as many complete pages as can be safely translated and verified in the current session.
3. After each page, note its source ID, translated ID, status and unresolved issues.
4. If capacity is reached, tell the user exactly what was completed and what remains. Do not claim that the original request is finished.
5. A later session calls `get-translation-plan` again and resumes from the items whose `state` is not `translated`.

## Completion response

Only call a translation job complete when `get-translation-plan` shows no remaining items and `verify-translations` returns `complete: true`. List anything an administrator still has to do (such as the menu sync).
