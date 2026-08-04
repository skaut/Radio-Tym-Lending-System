(function () {
    function getSortValue(row, th) {
        const type = th.dataset.sortType;
        const target = th.dataset.sortTarget ? row.querySelector(th.dataset.sortTarget) : null;

        if (type === 'numeric') {
            const raw = target instanceof HTMLSelectElement ? target.value : (target?.textContent ?? '');
            const num = parseFloat(raw);
            return Number.isNaN(num) ? -Infinity : num;
        }

        if (type === 'date') {
            return target ? (target.getAttribute('data-sort-value') || '') : '';
        }

        return (target?.textContent ?? '').trim().toLowerCase();
    }

    function sortTable(table, th) {
        const tbody = table.tBodies[0];
        if (!tbody) {
            return;
        }

        const nextDirection = th.dataset.sortDirection === 'asc' ? 'desc' : 'asc';

        Array.from(th.parentElement.children).forEach(function (otherTh) {
            if (otherTh !== th) {
                delete otherTh.dataset.sortDirection;
                otherTh.classList.remove('sorted-asc', 'sorted-desc');
            }
        });

        const multiplier = nextDirection === 'asc' ? 1 : -1;
        const rows = Array.from(tbody.querySelectorAll('tr'));

        rows.sort(function (rowA, rowB) {
            const valueA = getSortValue(rowA, th);
            const valueB = getSortValue(rowB, th);

            if (valueA < valueB) {
                return -1 * multiplier;
            }
            if (valueA > valueB) {
                return 1 * multiplier;
            }
            return 0;
        });

        rows.forEach(function (row) {
            tbody.appendChild(row);
        });

        th.dataset.sortDirection = nextDirection;
        th.classList.toggle('sorted-asc', nextDirection === 'asc');
        th.classList.toggle('sorted-desc', nextDirection === 'desc');
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('table.radios-table').forEach(function (table) {
            table.querySelectorAll('thead th[data-sort-type]').forEach(function (th) {
                th.classList.add('sortable-column');
                th.addEventListener('click', function () {
                    sortTable(table, th);
                });
            });
        });
    });
})();
