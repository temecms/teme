<p data-posts="{{ $site->postsUrl }}" @foreach ($site->archives as $type => $url) data-archive-{{ $type }}="{{ $url }}" @endforeach></p>
