$(function(){
    const csrfToken=$('meta[name="csrf-token"]').attr('content');
    if(csrfToken){$.ajaxSetup({headers:{'X-CSRF-TOKEN':csrfToken}});}
    window.Lpg=window.Lpg||{};
    window.Lpg.formatNumber=function(value,decimals=2){
        const number=Number(value);
        if(!Number.isFinite(number)){return '';}
        return number.toLocaleString(undefined,{minimumFractionDigits:decimals,maximumFractionDigits:decimals});
    };
});
