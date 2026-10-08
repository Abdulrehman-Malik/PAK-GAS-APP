<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h1 class="h3 mb-1">Point of Sale</h1><p class="text-secondary mb-0">Issue, sell and receive cylinders in one atomic transaction.</p></div>
</div>
<div id="posError" class="alert alert-danger d-none"></div>
<div class="card shadow-sm mb-3"><div class="card-body"><div class="row g-2">
<div class="col-md-3"><label class="form-label">Transaction Type</label><select id="transactionType" class="form-select"></select></div>
<div class="col-md-4"><label class="form-label">Customer</label><input id="customerSearch" class="form-control" placeholder="Search customer code, name or phone"><select id="customer" class="form-select mt-1"></select></div>
<div class="col-md-2"><label class="form-label">Date</label><input id="txnDate" type="date" class="form-control" value="<?=e($today)?>"></div>
<div class="col-md-2"><label class="form-label">Payment</label><select id="method" class="form-select"><option value="CASH">Cash</option><option value="ONLINE">Online</option><option value="CHEQUE">Cheque</option></select></div>
<div class="col-md-1"><label class="form-label">Received</label><input id="received" class="form-control" inputmode="decimal" value="0.00"></div>
</div></div></div>

<div id="shopSection" class="card shadow-sm mb-3"><div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><strong>Shop Cylinders</strong><select id="group" class="form-select form-select-sm" style="max-width:280px"><option value="0">All groups</option></select></div><div class="card-body"><div id="cylinders" class="row g-2"></div></div></div>

<div id="returnSection" class="card shadow-sm mb-3 d-none"><div class="card-header"><strong>Receive Back — Customer Cylinders</strong></div><div class="card-body"><div id="issuedCylinders" class="row g-2"></div><div class="small text-secondary">Select issued cylinders and enter the gas returned. Credit = returned gas × rate.</div></div></div>

<div class="card shadow-sm"><div class="card-body"><div class="row align-items-center g-2"><div class="col"><strong>Gas total: <span id="gasTotal">0.000</span> kg</strong><span class="mx-2">|</span><strong>Net: <span id="total">0.00</span></strong></div><div class="col-auto"><button id="save" class="btn btn-primary">Save Sale</button></div></div></div></div>

