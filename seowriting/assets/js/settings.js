(function ($) {
    const action = 'seowriting_settings';
    var conection_button = '#seowriting_conection_button';

    var tabs = $( '[data-seowriting-tab]' );
    if(tabs.length){
        tabs.on( 'click', function ( _event ) {
            seowriting_display_tab($( this ).data('seowriting-tab'));
            document.cookie = 'seowriting_tab=' + $( this ).data('seowriting-tab');
            $(this).blur();
        } );
        var tab = seowriting_tab_cookie(tabs);
        if ( tab.length < 1 ) {
            tab = tabs.first();
        }
        seowriting_display_tab(tab.data( 'seowriting-tab' ));
    }

    function seowriting_tab_cookie( $elems ) {
        var re = new RegExp(
            '(?:^|.*;)\\s*seowriting_tab\\s*=\\s*([^;]*).*$|^.*$',
            'ms'
        );
        var name = document.cookie.replace( re, "$1" );
        return $elems.filter( '[data-seowriting-tab="' + name + '"]:first' );
    }

    function seowriting_display_tab(name) {
        var tabs = $( '[data-seowriting-tab]' );
        var layouts = $( '[data-seowriting-layout]' );
        tabs.removeClass( 'nav-tab-active' );
        tabs.filter( '[data-seowriting-tab="' + name + '"]' ).addClass( 'nav-tab-active' );
        layouts.hide();
        layouts.filter( '[data-seowriting-layout="' + name + '"]' ).show();
    }

    function disableBtn(s, v) {
        if (v) {
            $(s).addClass( 'disabled' );
        }else{
            $(s).removeClass( 'disabled' );
        }
    }

    function hideMsg() {
        $('.seowriting_msg').hide();
    }

    function hideMsgWithDelay() {
        setTimeout(hideMsg, 5000);
    }

    function showMsg(msg) {
        $('.seowriting_msg').html(msg).show();
        hideMsgWithDelay();
    }

    function aj(command, success) {
        var data = {
            'action': action,
            'aj': command,
            'nonce': ajax_var.nonce
        };

        $.post(ajaxurl, data, success, 'json');
    }

    function connectOrDisconnect(event) {
        event.preventDefault();
        disableBtn(conection_button, true);
        hideMsg();
        var type = $(conection_button).attr('href') == '#connect' ? 'connect' : 'disconnect';
        aj(type, function(d){
            if ('success' in d) {
                if (d.success) {
                    if ('auth_url' in d) {
                        var a = document.createElement('a');
                        a.href = d.auth_url;
                        a.target = '_blank';
                        a.dispatchEvent(new MouseEvent('click', {
                            view: window,
                            bubbles: true,
                            cancelable: true
                        }));
                        //window.location.assign(d.auth_url);
                    }
                    else {
                        showMsg(d.msg);
                        if($('.conection-message').length){
                            $('.conection-message').html(d.body)
                        }
                    }
                    if(type === 'disconnect'){
                        disableBtn(conection_button, false);
                        $('.conection-blok').removeClass('connected');
                        $(conection_button).html('Connect').attr('href','#connect');
                    }
                }
                else {
                    if ('error' in d) {
                        showMsg(d.error);
                    }
                    disableBtn(conection_button, false);
                }
            }
            else {
                disableBtn(conection_button, false);
            }
        });
    }

    $(document).ready(function () {
        if($(conection_button).length){
            $(conection_button).click(connectOrDisconnect);
        }
        if ($('.seowriting_msg').length) {
            hideMsgWithDelay();
        }
    });
})(jQuery);
