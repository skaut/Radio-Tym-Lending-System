function getFilterInputElement() {
    const input = document.querySelector('#inputFilter')
    return input instanceof HTMLInputElement ? input : null
}

const scannerState = {
    clearFilterAfterReturn: false
}

const filterState = {
    debounceTimeoutId: null
}

function cancelPendingFilter() {
    if (filterState.debounceTimeoutId !== null) {
        window.clearTimeout(filterState.debounceTimeoutId)
        filterState.debounceTimeoutId = null
    }
}

function clearFilterInput() {
    cancelPendingFilter()

    const input = getFilterInputElement()
    if (input) {
        input.value = ''
    }

    applyFilterValue('')
}

function escapeFilterValue(value) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

function buildSearchText(row) {
    const radioId = row.querySelector('.cell-radio-id')?.textContent ?? ''
    const name = row.querySelector('.cell-name')?.textContent ?? ''
    const borrower = row.querySelector('.cell-borrower')?.textContent ?? ''

    return [radioId, name, borrower].join(' ')
}

function getNormalizedFilterPrefix(filterValue) {
    return filterValue.trim().slice(0, 3).toLowerCase()
}

function extractChannelFilterValue(filterValue) {
    const normalizedFilterValue = filterValue.trim()
    if (getNormalizedFilterPrefix(normalizedFilterValue) !== 'ch:') {
        return ''
    }

    return normalizedFilterValue.slice(3).trim()
}

function extractRadioIdFilterValue(filterValue) {
    const normalizedFilterValue = filterValue.trim()
    if (getNormalizedFilterPrefix(normalizedFilterValue) !== 'id:') {
        return ''
    }

    return normalizedFilterValue.slice(3).trim()
}

function rowMatchesExactChannelFilter(row, channelFilterValue) {
    const channelSelect = row.querySelector('select[name="channel"]')
    if (!(channelSelect instanceof HTMLSelectElement)) {
        return false
    }

    return channelSelect.value.trim().toLowerCase() === channelFilterValue.toLowerCase()
}

function rowMatchesRadioIdFilter(row, radioIdFilterValue) {
    const radioId = row.querySelector('.cell-radio-id')?.textContent ?? ''
    return radioId.trim().toLowerCase() === radioIdFilterValue.toLowerCase()
}

function getMatchingRows(filterValue) {
    const rows = Array.from(document.querySelectorAll('#mainTable tbody tr'))
    const normalizedFilterValue = filterValue.trim()

    if (normalizedFilterValue === '') {
        return rows
    }

    const filterPrefix = getNormalizedFilterPrefix(normalizedFilterValue)
    const channelFilterValue = extractChannelFilterValue(normalizedFilterValue)
    if (filterPrefix === 'ch:') {
        if (channelFilterValue === '') {
            return []
        }

        return rows.filter(function (row) {
            return rowMatchesExactChannelFilter(row, channelFilterValue)
        })
    }

    const radioIdFilterValue = extractRadioIdFilterValue(normalizedFilterValue)
    if (filterPrefix === 'id:') {
        if (radioIdFilterValue === '') {
            return []
        }

        return rows.filter(function (row) {
            return rowMatchesRadioIdFilter(row, radioIdFilterValue)
        })
    }

    const regex = new RegExp(escapeFilterValue(normalizedFilterValue), 'i')
    return rows.filter(row => regex.test(buildSearchText(row)))
}

function applyFilterValue(filterValue) {
    const rows = Array.from(document.querySelectorAll('#mainTable tbody tr'))
    const matchingRows = new Set(getMatchingRows(filterValue))

    rows.forEach(function (row) {
        row.style.display = matchingRows.has(row) ? '' : 'none'
    })
}

function filterInput() {
    const input = getFilterInputElement()
    if (!input) {
        return
    }

    cancelPendingFilter()

    const filterValue = input.value.trim()

    // A QR/barcode scanner sends a full URL followed by Enter. Avoid re-filtering
    // the whole table for each scanned character while that payload is still arriving.
    if (isScannerUrlValue(filterValue)) {
        return
    }

    filterState.debounceTimeoutId = window.setTimeout(function () {
        filterState.debounceTimeoutId = null
        applyFilterValue(filterValue)
    }, 200)
}

function isScannerUrlValue(value) {
    return /^https?:\/\//i.test(value.trim())
}

function extractRadioIdFromScannerValue(value) {
    const match = value.trim().match(/^https?:\/\/[^/]+\/src\/([^/?#]+)\/?$/i)
    return match ? decodeURIComponent(match[1]) : ''
}

function findRowByRadioId(radioId) {
    return document.querySelector(`#mainTable tbody tr[data-radio-code="${CSS.escape(radioId)}"]`)
}

function handleScannerSubmit() {
    cancelPendingFilter()

    const input = getFilterInputElement()
    if (!input) {
        return
    }

    const radioId = extractRadioIdFromScannerValue(input.value)
    if (radioId === '') {
        return
    }

    input.value = radioId
    applyFilterValue(radioId)

    const row = findRowByRadioId(radioId)
    if (!row) {
        return
    }

    const returnForm = row.querySelector('form.action-form[action="/radio-action/return"]')
    if (returnForm instanceof HTMLFormElement && typeof window.rtlsAsyncSubmit === 'function') {
        scannerState.clearFilterAfterReturn = true
        window.rtlsAsyncSubmit(returnForm)
        return
    }

    if (typeof window.rtlsFocusFilterInput === 'function') {
        window.rtlsFocusFilterInput()
    }
}

window.rtlsAfterAsyncRadioUpdate = function (radio, form) {
    if (
        !(form instanceof HTMLFormElement)
    ) {
        return
    }

    if (scannerState.clearFilterAfterReturn && form.action.indexOf('/radio-action/return') !== -1) {
        scannerState.clearFilterAfterReturn = false
        clearFilterInput()
        return
    }

    const input = getFilterInputElement()
    if (
        input
        && input.value.trim() !== ''
        && form.action.indexOf('/radio-action/lend') !== -1
    ) {
        clearFilterInput()
    }
}

document.addEventListener('DOMContentLoaded', function () {
    const input = getFilterInputElement()
    if (!(input instanceof HTMLInputElement)) {
        return
    }

    input.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter' || !isScannerUrlValue(input.value)) {
            return
        }

        event.preventDefault()
        handleScannerSubmit()
    })
})
