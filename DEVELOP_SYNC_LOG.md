# DEVELOP_SYNC_LOG.md — ported-commits ledger

Living state for the SF7 ⇄ `develop` synchronization (see `SYNCHRONIZATION_PLAN.md`).

## High-water mark
- **Synced `origin/develop` up to:** `d42d71a4` (the fork point — nothing ported yet)
- **This run's pinned target (T):** `f418ad30` (origin/develop @ 2026-06-22)
- **Last sync date:** (in progress — first run)

## Decisions legend
`Ported` · `Skipped-obsolete` · `Skipped-already-handled` (the SF7 upgrade already covers it) ·
`Deferred` · `Pending` (not yet processed this run)

## Ported-commits table (run 1: d42d71a4 → f418ad30, 63 non-merge commits)

| # | dev SHA | date | subject | group | decision | branch commit | notes |
|---|---------|------|---------|-------|----------|---------------|-------|
| 1 | a7945ea7 | 2026-03-16 | HOM url checker. | | Pending | | |
| 2 | 7749a427 | 2026-03-16 | ODR's 'default search key' system expanded to be more of a 'default search parameters' sys | | Pending | | |
| 3 | c2661c5f | 2026-03-16 | Disabled the 'this layout has been synchronized...' notification | bugfix | Ported | Phase C2 | commented notify_of_sync in ODRRenderService + wrapped the notify_of_sync block in {# #} in 6 templates (display/edit/fakeedit/shortresults/textresults x2) |
| 4 | 8fd3d7d7 | 2026-03-17 | Search results with a single record should no longer redirect to the Display route | bugfix | Ported | Phase C1 | SearchBundle DefaultController: redirectToSingleDatarecord -> $this->forward(); forward target converted to SF7 FQCN |
| 5 | 4348503b | 2026-03-17 | XYZData fields now silently switch display types to match whichever parameters they receiv | | Pending | | |
| 6 | c58aa638 | 2026-03-17 | HOM URL resolver system for use with IMA.  Probably should move this to IMA. | | Pending | | |
| 7 | 8f3c3af6 | 2026-03-19 | fixed commit 4348503 | | Pending | | |
| 8 | 9109c628 | 2026-03-19 | Apparently forgot to test commit 7749a42 by massediting a datatype without a default searc | | Pending | | |
| 9 | 60e75506 | 2026-03-23 | Modified ODREventSubscriber to use a redis cache entry to determine which plugin functions | | Pending | | |
| 10 | 20308a05 | 2026-03-23 | Fixes for issue #271 | bugfix | Ported | Phase C2 | csvtable "ordering": false->true; added CSVTable thead th text-wrap:nowrap to odr.css + odr_wordpress.css |
| 11 | 37e1d79f | 2026-04-09 | The 'current linked records' div on the Edit pages is now also on the View pages, satisfie | | Pending | | |
| 12 | 01b1279c | 2026-04-09 | SearchSidebars for datatypes with a default search key involving the 'inverse' parameter s | | Pending | | |
| 13 | d3e9f97e | 2026-04-09 | The problems dialog in the Plugin List page now displays grandparent datatype | bugfix | Ported | Phase C4 | PluginsController: query joins gdtm; threaded grandparent name through insertPluginUpdateError (new param + nesting level) and getMappedDatafields; plugin_problems.html.twig gains a "Child Database" column + extra loop level |
| 14 | 053a9773 | 2026-04-09 | Create a new validation action to delete links between datarecords when there is no link b | | Pending | | |
| 15 | c1db5f69 | 2026-04-10 | Performed #281, and fixed an issue where the various reference plugins would crash if the  | | Pending | | |
| 16 | 5ea9915f | 2026-04-13 | Forgot that #278 should only happen when the user is logged in... | | Pending | | |
| 17 | 12cbb3d7 | 2026-04-14 | Override getRootDir() in AppKernel to support symlinked instances | | Pending | | |
| 18 | 55650b44 | 2026-04-15 | Adding script to link a new virtualhost from a source tree. | | Pending | | |
| 19 | a6248ed3 | 2026-04-15 | Fix to ensure prefixed routes are used when creating linked instances. | | Pending | | |
| 20 | f8f1e86c | 2026-04-22 | Query optimization when sorting involves radio options | bugfix | Ported | Phase C1 | SortService DQL: drf.dataField -> ro.dataField |
| 21 | dcba252d | 2026-05-06 | Add "modify search" button for wordpress integrated searches. | | Pending | | |
| 22 | 4c09003b | 2026-05-06 | Fixing single search result for modify search system. | | Pending | | |
| 23 | cdf68594 | 2026-05-06 | Hiding search link in wordpress integrated state. | | Pending | | |
| 24 | 0f9954eb | 2026-05-07 | Start of sitemap/SEO system. | | Pending | | |
| 25 | 57ad6a20 | 2026-05-08 | Sitemap caching system for ODR. | | Pending | | |
| 26 | 49b048e4 | 2026-05-08 | Adding login detection and redirecting logged in users to live page. | | Pending | | |
| 27 | f8cfbf18 | 2026-05-08 | Fixes to display. | | Pending | | |
| 28 | 3b90d0b5 | 2026-05-11 | seemingly mostly working, but creating a restore point prior to potentially screwing up ad | | Pending | | |
| 29 | 878400fd | 2026-05-12 | Adding delete record system to API. | | Pending | | |
| 30 | 3bd0d52a | 2026-05-14 | Fix to API path. | | Pending | | |
| 31 | 5b64008c | 2026-05-14 | Fixes due to accidental commit.  No security issues. | | Pending | | |
| 32 | 2be0b76f | 2026-05-15 | API Fix for Deleting Records. | | Pending | | |
| 33 | 4d58505c | 2026-05-19 | more potential problems | | Pending | | |
| 34 | a7e2ef9d | 2026-05-20 | seem to have run into a logical contradiction caused by empty string also matching ancesto | | Pending | | |
| 35 | 14f3976e | 2026-05-20 | fix for commit 64b18c4 breaking the ability to set name/sort fields for child datatypes | bugfix | Ported | Phase C3 | DatabaseInfoService::getSpecialDatafields now takes a DataType (uses its own id, not grandparent's); private getSpecialFields->getNameSortFields; 4 callers updated (Session/ODRCustom/Displaytemplate x2) |
| 36 | 006d0e97 | 2026-05-20 | Added a new ThemeElement property to 'show when empty' | | Pending | | |
| 37 | 546a233a | 2026-05-21 | Implemented ability to change what the odr_search_immediate route uses | | Pending | | |
| 38 | 06e06792 | 2026-05-21 | forgot this part in commit 546a233 | | Pending | | |
| 39 | c412c267 | 2026-05-26 | fix for #305 | bugfix | Ported | Phase C2 | <a class="Cursor/Info">...</a> -> <span> for non-link labels in display_datafield/edit_ajax/edit_file_datafield/csvtable_display_datafield |
| 40 | 26dd4715 | 2026-05-26 | Added ability for a datatype admin to permit editing of files with specific extensions in  | | Pending | | |
| 41 | bf9c6de4 | 2026-05-27 | Added missing isset() check for one of the apparently required graph plugin options | bugfix | Ported | Phase C1 | isset($options['x_axis_dir']) guard added to GraphPlugin, FilterGraphPlugin, GCMassSpecPlugin |
| 42 | 0d93d3de | 2026-05-27 | Added a no_header version of the table display_type in the themeDatatype entity | | Pending | | |
| 43 | 97e45209 | 2026-05-28 | Updated setup_virtualhost script to set permissions properly. | infra | Deferred | | shell script perms; revisit in Phase E (infra) |
| 44 | 5137749e | 2026-05-28 | the 'logical contradiction' is technically a combination of unexpected set subtraction and | | Pending | | |
| 45 | 105c2e44 | 2026-06-01 | ...each new instance where set subtraction does something that's correct yet unexpected ma | | Pending | | |
| 46 | 9da3f32c | 2026-06-01 | fix for #315 | | Pending | | |
| 47 | 244cb7ab | 2026-06-01 | wordpress css tweak for #311 | | Pending | | |
| 48 | 7cce2cb4 | 2026-06-02 | finally to the point where nothing appears broken...but still feel like a fair bit is miss | | Pending | | |
| 49 | d557dc48 | 2026-06-02 | cleaned up previous commits somewhat... | | Pending | | |
| 50 | d81bdecc | 2026-06-08 | re-enabled the non-set merging, because set merging breaks MassEdit/CSVExport... | | Pending | | |
| 51 | 7c161e97 | 2026-06-09 | Fix to detection of sidebar and wordpress integration status for "modify search" display. | | Pending | | |
| 52 | a0ffd923 | 2026-06-09 | Apparently need to completely restrict unathenticated users from zip archive creation/down | security | Ported | Phase B | `$user_id===0` guard in 4 DisplayController + 1 ReportsController zip methods; disabled `listsearchresultfiles` + 2 routes; hid download-all UI in 4 templates |
| 53 | bdeaba90 | 2026-06-10 | Some cleanup, forgot about shennanigans in CSVExportHelperService... | | Pending | | |
| 54 | 8021e7f5 | 2026-06-11 | Added monospace class to implementation of issue #311 | | Pending | | |
| 55 | 8fe9ba6d | 2026-06-11 | got CSVExport working again, did (semi)final cleanup | | Pending | | |
| 56 | cbc36f2a | 2026-06-12 | Fixed #314 on non-wordpress side by copying relevant CSS from wordpress side | | Pending | | |
| 57 | 12d96962 | 2026-06-12 | The SearchLink page should not be directly accessible from a record's Display page | | Pending | | |
| 58 | 3f498fdd | 2026-06-12 | Forgot to change an array key in the ChemicalElementsSearch plugin as part of search syste | | Pending | | |
| 59 | a094325b | 2026-06-12 | Fixed the select/deselect buttons on the SearchLink page not displaying when coming from t | | Pending | | |
| 60 | 866664e1 | 2026-06-18 | Fixes to routing and wordpress integration API security. | security | Deferred | | **FLAGGED.** `.dist`-only (deploy-template config, not live); entangled with removed FOS/HWI route+firewall blocks (deleted in upgrade Phase 4) and a wordpress-prefix (/odr, /odr_rruff, /odr_data) token-routing scheme that differs from the branch's authenticator-system security. Needs careful manual reconciliation + per-mode JWT testing; do not port mechanically |
| 61 | 8f6fb8c2 | 2026-06-19 | Fixes on beta - moving to develop for more work. | | Pending | | |
| 62 | 655abe7f | 2026-06-19 | ODR uses a collation sort in most places | | Pending | | |
| 63 | f418ad30 | 2026-06-22 | Deleted unused/out-of-date code from SearchAPIServiceNoConflict | | Pending | | |
