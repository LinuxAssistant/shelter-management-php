'use strict';
(() => {
    const resident = document.getElementById('occupancy-resident');
    const bed = document.getElementById('occupancy-bed');
    const search = document.getElementById('resident-search');
    const status = document.getElementById('resident-search-status');
    let timer, controller, generation = 0;
    function filterBeds() {
        const facility = resident.selectedOptions[0]?.dataset.facility || '';
        for (const option of bed.options) {
            if (!option.value) continue;
            option.hidden = !facility || option.dataset.facility !== facility;
            option.disabled = option.hidden;
        }
        if (bed.selectedOptions[0]?.disabled) bed.value = '';
    }
    resident.addEventListener('change', filterBeds);
    search.addEventListener('input', () => {
        clearTimeout(timer);
        controller?.abort();
        const current = ++generation;
        resident.replaceChildren(new Option('Search to select a resident', ''));
        filterBeds();
        const q = search.value.trim();
        if ([...q].length < 2 || [...q].length > 100) {
            status.textContent = 'Enter 2 to 100 characters.';
            return;
        }
        status.textContent = 'Searching…';
        timer = setTimeout(async () => {
            controller = new AbortController();
            try {
                const response = await fetch('/api/residents/search?q=' + encodeURIComponent(q), {
                    signal: controller.signal, headers: {Accept: 'application/json'}, cache: 'no-store'
                });
                if (!response.ok || response.redirected) throw new Error('Search unavailable');
                const rows = await response.json();
                if (!Array.isArray(rows)) throw new Error('Invalid response');
                if (current !== generation) return;
                for (const row of rows) {
                    const option = new Option(row.facility_name + ' — ' + row.display_name + ' (' + row.reference_code + ')', String(row.id));
                    option.dataset.facility = String(row.facility_id);
                    resident.add(option);
                }
                status.textContent = rows.length ? rows.length + ' results. Select a resident.' + (rows.length === 20 ? ' Refine your search if needed.' : '') : 'No available residents found.';
            } catch (error) {
                if (current !== generation || error.name === 'AbortError') return;
                status.textContent = 'Search failed. Try again or sign in again.';
            }
        }, 250);
    });
    filterBeds();
})();
