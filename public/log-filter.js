function getLogFilterInputElement() {
    const input = document.querySelector('#inputLogFilter')
    return input instanceof HTMLInputElement ? input : null
}

const logFilterState = {
    debounceTimeoutId: null
}

function escapeLogFilterValue(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

function applyLogFilterValue(filterValue) {
    const rows = Array.from(document.querySelectorAll('#logTable tbody tr'))
    const normalizedFilterValue = filterValue.trim()

    if (normalizedFilterValue === '') {
        rows.forEach(row => row.style.display = '')
        return
    }

    const regex = new RegExp(escapeLogFilterValue(normalizedFilterValue), 'i')

    rows.forEach(function (row) {
        row.style.display = regex.test(row.textContent) ? '' : 'none'
    })
}

function logFilterInput() {
    const input = getLogFilterInputElement()
    if (!input) {
        return
    }

    if (logFilterState.debounceTimeoutId !== null) {
        window.clearTimeout(logFilterState.debounceTimeoutId)
    }

    const filterValue = input.value

    logFilterState.debounceTimeoutId = window.setTimeout(function () {
        logFilterState.debounceTimeoutId = null
        applyLogFilterValue(filterValue)
    }, 200)
}
