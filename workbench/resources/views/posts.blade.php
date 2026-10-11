<ul>
    @foreach (range(1, 5) as $post)
        <li>{{ \Illuminate\Support\Facades\DB::selectOne('select ? as author', [$post])->author }}</li>
    @endforeach
</ul>
