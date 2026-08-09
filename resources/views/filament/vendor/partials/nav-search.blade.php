{{--
    Sidebar menu filter.

    Purely client-side on purpose: the navigation is already fully rendered in the
    DOM, so filtering it needs no round trip and stays instant on a slow phone
    connection. It matches item labels and group labels, hides groups that end up
    with nothing visible, and force-opens collapsed groups while filtering so a
    match can never hide inside a closed group.
--}}
<div
    x-data="{
        q: '',

        filter() {
            const needle = this.q.trim().toLowerCase();
            const root = this.$root.closest('.fi-sidebar-nav') ?? document;
            let visible = 0;

            // Ungrouped items and grouped items alike
            root.querySelectorAll('.fi-sidebar-item').forEach((item) => {
                const label = item.querySelector('.fi-sidebar-item-label')?.textContent?.toLowerCase() ?? '';
                const hit = needle === '' || label.includes(needle);
                item.style.display = hit ? '' : 'none';
                if (hit) visible++;
            });

            root.querySelectorAll('.fi-sidebar-group').forEach((group) => {
                const groupLabel = group.querySelector('.fi-sidebar-group-label')?.textContent?.toLowerCase() ?? '';
                const groupHit = needle !== '' && groupLabel.includes(needle);

                // A group whose own name matches shows all of its items back
                if (groupHit) {
                    group.querySelectorAll('.fi-sidebar-item').forEach((i) => { i.style.display = ''; });
                }

                const anyVisible = [...group.querySelectorAll('.fi-sidebar-item')]
                    .some((i) => i.style.display !== 'none');

                group.style.display = (needle === '' || groupHit || anyVisible) ? '' : 'none';

                // Collapsed groups would hide matches, so open them while searching
                const items = group.querySelector('.fi-sidebar-group-items');
                if (items && needle !== '') {
                    items.style.display = '';
                    items.removeAttribute('x-collapse');
                    items.style.height = '';
                }
            });

            this.noResults = needle !== '' && visible === 0;
        },

        noResults: false,

        clear() {
            this.q = '';
            this.filter();
            this.$refs.input?.focus();
        },
    }"
    x-init="$watch('q', () => filter())"
    class="gp-nav-search"
>
    <div class="gp-nav-search-field">
        <svg class="gp-nav-search-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11zM2 9a7 7 0 1112.452 4.391l3.328 3.329a.75.75 0 11-1.06 1.06l-3.329-3.328A7 7 0 012 9z" clip-rule="evenodd"/>
        </svg>

        <input
            x-ref="input"
            x-model="q"
            type="search"
            autocomplete="off"
            placeholder="{{ __('Filter menu…') }}"
            aria-label="{{ __('Filter menu') }}"
            class="gp-nav-search-input"
            @keydown.escape.stop="clear()"
        >

        <button
            type="button"
            x-show="q !== ''"
            x-cloak
            @click="clear()"
            aria-label="{{ __('Clear menu filter') }}"
            class="gp-nav-search-clear"
        >&times;</button>
    </div>

    <p x-show="noResults" x-cloak class="gp-nav-search-empty">
        {{ __('Nothing in the menu matches.') }}
    </p>
</div>
