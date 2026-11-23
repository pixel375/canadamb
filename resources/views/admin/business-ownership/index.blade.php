<x-layouts.admin title="Ownership reviews">
    <div class="page-intro"><div><h1>Ownership and dispute reviews</h1><p>Cases require independent proof. Transfers cannot happen without the designated successor accepting first.</p></div></div>
    <section class="panel">
        @forelse($cases as $case)
            <article class="item-row">
                <div><h2>{{ $case->name }}</h2><p>{{ ucfirst($case->type) }} · {{ str_replace('_', ' ', $case->status) }} · {{ $case->created_at }}</p></div>
                <a class="button button-outline" href="/admin/ownership-cases/{{ $case->id }}">Review privately</a>
            </article>
        @empty <p>No ownership cases awaiting independent action.</p> @endforelse
        {{ $cases->links() }}
    </section>
</x-layouts.admin>
