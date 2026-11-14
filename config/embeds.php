<?php

declare(strict_types=1);

/*
 * The providers an embed card may frame (I-164). The editor fetches a URL's
 * details; the site never prints the markup a provider returns. Instead the
 * frame's address is built here, from the URL, for providers on this list
 * only. `match` is tested against the stored URL and its groups fill `frame`
 * ($1, $2). A URL no provider matches renders as a plain link. `kind` is what
 * a pasted address of the provider's becomes (video or audio), since a paste
 * has no one to pick it.
 */
return [
    'providers' => [
        'youtube' => [
            'match' => '~^https://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]+)~',
            'frame' => 'https://www.youtube-nocookie.com/embed/$1',
            'kind' => 'video',
        ],
        'vimeo' => [
            'match' => '~^https://(?:www\.)?vimeo\.com/(\d+)~',
            'frame' => 'https://player.vimeo.com/video/$1?dnt=1',
            'kind' => 'video',
        ],
        'spotify' => [
            'match' => '~^https://open\.spotify\.com/(track|album|playlist|episode|show)/([A-Za-z0-9]+)~',
            'frame' => 'https://open.spotify.com/embed/$1/$2',
            'kind' => 'audio',
        ],
    ],
];
