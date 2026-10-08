<div class="d-flex flex-wrap justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-1">Reports</h1><p class="text-secondary mb-0">Operational reports with filters, CSV export and print.</p></div></div>
<div class="card shadow-sm mb-3"><div class="card-body"><div class="row g-2">
<div class="col-lg-4"><label class="form-label">Report</label><select id="type" class="form-select">
<option value="stock_summary">Stock Summary</option><option value="cylinder_history">Cylinder History</option><option value="cylinders_sold">Cylinders Sold</option><option value="customer_ledger">Customer Ledger</option><option value="supplier_ledger">Supplier Ledger</option><option value="outstanding">Outstanding Balances</option><option value="customer_cylinders">Cylinders Held by Customer</option><option value="cash">Daily Cash Register</option><option value="sales">Sales History</option><option value="receipts">Receipt History</option><option value="payments">Payment History</option><option value="expenses">Expense Report</option>
</select></div>
<div class="col-md-2"><label class="form-label">From</label><input id="from" type="date" class="form-control" value="<?=e($today)?>"></div>
<div class="col-md-2"><label class="form-label">To</label><input id="to" type="date" class="form-control" value="<?=e($today)?>"></div>
<div class="col-md-2"><label class="form-label">Party ID</label><input id="party" class="form-control" placeholder="Optional"></div>
<div class="col-md-2"><label class="form-label">Counter ID</label><input id="counter" class="form-control" placeholder="Optional"></div>
<div class="col-md-3"><label class="form-label">Cylinder Code</label><input id="code" class="form-control" placeholder="For cylinder history"></div>
<div class="col-md-3"><label class="form-label">Party Type</label><select id="partyType" class="form-select"><option>CUSTOMER</option><option>SUPPLIER</option></select></div>
<div class="col-md-6 d-flex align-items-end gap-2"><button id="run" class="btn btn-primary">Run</button><button id="export" class="btn btn-outline-secondary">Export CSV</button><button id="print" class="btn btn-outline-secondary">Print</button></div>
</div></div></div>
<div class="card shadow-sm"><div class="card-header" id="title">Report</div><div class="card-body"><div class="table-responsive"><table class="table table-sm"><thead id="head"></thead><tbody id="rows"></tbody></table></div></div></div>
<script>
$(function(){const b=<?=json_encode(url('/'))?>;
function esc(v){return $('<div>').text(v??'').html();}
function query(){return{type:$('#type').val(),from:$('#from').val(),to:$('#to').val(),party_id:$('#party').val(),counter_id:$('#counter').val(),code:$('#code').val(),party_type:$('#partyType').val()};}
function run(){ $.getJSON(b+'reports/data',query()).done(r=>{if(!r.ok){alert(r.message);return;}$('#title').text(r.data.title);const h=$('#head').empty(),body=$('#rows').empty();(r.data.columns||[]).forEach(c=>h.append('<th>'+esc(c)+'</th>'));(r.data.rows||[]).forEach(row=>{let t='<tr>';(r.data.columns||[]).forEach(c=>t+='<td>'+esc(row[c])+'</td>');body.append(t+'</tr>');});}).fail(x=>alert(x.responseJSON?.message||'Report failed')); }
$('#run').click(run);$('#type,#partyType').on('change',run);
$('#export').click(()=>{const q=query();window.location=b+'reports/export?'+new URLSearchParams(q).toString();});
$('#print').click(()=>{const w=window.open('','_blank');if(!w)return;w.document.write('<html><head><title>'+esc($('#title').text())+'</title><style>body{font-family:Arial,sans-serif;font-size:12px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #bbb;padding:4px;text-align:left}</style></head><body><h3>'+esc($('#title').text())+'</h3>'+$('#rows').closest('table')[0].outerHTML+'</body></html>');w.document.close();w.focus();w.print();});
run();});
</script>