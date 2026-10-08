<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h1 class="h3">Point of Sale</h1>
        <p class="text-secondary mb-0">Issue gas or sell cylinders with one atomic transaction.</p>
    </div>
</div>

<div id="posError" class="alert alert-danger d-none" role="alert"></div>

<div class="card shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2">
            <div class="col-md-3">
                <label class="form-label" for="transactionType">Transaction Type</label>
                <select id="transactionType" class="form-select"></select>
            </div>
            <div class="col-md-4">
                <label class="form-label" for="customerSearch">Customer</label>
                <input id="customerSearch" class="form-control" placeholder="Search customer code, name or phone" autocomplete="off">
                <select id="customer" class="form-select mt-1" aria-label="Customer"></select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="txnDate">Date</label>
                <input id="txnDate" type="date" class="form-control" value="<?= e($today) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="method">Payment</label>
                <select id="method" class="form-select">
                    <option value="CASH">Cash</option>
                    <option value="ONLINE">Online</option>
                    <option value="CHEQUE">Cheque</option>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label" for="received">Received</label>
                <input id="received" class="form-control" inputmode="decimal" value="0.00">
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong>Shop Cylinders</strong>
        <select id="group" class="form-select form-select-sm" style="max-width:260px">
            <option value="0">All groups</option>
        </select>
    </div>
    <div class="card-body">
        <div id="cylinders" class="row g-2"></div>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
            <div>
                <strong>Gas: <span id="gasTotal">0.000</span> kg</strong>
                <span class="mx-2 text-secondary">|</span>
                <strong>Total: <span id="total">0.00</span></strong>
            </div>
            <button id="save" class="btn btn-primary">Post Sale</button>
        </div>
    </div>
</div>

