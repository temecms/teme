{{-- Above a preview, pushed onto teme-body-start: core's own, and styled here, so it reads the same under any theme. A signed-in person's preview has no expiry. --}}
<div class="teme-preview-bar" role="note" style="font: 13px/1.4 system-ui, sans-serif; color: #1c1c1c; background: #f2f2f2; border-block-end: 1px solid #d4d4d4; padding: 10px 16px;">
    @if ($expires === null)
        {{ __('Preview of the latest saved version') }} · {{ __('Visible only to people who can edit it') }}
    @else
        {{ __('Preview of an unpublished version') }} · {{ __('expires :date', ['date' => $expires]) }}
    @endif
</div>
