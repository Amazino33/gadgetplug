# Ask GadgetPlug chat UI: progress

The chat UI/UX rework. The assistant's backend (queries, permissions, prompt, provider) has its own notes in `docs/ai-assistant/PROGRESS.md`.

| Phase | What | Status |
|---|---|---|
| 0 | Recon and design plan | approved 2026-11-10 |
| 1 | Fix what's broken | **merged to main 2026-11-11** |
| 2 | Conversation flow (+ auto-titles, permission-aware suggestions) | not started |
| 3 | Answer cards (needs product IDs from the backend; the answer-style prompt change ships with it) | not started |
| 4 | Polish and accessibility pass | not started |

## Standing rules for this work

- **Build assets:** `public/build` is committed, so every branch that rebuilds it conflicts there. Run `npm run build` only for the **final commit of each phase**. When merging branches, **rebuild instead of resolving `public/build` conflicts by hand**: take either side, run `npm run build`, and commit the result.
- No backend assistant logic on this branch. Anything a UI change needs from the backend is listed below as "needs backend branch", with the change proposed.
- The UI only shows what the backend returns for that user, and never changes what a user can see.
- Screenshots go under `docs/assistant-ui/screenshots/` only when they show demo data. Real vendor data is kept out of git.
- Full Pest suite: no new failures against the baseline in `docs/ai-assistant/PROGRESS.md`, by name.

## Decisions (Phase 0 review, 2026-11-10)

- Answers are full width with no bubble. Your own question is a tinted bubble.
- Answer cards are styled like shelf price tags (Phase 3).
- History is a drawer inside the chat, not a permanent column.
- Orange is an accent only, always with **dark text** on it. White on primary-500 measures 2.15 : 1 (fails); gray-950 on it measures 9.4 : 1.
- Markdown goes through `league/commonmark` and a **strict** `symfony/html-sanitizer` config (`App\Support\Assistant\AnswerMarkdown`), not Filament's rich-editor allowlist. Both packages were already installed.
- UI notes live here, not in `docs/ai-assistant/PROGRESS.md`, so the docs don't conflict with the backend branches.

## Phase 1: fix what's broken (built 2026-11-10)

In the order the review set:

