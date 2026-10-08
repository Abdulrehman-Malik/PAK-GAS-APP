<div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h3">Cash Counter</h1></div>
<div class="card shadow-sm mb-3"><div class="card-body"><div class="row g-2">
<div class="col-md-3"><label class="form-label">Counter</label><select id="counter" class="form-select"><?php foreach($counters as $c): ?><option value="<?=e((string)$c['id'])?>"><?=e($c['name'])?></option><?php endforeach;?></select></div>
<div class="col-md-3"><label class="form-label">Opening cash</label><input id="opening" class="form-control" value="0.00"></div>
<div class="col-md-2 d-flex align-items-end"><button id="open" class="btn btn-primary w-100">Open</button></div>
<div class="col-md-4 d-flex align-items-end"><div id="status" class="alert alert-secondary w-100 mb-0">Checking session...</div></div>
</div></div></div>
<div class="card shadow-sm mb-3"><div class="card-header"><strong>Manual Cash In / Out</strong></div><div class="card-body"><div class="row g-2">
<div class="col-md-2"><select id="direction" class="form-select"><option value="IN">Cash In</option><option value="OUT">Cash Out</option></select></div><div class="col-md-3"><input id="manualAmount" class="form-control" inputmode="decimal" placeholder="Amount"></div><div class="col-md-5"><input id="reason" class="form-control" placeholder="Reason"></div><div class="col-md-2"><button id="manual" class="btn btn-outline-primary w-100">Post</button></div>
</div></div></div>
<div class="card shadow-sm mb-3"><div class="card-header"><strong>Current Session</strong></div><div class="card-body"><div class="row"><div class="col-md-3">Opening<br><strong id="sOpening">0.00</strong></div><div class="col-md-3">Cash In<br><strong id="sIn">0.00</strong></div><div class="col-md-3">Cash Out<br><strong id="sOut">0.00</strong></div><div class="col-md-3">Expected<br><strong id="sExpected">0.00</strong></div></div></div></div>
<div class="card shadow-sm"><div class="card-body"><div class="row g-2"><div class="col-md-3"><input id="counted" class="form-control" value="0.00" placeholder="Counted cash"></div><div class="col-md-2"><button id="close" class="btn btn-danger w-100">Close Session</button></div></div></div></div>
<script>
$(function(){const b=<?=json_encode(url('/'),JSON_THROW_ON_ERROR)?>;
function refresh(){let c=$('#counter').val();$.getJSON(b+'counter/status',{counter_id:c}).done(r=>$('#status').text(r.data?'Session open since '+r.data.opened_at:'No open session.'));$.getJSON(b+'counter/summary',{counter_id:c}).done(r=>{let d=r.data||{};$('#sOpening').text(Number(d.opening_cash||0).toFixed(2));$('#sIn').text(Number(d.cash_in||0).toFixed(2));$('#sOut').text(Number(d.cash_out||0).toFixed(2));$('#sExpected').text(Number(d.expected||0).toFixed(2));});}
$('#counter').change(refresh);
$('#open').click(()=>$.post(b+'counter/open',{counter_id:$('#counter').val(),opening_cash:$('#opening').val()}).done(r=>{alert(r.message);refresh()}).fail(x=>alert(x.responseJSON?.message||'Open failed')));
$('#manual').click(()=>$.post(b+'counter/manual',{counter_id:$('#counter').val(),date:'<?=e(date('Y-m-d'))?>',direction:$('#direction').val(),amount:$('#manualAmount').val(),reason:$('#reason').val()}).done(r=>{alert(r.message);refresh()}).fail(x=>alert(x.responseJSON?.message||'Cash entry failed')));
$('#close').click(()=>$.post(b+'counter/close',{counter_id:$('#counter').val(),counted_cash:$('#counted').val()}).done(r=>{alert(r.message+(r.data?' Expected '+r.data.expected+', variance '+r.data.variance:''));refresh()}).fail(x=>alert(x.responseJSON?.message||'Close failed')));
refresh();});
</script>