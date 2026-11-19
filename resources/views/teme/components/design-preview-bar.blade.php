{{-- Above a design preview, pushed onto teme-body-start: core's own. Same look as preview-bar. --}}
{{-- Styled here, not with the admin's tokens, so it reads the same under any theme. --teme-bar-ink and --teme-bar-bg are the admin's light-mode --ink and --page (resources/css/app.css), written out once; the eye is lucide's. --}}
<style>
    .teme-preview-bar { --teme-bar-ink: oklch(0.22 0.004 260); --teme-bar-bg: oklch(0.9875 0 0); position: sticky; inset-block-start: 0; z-index: 1000; display: flex; align-items: center; gap: 12px; min-block-size: 40px; padding: 8px 16px; box-sizing: border-box; background: var(--teme-bar-ink); color: var(--teme-bar-bg); font: 14px/1.4 system-ui, sans-serif; }
    .teme-preview-bar svg { flex: none; }
    .teme-preview-bar p { margin: 0; flex: 1; }
    .teme-preview-bar a { color: inherit; font-weight: 500; text-decoration: underline; }
</style>
<div class="teme-preview-bar" role="note">
    <svg aria-hidden="true" focusable="false" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/></svg>
    <p>{{ __('Previewing unpublished design changes') }} · <a href="{{ $back }}">{{ __('Back to Themes') }}</a></p>
</div>
