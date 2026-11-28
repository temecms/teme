<section data-override="comment-form">
    <form method="post" action="{{ $form->action }}">
        <x-teme::comment-form-fields :form="$form" />
        <textarea name="text"></textarea>
        <button type="submit">Post</button>
    </form>
</section>