<script>
$(function(){
 const base=<?=json_encode(url('/'),JSON_THROW_ON_ERROR)?>;
 const $type=$('#transactionType'),$group=$('#group'),$shop=$('#shopSection'),$return=$('#returnSection'),$cyl=$('#cylinders'),$issued=$('#issuedCylinders'),$err=$('#posError');
 function esc(v){return $('<div>').text(v??'').html();}
 function error(m){$err.text(m||'Unexpected error.').removeClass('d-none');}
 function clearError(){$err.addClass('d-none').text('');}
 function label(t){return String(t||'').replaceAll('_',' ').toLowerCase().replace(/\b\w/g,c=>c.toUpperCase());}
 function recalc(){
  let gas=0,total=0;
  $cyl.find('.shop-card').each(function(){
   const x=$(this);if(!x.find('.pick').is(':checked'))return;
   const g=Number(x.find('.gas').val()||0),r=Number(x.find('.rate').val()||0),cp=Number(x.find('.cp').val()||0);
   gas+=g;total+=x.find('.sell').is(':checked')?(g*r+cp):(g*r);
  });
  $issued.find('.return-card').each(function(){const x=$(this);if(!x.find('.return-pick').is(':checked'))return;const g=Number(x.find('.return-gas').val()||0),r=Number(x.find('.return-rate').val()||0);gas-=g;total-=g*r;});
  $('#gasTotal').text(gas.toFixed(3));$('#total').text(total.toFixed(2));
 }
 function loadConfig(){
  return $.getJSON(base+'pos/config').done(r=>{if(!r.ok){error(r.message);return;}const ts=r.data.transaction_types||[];$type.empty();ts.forEach(t=>$type.append(new Option(label(t),t)));if(!ts.length){error('No POS transaction types are configured.');return;}$type.val(ts.includes(r.data.default_transaction_type)?r.data.default_transaction_type:ts[0]);}).fail(x=>error(x.responseJSON?.message||'Unable to load POS configuration.'));
 }
 function loadShop(){
  const t=$type.val(),g=$group.val()||0;
  if(t==='CYLINDER_RETURN'){$shop.addClass('d-none');return;}
  $shop.removeClass('d-none');
  $.getJSON(base+'pos/cylinders',{group_id:g,transaction_type:t,txn_date:$('#txnDate').val()}).done(r=>{
   if(!r.ok){error(r.message);return;}let groups={};$cyl.empty();
   (r.data||[]).forEach(v=>{groups[v.group_id]=v.group_code+' - '+v.group_name+' ('+v.capacity_kg+' kg)';$cyl.append(
   '<div class="col-12 col-sm-6 col-xl-3"><div class="shop-card border rounded p-3 h-100" data-id="'+v.id+'"><div class="fw-bold fs-5">'+esc(v.code)+'</div><div class="small text-secondary">'+esc(v.group_name)+' · '+esc(v.gas_kg)+'/'+esc(v.capacity_kg)+' kg</div><div class="form-check mt-2"><input class="form-check-input pick" type="checkbox"><label class="form-check-label">Select</label></div><input class="form-control form-control-sm gas mt-2" value="'+esc(v.gas_kg)+'" data-max="'+esc(v.gas_kg)+'" inputmode="decimal" placeholder="Gas kg"><div class="form-check mt-2 sell-wrap"><input class="form-check-input sell" type="checkbox"><label class="form-check-label">Sell cylinder</label></div><input class="form-control form-control-sm rate mt-2" value="'+esc(v.gas_rate)+'" inputmode="decimal" placeholder="Gas rate"><input class="form-control form-control-sm cp mt-2" value="'+esc(v.cylinder_price)+'" inputmode="decimal" placeholder="Cylinder price"></div></div>');});
   const keep=g;$group.empty().append(new Option('All groups','0'));Object.entries(groups).forEach(([id,n])=>$group.append(new Option(n,id)));if(groups[keep])$group.val(keep);
   $('.sell-wrap').toggle(t!=='EMPTY_CYLINDER_SALE');$cyl.find('.rate').prop('disabled',t==='EMPTY_CYLINDER_SALE');$cyl.find('.gas').prop('disabled',t==='EMPTY_CYLINDER_SALE');$cyl.find('.cp').prop('disabled',false);
   if(t==='EMPTY_CYLINDER_SALE')$cyl.find('.gas').val('0.000');
   recalc();
  }).fail(x=>error(x.responseJSON?.message||'Unable to load cylinders.'));
 }
 function loadIssued(){
  const cid=$('#customer').val(),t=$type.val();
  const show=t==='CYLINDER_RETURN'||(t==='GAS_SALE'&&cid);
  $return.toggleClass('d-none',!show);if(!show){$issued.empty();return;}
  if(!cid){$issued.html('<div class="alert alert-warning">Select a customer to receive cylinders back.</div>');return;}
  $.getJSON(base+'pos/issued/'+cid).done(r=>{if(!r.ok){error(r.message);return;}$issued.empty();
   (r.data||[]).forEach(v=>$issued.append('<div class="col-12 col-sm-6 col-xl-3"><div class="return-card border rounded p-3 h-100" data-id="'+v.id+'"><div class="fw-bold fs-5">'+esc(v.code)+'</div><div class="small text-secondary">'+esc(v.group_name)+' · issue gas '+esc(v.gas_kg)+' kg</div><div class="form-check mt-2"><input class="form-check-input return-pick" type="checkbox"><label class="form-check-label">Return</label></div><input class="form-control form-control-sm return-gas mt-2" value="0.000" data-max="'+esc(v.capacity_kg)+'" inputmode="decimal" placeholder="Returned gas"><input class="form-control form-control-sm return-rate mt-2" value="'+esc(v.issue_rate)+'" inputmode="decimal" placeholder="Rate"></div></div>'));recalc();
  }).fail(x=>error(x.responseJSON?.message||'Unable to load issued cylinders.'));
 }
 $type.on('change',()=>{clearError();loadShop();loadIssued();});
 $group.on('change',loadShop);$('#txnDate').on('change',loadShop);
 $('#customerSearch').on('input',function(){const q=this.value.trim();if(q.length<2){$('#customer').empty();loadIssued();return;}$.getJSON(base+'pos/customers',{q}).done(r=>{const s=$('#customer').empty();(r.data||[]).forEach(v=>s.append(new Option(v.code+' - '+v.name,v.id)));loadIssued();}).fail(x=>error(x.responseJSON?.message||'Customer search failed'));});
 $('#customer').on('change',loadIssued);
 $cyl.on('input change','.pick,.sell,.gas,.rate,.cp',function(){recalc();});
 $issued.on('input change','.return-pick,.return-gas,.return-rate',function(){const x=$(this).closest('.return-card');if($(this).hasClass('return-gas')){const m=Number($(this).data('max')),v=Number($(this).val());if(v<0)$(this).val('0.000');if(Number.isFinite(m)&&v>m)$(this).val(m.toFixed(3));}recalc();});
 $('#save').on('click',function(){
  clearError();const t=$type.val(),cid=$('#customer').val(),lines=[];
  if(!cid){error('Customer is required.');return;}
  $cyl.find('.shop-card').each(function(){const x=$(this);if(!x.find('.pick').is(':checked'))return;lines.push({cylinder_id:Number(x.data('id')),type:t==='EMPTY_CYLINDER_SALE'?'SELL_EMPTY':(x.find('.sell').is(':checked')?'SELL_FILLED':'ISSUE'),gas_kg:t==='EMPTY_CYLINDER_SALE'?'0.000':String(x.find('.gas').val()||0),rate:String(x.find('.rate').val()||0),cylinder_price:String(x.find('.cp').val()||0)});});
  $issued.find('.return-card').each(function(){const x=$(this);if(!x.find('.return-pick').is(':checked'))return;lines.push({cylinder_id:Number(x.data('id')),type:'RETURN',gas_kg:String(x.find('.return-gas').val()||0),rate:String(x.find('.return-rate').val()||0),cylinder_price:'0.00'});});
  if(!lines.length){error('Select at least one cylinder.');return;}
  const sold=lines.filter(l=>l.type==='SELL_EMPTY'||l.type==='SELL_FILLED');if(sold.length&&!window.confirm(sold.length+' cylinder(s) will leave company stock permanently. Continue?'))return;
  const btn=$(this).prop('disabled',true);$.post(base+'pos',{txn_date:$('#txnDate').val(),transaction_type:t,customer_id:cid,counter_id:<?=json_encode((int)($user['default_counter_id']??0))?>,method:$('#method').val(),received_amount:$('#received').val(),lines_json:JSON.stringify(lines)}).done(r=>{if(!r.ok){error(r.message);return;}alert(r.message+' '+r.data.doc_no);loadShop();loadIssued();$('#received').val('0.00');}).fail(x=>error(x.responseJSON?.message||'Sale failed')).always(()=>btn.prop('disabled',false));
 });
 loadConfig().done(()=>{loadShop();loadIssued();});
});
</script>