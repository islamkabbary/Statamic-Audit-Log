{{-- Scoped styles for the audit log screens. Raw CSS on purpose: the CP stylesheet is purged
     to Statamic's own templates, so arbitrary utility classes may not exist in it. --}}
<style>
    .audit-badge { display: inline-block; padding: 1px 8px; border-radius: 999px; font-size: 11px; font-weight: 600; line-height: 18px; white-space: nowrap; background: #eef0f3; color: #3b4452; }
    .audit-badge--created, .audit-badge--uploaded, .audit-badge--published { background: #e3f6ea; color: #1d6b3a; }
    .audit-badge--updated, .audit-badge--moved, .audit-badge--replaced { background: #e6f0fd; color: #1f4f96; }
    .audit-badge--deleted, .audit-badge--unpublished { background: #fdeaea; color: #9b2323; }
    .audit-badge--legacy { background: #fff4de; color: #86560a; }
    .audit-muted { color: #737f8c; }
    .audit-fields { color: #737f8c; font-size: 12px; margin-top: 2px; }
    .audit-row { cursor: pointer; }
    .audit-row:hover td { background: #f7f9fb; }
    .audit-filters { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px; align-items: end; }
    .audit-filters label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px; color: #3b4452; }
    .audit-filters .input-text { width: 100%; }
    .audit-meta { display: grid; grid-template-columns: 170px 1fr; gap: 8px 16px; font-size: 14px; }
    .audit-meta dt { color: #737f8c; }
    .audit-meta dd { margin: 0; word-break: break-word; }
    .audit-change { border-top: 1px solid #e5e8ec; padding: 14px 16px; }
    .audit-change:first-child { border-top: 0; }
    .audit-change__label { font-weight: 600; margin-bottom: 8px; }
    .audit-change__label small { font-weight: 400; color: #737f8c; margin-left: 6px; }
    .audit-cmp { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .audit-cmp--single { grid-template-columns: 1fr; }
    .audit-val { border-radius: 4px; padding: 8px 10px; font-size: 13px; white-space: pre-wrap; word-break: break-word; min-height: 36px; }
    .audit-val__cap { display: block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; margin-bottom: 3px; opacity: .75; }
    .audit-val--old { background: #fdf0f0; color: #7a1f1f; border: 1px solid #f5d0d0; }
    .audit-val--new { background: #eefaf2; color: #1b5e33; border: 1px solid #c8ecd5; }
    .audit-val--plain { background: #f5f7f9; color: #3b4452; border: 1px solid #e5e8ec; }
    .audit-empty { font-style: italic; opacity: .6; }
    .audit-list { list-style: none; margin: 0; padding: 0; font-size: 13px; }
    .audit-list li { padding: 3px 8px; border-radius: 3px; margin-bottom: 3px; word-break: break-word; }
    .audit-list .add { background: #eefaf2; color: #1b5e33; }
    .audit-list .rem { background: #fdf0f0; color: #7a1f1f; }
    .audit-list .more { background: #f5f7f9; color: #737f8c; }
    .audit-nested { width: 100%; font-size: 13px; border-collapse: collapse; }
    .audit-nested th { text-align: left; font-size: 11px; color: #737f8c; padding: 4px 6px; }
    .audit-nested td { vertical-align: top; padding: 4px 6px; border-top: 1px solid #eef0f3; word-break: break-word; }
    .audit-nested td.path { font-family: ui-monospace, monospace; font-size: 12px; color: #3b4452; width: 30%; }
    .audit-nested td.o { background: #fdf0f0; color: #7a1f1f; }
    .audit-nested td.n { background: #eefaf2; color: #1b5e33; }
    .audit-pager { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; padding: 12px 16px; font-size: 13px; border-top: 1px solid #e5e8ec; }
    .audit-pages { display: flex; gap: 4px; flex-wrap: wrap; }
    .audit-page { min-width: 32px; height: 32px; padding: 0 8px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid #dde1e6; border-radius: 4px; background: #fff; color: #3b4452; text-decoration: none; }
    .audit-page:hover { background: #f1f4f7; }
    .audit-page.is-current { background: #3b82f6; border-color: #3b82f6; color: #fff; font-weight: 600; }
    .audit-page.is-disabled { opacity: .4; }
    .audit-page.is-gap { border-color: transparent; background: transparent; }
    @media (max-width: 700px) { .audit-cmp, .audit-meta { grid-template-columns: 1fr; } }
    .audit-back { display: inline-block; margin-bottom: 8px; font-size: 13px; color: #737f8c; text-decoration: none; }
    .audit-back:hover { color: #3b4452; }
</style>

{{-- Statamic 6 rebuilt the CP on new components and dropped the v4/v5 utility classes these
     screens use (btn, input-text, text-blue…). Recreate just those, scoped to the audit screens. --}}
@if (\IslamKabbary\AuditLog\Support\StatamicVersion::atLeast(6))
<style>
    .audit-screen h1 { font-size: 20px; font-weight: 600; }
    .audit-screen .card { background: #fff; border: 1px solid #e5e8ec; border-radius: 8px; padding: 16px; box-shadow: 0 1px 2px rgba(0,0,0,.04); }
    .audit-screen .card.p-0 { padding: 0; }
    .audit-screen .p-4 { padding: 16px; }
    .audit-screen .mb-2 { margin-bottom: 8px; } .audit-screen .mb-4 { margin-bottom: 16px; } .audit-screen .mb-6 { margin-bottom: 24px; } .audit-screen .mt-3 { margin-top: 12px; }
    .audit-screen .flex { display: flex; } .audit-screen .items-center { align-items: center; } .audit-screen .justify-between { justify-content: space-between; }
    .audit-screen .text-sm { font-size: 13px; } .audit-screen .font-bold { font-weight: 700; } .audit-screen .whitespace-nowrap { white-space: nowrap; }
    .audit-screen .text-blue { color: #2563eb; }
    .audit-screen .btn, .audit-screen .btn-primary { display: inline-flex; align-items: center; height: 36px; padding: 0 14px; border-radius: 6px; font-size: 13px; font-weight: 500; border: 1px solid #d4d8de; background: #fff; color: #3b4452; text-decoration: none; cursor: pointer; }
    .audit-screen .btn-primary { background: #2563eb; border-color: #2563eb; color: #fff; }
    .audit-screen .input-text { height: 36px; padding: 0 10px; border: 1px solid #d4d8de; border-radius: 6px; background: #fff; font-size: 13px; }
    .audit-screen .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .audit-screen .data-table th { text-align: start; font-size: 12px; font-weight: 600; color: #737f8c; padding: 10px 16px; border-bottom: 1px solid #e5e8ec; }
    .audit-screen .data-table td { padding: 10px 16px; border-bottom: 1px solid #f0f2f4; vertical-align: top; }
    [dir="rtl"] .audit-screen .rtl\:ml-2 { margin-left: 8px; } [dir="ltr"] .audit-screen .ltr\:mr-2 { margin-right: 8px; }
</style>
@endif