<script>
$(function () {
    const base = <?= json_encode(url('/'), JSON_THROW_ON_ERROR) ?>;
    const $type = $('#transactionType');
    const $group = $('#group');
    const $cylinders = $('#cylinders');
    const $error = $('#posError');

    function showError(message) {
        $error.text(message || 'An unexpected error occurred.').removeClass('d-none');
    }

    function clearError() {
        $error.addClass('d-none').text('');
    }

    function esc(value) {
        return $('<div>').text(value ?? '').html();
    }

    function labelForType(type) {
        return String(type || '')
            .replaceAll('_', ' ')
            .toLowerCase()
            .replace(/\b\w/g, function (char) { return char.toUpperCase(); });
    }

    function transactionType() {
        return $type.val() || 'GAS_SALE';
    }

    function loadConfig() {
        return $.getJSON(base + 'pos/config').done(function (response) {
            if (!response.ok) {
                throw new Error(response.message || 'Unable to load POS configuration.');
            }

            const types = response.data.transaction_types || [];
            const defaultType = response.data.default_transaction_type || types[0] || 'GAS_SALE';

            $type.empty();
            types.forEach(function (type) {
                $type.append(new Option(labelForType(type), type));
            });

            if (!types.length) {
                showError('No POS transaction types are configured.');
                return;
            }

            $type.val(types.includes(defaultType) ? defaultType : types[0]);
        }).fail(function (xhr) {
            showError(xhr.responseJSON?.message || 'Unable to load POS transaction types.');
        });
    }

    function recalculate() {
        let gasTotal = 0;
        let total = 0;

        $cylinders.find('.cylinder-card').each(function () {
            const $card = $(this);
            if (!$card.find('.pick').is(':checked')) {
                return;
            }

            const type = transactionType();
            const gas = type === 'EMPTY_CYLINDER_SALE'
                ? 0
                : Number($card.find('.gas').val() || 0);
            const rate = Number($card.find('.rate').val() || 0);
            const cylinderPrice = Number($card.find('.cp').val() || 0);
            const sell = $card.find('.sell').is(':checked');

            gasTotal += gas;
            if (type === 'EMPTY_CYLINDER_SALE') {
                total += cylinderPrice;
            } else if (sell) {
                total += (gas * rate) + cylinderPrice;
            } else {
                total += gas * rate;
            }
        });

        $('#gasTotal').text(gasTotal.toFixed(3));
        $('#total').text(total.toFixed(2));
    }

    function refreshMode() {
        const emptySale = transactionType() === 'EMPTY_CYLINDER_SALE';

        $cylinders.find('.sell-wrap').toggle(!emptySale);
        $cylinders.find('.rate').prop('disabled', emptySale).toggle(!emptySale);
        $cylinders.find('.gas').prop('disabled', emptySale);

        $cylinders.find('.cylinder-card').each(function () {
            const $card = $(this);
            $card.find('.pick').prop('disabled', false);
            if (emptySale) {
                $card.find('.gas').val('0.000');
                $card.find('.sell').prop('checked', false);
            }
        });

        recalculate();
    }

    function loadCylinders() {
        clearError();

        const selectedGroup = $group.val() || '0';
        const type = transactionType();
        const date = $('#txnDate').val();

        $.getJSON(base + 'pos/cylinders', {
            group_id: selectedGroup,
            transaction_type: type,
            txn_date: date
        }).done(function (response) {
            if (!response.ok) {
                showError(response.message || 'Unable to load cylinders.');
                return;
            }

            const rows = response.data || [];
            const groups = {};
            $cylinders.empty();

            rows.forEach(function (row) {
                groups[row.group_id] = row.group_code + ' - ' + row.group_name + ' (' + row.capacity_kg + ' kg)';

                const gas = Number(row.gas_kg || 0);
                const rate = row.gas_rate ?? '';
                const cylinderPrice = row.cylinder_price ?? '';
                const missingRate = type !== 'EMPTY_CYLINDER_SALE' && (rate === '' || Number(rate) <= 0);
                const missingCylinderPrice = cylinderPrice === '' || Number(cylinderPrice) <= 0;

                $cylinders.append(
                    '<div class="col-12 col-sm-6 col-xl-3">' +
                        '<div class="cylinder-card border rounded p-3 h-100" data-id="' + esc(row.id) + '" data-default-gas="' + esc(row.gas_kg) + '">' +
                            '<div class="d-flex justify-content-between align-items-start gap-2">' +
                                '<div>' +
                                    '<div class="fw-bold fs-5">' + esc(row.code) + '</div>' +
                                    '<div class="small text-secondary">' + esc(row.group_name) + '</div>' +
                                '</div>' +
                                '<span class="badge text-bg-secondary">' + (gas >= Number(row.capacity_kg) ? 'Filled' : gas > 0 ? 'Partial' : 'Empty') + '</span>' +
                            '</div>' +
                            '<div class="mt-2">Gas: <span class="gas-display">' + esc(row.gas_kg) + '</span> / ' + esc(row.capacity_kg) + ' kg</div>' +
                            '<div class="form-check mt-2">' +
                                '<input class="form-check-input pick" type="checkbox" data-id="' + esc(row.id) + '">' +
                                '<label class="form-check-label">Select</label>' +
                            '</div>' +
                            '<div class="mt-2">' +
                                '<label class="form-label small mb-1">Gas kg</label>' +
                                '<input class="form-control form-control-sm gas" data-id="' + esc(row.id) + '" data-max="' + esc(row.gas_kg) + '" value="' + esc(row.gas_kg) + '" inputmode="decimal">' +
                            '</div>' +
                            '<div class="form-check mt-2 sell-wrap">' +
                                '<input class="form-check-input sell" type="checkbox" data-id="' + esc(row.id) + '">' +
                                '<label class="form-check-label">Sell cylinder</label>' +
                            '</div>' +
                            '<div class="mt-2 rate-wrap">' +
                                '<label class="form-label small mb-1">Gas rate</label>' +
                                '<input class="form-control form-control-sm rate" data-id="' + esc(row.id) + '" value="' + esc(rate) + '" inputmode="decimal">' +
                            '</div>' +
                            '<div class="mt-2">' +
                                '<label class="form-label small mb-1">Cylinder price</label>' +
                                '<input class="form-control form-control-sm cp" data-id="' + esc(row.id) + '" value="' + esc(cylinderPrice) + '" inputmode="decimal">' +
                            '</div>' +
                            (missingRate || missingCylinderPrice
                                ? '<div class="alert alert-warning small mt-2 mb-0">Configure an effective rate before posting.</div>'
                                : '') +
                        '</div>' +
                    '</div>'
                );
            });

            $group.empty().append(new Option('All groups', '0'));
            Object.entries(groups).forEach(function (entry) {
                $group.append(new Option(entry[1], entry[0]));
            });

            if (selectedGroup !== '0' && groups[selectedGroup]) {
                $group.val(selectedGroup);
            }

            refreshMode();
        }).fail(function (xhr) {
            showError(xhr.responseJSON?.message || 'Unable to load cylinders.');
        });
    }

    $type.on('change', function () {
        loadCylinders();
    });

    $group.on('change', function () {
        loadCylinders();
    });

    $('#txnDate').on('change', function () {
        loadCylinders();
    });

    $cylinders.on('input change', '.pick,.sell,.gas,.rate,.cp', function () {
        const $input = $(this);
        const $card = $input.closest('.cylinder-card');

        if ($input.hasClass('gas') && transactionType() !== 'EMPTY_CYLINDER_SALE') {
            const max = Number($input.data('max'));
            const value = Number($input.val());
            if (Number.isFinite(max) && Number.isFinite(value) && value > max) {
                $input.val(max.toFixed(3));
            }
            if (Number.isFinite(value) && value < 0) {
                $input.val('0.000');
            }
        }

        if ($input.hasClass('pick') && !$input.is(':checked')) {
            $card.find('.sell').prop('checked', false);
        }

        recalculate();
    });

    $('#customerSearch').on('input', function () {
        const q = this.value.trim();
        const $customer = $('#customer');

        if (q.length < 2) {
            $customer.empty();
            return;
        }

        $.getJSON(base + 'pos/customers', {q: q}).done(function (response) {
            $customer.empty();
            (response.data || []).forEach(function (row) {
                $customer.append(new Option(row.code + ' - ' + row.name, row.id));
            });
        }).fail(function (xhr) {
            showError(xhr.responseJSON?.message || 'Unable to search customers.');
        });
    });

    $('#save').on('click', function () {
        clearError();

        const type = transactionType();
        const lines = [];

        $cylinders.find('.cylinder-card').each(function () {
            const $card = $(this);
            if (!$card.find('.pick').is(':checked')) {
                return;
            }

            const gas = type === 'EMPTY_CYLINDER_SALE' ? '0.000' : String($card.find('.gas').val() || '0');
            const sell = $card.find('.sell').is(':checked');

            lines.push({
                cylinder_id: Number($card.data('id')),
                type: type === 'EMPTY_CYLINDER_SALE' ? 'SELL_EMPTY' : (sell ? 'SELL_FILLED' : 'ISSUE'),
                gas_kg: gas,
                rate: String($card.find('.rate').val() || '0'),
                cylinder_price: String($card.find('.cp').val() || '0')
            });
        });

        if (!lines.length) {
            showError('Select at least one cylinder.');
            return;
        }

        if (!$('#customer').val()) {
            showError('Customer is required.');
            return;
        }

        const soldLines = type === 'EMPTY_CYLINDER_SALE'
            ? lines.length
            : lines.filter(function (line) { return line.type === 'SELL_FILLED'; }).length;

        if (soldLines > 0) {
            const gasLeaving = lines
                .filter(function (line) { return line.type === 'SELL_FILLED'; })
                .reduce(function (sum, line) { return sum + Number(line.gas_kg || 0); }, 0);

            const message = soldLines + ' cylinder(s) will leave company stock permanently' +
                (gasLeaving > 0 ? ' with ' + gasLeaving.toFixed(3) + ' kg gas.' : '.') +
                '\n\nContinue?';

            if (!window.confirm(message)) {
                return;
            }
        }

        const $button = $('#save').prop('disabled', true);

        $.post(base + 'pos', {
            txn_date: $('#txnDate').val(),
            transaction_type: type,
            customer_id: $('#customer').val(),
            method: $('#method').val(),
            received_amount: $('#received').val(),
            lines_json: JSON.stringify(lines)
        }).done(function (response) {
            if (!response.ok) {
                showError(response.message || 'Sale failed.');
                return;
            }

            window.alert(response.message + ' (' + response.data.doc_no + ')');
            loadCylinders();
        }).fail(function (xhr) {
            showError(xhr.responseJSON?.message || 'Sale failed.');
        }).always(function () {
            $button.prop('disabled', false);
        });
    });

    loadConfig().done(function () {
        loadCylinders();
    });
});
</script>
