$(function(){
    const csrfToken=$('meta[name="csrf-token"]').attr('content');
    if(csrfToken){$.ajaxSetup({headers:{'X-CSRF-TOKEN':csrfToken}});}

    window.Lpg=window.Lpg||{};
    window.Lpg.formatNumber=function(value,decimals=2){
        const number=Number(value);
        if(!Number.isFinite(number)){return '';}
        return number.toLocaleString(undefined,{minimumFractionDigits:decimals,maximumFractionDigits:decimals});
    };


    function activateTab(tab){
        const target=tab.getAttribute('data-bs-target')||tab.getAttribute('href');
        if(!target||!target.startsWith('#')){return;}

        if(window.bootstrap && window.bootstrap.Tab){
            window.bootstrap.Tab.getOrCreateInstance(tab).show();
            return;
        }

        const container=tab.closest('[role="tablist"]');
        const scope=container ? container.parentElement : document;
        const tabs=scope.querySelectorAll('[data-bs-toggle="tab"]');
        tabs.forEach(function(item){
            const itemTarget=item.getAttribute('data-bs-target')||item.getAttribute('href');
            const active=item===tab;
            item.classList.toggle('active',active);
            item.setAttribute('aria-selected',active?'true':'false');
        });

        scope.querySelectorAll('.tab-pane').forEach(function(pane){
            const active='#'+pane.id===target;
            pane.classList.toggle('show',active);
            pane.classList.toggle('active',active);
        });
    }

    $(document).on('click','[data-bs-toggle="tab"]',function(event){
        event.preventDefault();
        activateTab(this);
    });

    const storageKey='pak-gas-sidebar-collapsed';
    const $body=$('body');
    const $sidebar=$('#appSidebar');
    const $groups=$sidebar.find('.nav-group');

    function setCollapsed(collapsed){
        $body.toggleClass('sidebar-collapsed',collapsed);
        try{localStorage.setItem(storageKey,collapsed?'1':'0');}catch(e){}
        const $button=$('#sidebarCollapse');
        if($button.length){
            $button.attr('aria-label',collapsed?'Expand navigation':'Collapse navigation');
            $button.attr('title',collapsed?'Expand navigation':'Collapse navigation');
        }
        if(collapsed){$groups.removeClass('is-open');}
    }

    try{
        if(window.innerWidth >= 992 && localStorage.getItem(storageKey)==='1'){
            setCollapsed(true);
        }
    }catch(e){}

    $('#sidebarCollapse').on('click',function(){
        setCollapsed(!$body.hasClass('sidebar-collapsed'));
    });

    $groups.each(function(){
        const $group=$(this);
        const $toggle=$group.find('.nav-group-toggle').first();
        const $children=$group.find('.nav-children').first();

        if($children.hasClass('show')){
            $group.addClass('is-open');
        }

        $toggle.on('click',function(){
            if($body.hasClass('sidebar-collapsed')){
                setCollapsed(false);
            }

            const shouldOpen=!$children.hasClass('show');

            $groups.not($group).each(function(){
                const $other=$(this);
                $other.removeClass('is-open').find('.nav-children').first().removeClass('show');
                $other.find('.nav-group-toggle').first().attr('aria-expanded','false');
            });

            $group.toggleClass('is-open',shouldOpen);
            $children.toggleClass('show',shouldOpen);
            $toggle.attr('aria-expanded',shouldOpen?'true':'false');
        });
    });

    $sidebar.on('click','.nav-child',function(){
        if(window.innerWidth < 992){
            const instance=bootstrap.Offcanvas.getInstance($sidebar[0]);
            if(instance){instance.hide();}
        }
    });

    $sidebar.on('hidden.bs.offcanvas',function(){
        $body.removeClass('sidebar-collapsed');
    });

    $(window).on('resize',function(){
        if(window.innerWidth < 992){
            $body.removeClass('sidebar-collapsed');
        }
    });
});