1. **Mobile overflow.** The chat column lacked `min-width: 0`, so the nowrap store-chips row forced it wider than the panel and `overflow-hidden` clipped it. At 360 px the send button was entirely off-screen. The chips row is gone, every flex level has `min-w-0`, and on phones the chat is a full-screen sheet. **Measured in headless Chrome** at 320, 360 and 390 px, light and dark (`screenshots/after/overflow.json`): the sheet is exactly the screen's width, the page never scrolls sideways, the send button is fully visible, and no element extends past the edge.
2. **Exception text is never shown.** `ChatBubble::sendMessage()` used to put `$e->getMessage()` on screen. It now logs the full exception (`Assistant chat: the question could not be answered`, with `user_id`, `vendor_id`, `thread_id`, `question_message_id`) and shows plain words with **Retry**:
   - a question too long: "That question is too long. Keep it under 2,000 characters.";
   - no access: "The assistant isn't available to you here. Ask the shop owner if you need it.";
   - anything else: "Couldn't get an answer. Check your connection and try again.";
   - a request that never comes back (the browser's own failure) gets that last message too, from Alpine, never the browser's error text.

   Answers the backend saved as failed (`provider_error`, `internal_error`, `tool_error`, `phrasing_failed`) keep the backend's own friendly words ("took too long", "out of credit…", "busy"), which are more specific, plus Retry. `cap_reached` shows the limit message with no Retry. `no_access` shows the answer plus "Your role doesn't include this. Ask the shop owner if you need it."
3. **Sanitized Markdown.** Assistant answers render bold, italics, lists, line breaks, inline code, simple tables and links. CommonMark escapes all raw HTML (`html_input: escape`); the sanitizer then keeps only those tags. Headings, quotes and code blocks become text, and everything else is dropped. Links work only inside the panel (relative, or this app's host). Any other link loses its `href` and reads as plain text, and `javascript:` links never survive.
   - **Limit, stated honestly:** in the model's text, Markdown inside a product name the model repeated ("**bold**") may render as bold. It can never become a script, an image or an off-site link. Cards (Phase 3) will show record names straight from tool data, as plain text.
   - Questions, store names and conversation titles are always plain text (Blade-escaped).
4. **Full-screen sheet on phones**, a 420 px panel docked right from 768 px (page usable beside it), and a one-row 56 px header: history, title, store chip, questions left (when low), close.
   - One close control; the launcher is hidden while the chat is open. Escape closes the store picker, then the history, then the chat.
   - The separate store-chips row is gone: the header chip opens a simple store picker (Phase 2 makes it a bottom sheet with "All stores").
   - The raw `[Store context: X]` pill and the store label above every message are gone. The store shows in the header chip, plus a divider "Now asking about X" where it changes partway through. The note that opens a conversation is hidden.
5. **Times in the reporting timezone** (`BusinessDate::timezone()`, the same config the trading day uses), not UTC. They were an hour behind Lagos. A small centred divider ("Today, 14:12", "Yesterday, 09:05", "Mon 9 Nov, 14:12") appears at the first message and after any gap of 10+ minutes. Tapping a message shows its exact time.
6. **A textarea that stays above the keyboard.** It grows to 5 lines. The sheet's height and top follow `visualViewport` (with `100dvh` as fallback), and the footer pads for the safe area.
   - Desktop: Enter sends, Shift+Enter adds a new line.
   - Phones (coarse pointer): return adds a new line, and the send button sends.
7. **Smart scroll:** the chat follows new content only if you're already at the bottom; otherwise a "New answer ↓" pill appears. The thinking state sits where the answer will appear, with `role="status"`. The conversation is `role="log"` with `aria-live="polite"`. The dots animate only without `prefers-reduced-motion`, and the send button is disabled while a question runs.
8. **Rename button:** the title is passed with `@js()` instead of `'{{ addslashes() }}'`, so any title (quotes, line breaks, `</script>`) is a safe JavaScript string.
9. **Questions left** show in the header only when 10 or fewer remain ("3 left today"). The old 80% banner is gone.

**Also:** icon controls are 44 × 44 px with `aria-label`s, focus is visible (accent outline), focus goes to the input on open and back to the launcher on close, and numbers use tabular figures.

### Tests

**New:** `tests/Feature/Assistant/Ui/ChatUiTest.php` (17):
- Markdown bold and lists;
- an `<img src=x onerror=…>` product name shows as text;
- `**bold** [link](https://evil.example)` and `javascript:` can't make a clickable off-site link, while a panel link stays clickable;
- questions, store names and titles with HTML are plain text;
- an exception's message never appears and is logged in full with the question;
- too long and no access each get their own copy;
- backend-saved failures show friendly words with Retry, and the cap with no Retry;
- no_access gets its note;
- Retry after a failure before the model resends the same question and counts it once (the first attempt never reached the model, so there is 1 question row and 1 counted);
- Lagos times and 10-minute dividers;
- the first question's divider when a store note opens the conversation;
- store dividers with no raw note;
- the rename `@js` title with a line break and `</script>`;
- questions left at exactly 10 but not at 11;
- one close control, the launcher hidden while open, `aria-live`, and the textarea.

**Changed deliberately:**

| Test | Was | Now | Why |
|---|---|---|---|
| `AssistantTest` "bubble is hidden when any of the three switches is off" | `assertSee('AI Assistant')`, `('How can I help you today?')` ×4 | `'Ask GadgetPlug'`, `'Ask about sales, stock, prices or debts'` | new title and empty-state copy from the brief |
| `PermissionAndTenantScopingTest` "the chat bubble is hidden … for staff without use_ai_assistant" | `'How can I help you today?'` ×2 | `'Ask about sales, stock, prices or debts'` | same copy change; the test still proves hidden versus shown |
| `VendorDailyCapTest` "the chat bubble counts the business's questions against its cap" | `assertSee('37 / 40 today')` | renamed "…shows the business's questions left, only once 10 or fewer remain": `questionsLeft` 3 shows "3 left today"; with the cap at 100 (63 left) "left today" isn't shown | item 9 |

`ChatBubbleTimeoutAndUiTest` is **unchanged** and passes. Its hooks (`wire:key="msg-…"`, `x-ref="chatEnd"`, `wire:submit.prevent="sendMessage"`, `x-ref="messageInput"`, the four chips, `selectChip(`) are all kept, and the timeout test still sees the backend's "took too long" message.

### Gate

Full suite, 2026-11-11, 06:08 to 06:42 UTC, results in `storage/gate/feat-assistant-ui-full-20261111-0608.*`: 3,349 tests (3,327 passed, 3 skipped). **19 failures, the baseline list by name**: nothing new, nothing gone.

An earlier run (04:12 to 05:12 UTC) had 20: the extra was AccountAffiliateDashboardTest "the dashboard shows the affiliate's pending and available wallet balances". That run overlapped a stalled earlier run and another session's parallel run. The test passes alone (10/10), but its first test takes 60 to 90 s. It has nothing to do with the chat, and it is noted as load-sensitive.

**Merge gate:** after merging main (`7f368e7`: stores by name, own sales, model interface) into the branch, the full suite ran again, 2026-11-11, 10:11 to 11:10 UTC (`storage/gate/feat-assistant-ui-merge-20261111-1011.*`): 3,366 tests, **19 failures, the baseline list by name**. Main's commits touched no CSS, JS or views, so `public/build` merged cleanly and needed no rebuild.

### Retry counting (as decided)

Retry sends the same question again. A question refused before it reached the model (switched off, no access, invalid) was never counted, so the retry counts once. A question that reached the model and then failed at the provider was counted, by the owner's decision that failed questions count, so its retry counts again. The backend does this, and the UI follows it.

### Screenshots

`screenshots/before/` (taken from main at `077fdd4`) and `screenshots/after/` cover 320 (after only), 360, 390, 768 and 1280 px, light and dark. They are local demo data only (the seeded TechHaven owner "Chidi Okonkwo" and demo products), taken headlessly through Chrome's DevTools protocol with Node's built-in WebSocket; nothing was installed. `after/overflow.json` holds the measurements.

## Needs backend branch (`feat/assistant-phase2`)

1. **Product IDs on product rows:** `find_products` branch rows, and `low_stock` / `out_of_stock` items, so card rows can open the product in the panel. About 10 lines in `ProductCatalogue::branchRow()` and the two stock queries; no prompt tokens.
2. **Feedback storage:** a table, an action, and an admin list of thumbs-down with comments. Until it exists, the thumbs buttons stay hidden.
3. **Answer style** (about 95 prompt tokens). **Ships with the Phase 3 cards, not before**, because "don't repeat the list" would hide lists until cards exist:
   > Start with the direct answer in one sentence; for "how many", give the total first ("You have 5 Oraimo power banks in Main Store, across 4 models."). When a tool returns a list, give that line and at most one useful observation ("2 models are down to their last unit"); don't repeat the list. Plain words and ₦ amounts; no Markdown headings.

## Notes for later phases

- Phase 2: the four empty-state chips are still the old hard-coded ones ("Do I owe any suppliers?" is owner-only). They become permission-aware.
- Phase 2: auto-title conversations from the first question (about 40 characters, no AI call). Existing titles stay unless renamed.
- Phase 4: messages still load whole per conversation; lazy-load older ones.
- Streaming answers and "Checking stock…"-style progress need the backend to stream or report progress; today it's one synchronous request.
