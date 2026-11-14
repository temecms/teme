{{-- The plain-text email is not Markdown: the backslashes that made the words safe for it are taken out. --}}
{{ preg_replace('/\\\\(?=[\\\\`*_\[\]!|~#+=-])/u', '', (string) $slot) }}
